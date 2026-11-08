<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\WebSocket;

use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Hub\Transport\Subscription;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(WebSocketTransport::class)]
#[UsesClass(Keys::class)]
#[UsesClass(CursorIdentity::class)]
#[UsesClass(RequestSigner::class)]
#[UsesClass(SubscriberToken::class)]
#[UsesClass(TokenClaims::class)]
#[UsesClass(Origin::class)]
#[UsesClass(Notice::class)]
#[UsesClass(HubSession::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(PublishFailedException::class)]
final class WebSocketTransportTest extends TestCase
{
    private const string SECRET = 'a-secret-of-thirty-two-bytes-ok!';
    private const int NOW = 1_800_000_000;

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $requests = [];

    public function testPublishesTheNoticesOfARequestInOneSignedRequestToThePrivateSide(): void
    {
        $transport = $this->transport([new MockResponse('', ['http_code' => 204])]);
        $transport->publish([new Notice('urn:c:a', '{"channel":"x/y"}'), new Notice(['urn:c:b', 'urn:c:c'], '{}')]);

        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('http://127.0.0.1:8091/publish', $url);
        $body = '{"notices":[{"topics":["urn:c:a"],"data":"{\"channel\":\"x/y\"}"},{"topics":["urn:c:b","urn:c:c"],"data":"{}"}]}';
        self::assertSame($body, $options['body']);
        self::assertContains('Content-Type: application/json', self::headers($options));
        self::assertTrue(self::signer()->verify(self::signature($options), 'POST', '/publish', $body, self::NOW));
        self::assertSame(1.0, $options['timeout']);
        self::assertSame(1.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertArrayNotHasKey('bindto', $options);
    }

    public function testPublishesThroughAUnixSocketWhenOneIsConfigured(): void
    {
        $transport = $this->transport(
            [new MockResponse('', ['http_code' => 204])],
            publishUrl: 'http://localhost/',
            socket: '/run/c/publish.sock',
        );
        $transport->publish([new Notice('urn:c:a', '{}')]);

        self::assertSame('http://localhost/publish', $this->requests[0][1]);
        self::assertSame('/run/c/publish.sock', $this->requests[0][2]['bindto']);
    }

    public function testSendsAHundredNoticesARequestAndNamesTheFirstOfABatchThatFailed(): void
    {
        $transport = $this->transport([
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $notices = array_map(static fn(int $index): Notice => new Notice('urn:c:' . $index, '{}'), range(0, 249));

        try {
            $transport->publish($notices);
            self::fail('The second batch failed.');
        } catch (PublishFailedException $error) {
            self::assertSame(100, $error->index);
            self::assertSame('The hub answered 503 to a publish.', $error->getPrevious()?->getMessage());
        }
        self::assertCount(2, $this->requests);
        $body = Arrays::json((string) $this->requests[0][2]['body']);
        self::assertIsArray($body['notices']);
        self::assertCount(100, $body['notices']);
    }

    public function testATransportFailureIsAPublishFailureOfTheFirstNoticeOfItsBatch(): void
    {
        $failure = new TransportException('refused');
        $transport = $this->transport([new MockResponse('', ['http_code' => 204]), static fn() => throw $failure]);
        $notices = array_map(static fn(int $index): Notice => new Notice('urn:c:' . $index, '{}'), range(0, 100));

        try {
            $transport->publish($notices);
            self::fail('The hub refused the connection.');
        } catch (PublishFailedException $error) {
            self::assertSame(100, $error->index);
            self::assertSame($failure, $error->getPrevious());
        }
    }

    public function testSubscribesThePageWithATokenOfItsTopicsForItsOrigin(): void
    {
        $transport = $this->transport([]);
        $request = Request::create('https://admin.example.com/admin/cursors/session', 'POST');
        $subscription = $transport->subscribe(new HubSession(15, ['urn:c:cursors:r', 'urn:c:updates']), $request);

        self::assertSame('websocket', $subscription->transport);
        self::assertFalse($subscription->cursorEvents);
        self::assertSame('/collaborate-hub', $subscription->url);
        $claims = self::tokens()->verify($subscription->token, self::NOW);
        self::assertNotNull($claims);
        self::assertSame(15, $claims->sub);
        self::assertSame('https://admin.example.com', $claims->origin);
        self::assertSame(['urn:c:cursors:r', 'urn:c:updates'], $claims->topics);
        self::assertSame(self::NOW + 300, $claims->exp);
        self::assertSame([], $this->requests);
    }

    public function testAPageInACursorRoomSendsItsCursorEventsOverItsSocketUnlessTheyAreOff(): void
    {
        $request = Request::create('https://admin.example.com/admin/cursors/session', 'POST');
        $identity = new CursorIdentity(15, 'Alice', 'red');
        $session = new HubSession(15, ['urn:c:cursors:r'], cursorRoom: 'urn:c:cursors:r', cursor: $identity);

        $on = $this->transport([])->subscribe($session, $request);
        self::assertTrue($on->cursorEvents);
        self::assertEquals($identity, self::tokens()->verify($on->token, self::NOW)?->cursor);

        $off = $this->transport([], cursorEvents: false)->subscribe($session, $request);
        self::assertFalse($off->cursorEvents);
        self::assertNull(self::tokens()->verify($off->token, self::NOW)?->cursor);

        $roomless = $this->transport([])->subscribe(new HubSession(15, ['urn:c:updates']), $request);
        self::assertFalse($roomless->cursorEvents);

        // On by default.
        $default = new WebSocketTransport(
            new MockHttpClient(),
            self::signer(),
            self::tokens(),
            '/collaborate-hub',
            'http://127.0.0.1:8091',
        );
        self::assertTrue($default->subscribe($session, $request)->cursorEvents);
    }

    public function testAsksThePrivateSideWhetherAPageListensToATopic(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $transport = $this->transport([
            new MockResponse('{"subscriptions":[{"active":true}]}'),
            new MockResponse('{"subscriptions":[]}'),
            new MockResponse('{}'),
        ], logger: $logger);

        self::assertTrue($transport->listening('urn:c:notifications:5'));
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertSame('http://127.0.0.1:8091/subscriptions?topic=urn%3Ac%3Anotifications%3A5', $url);
        self::assertSame('', $options['body']);
        self::assertNotContains('Content-Type: application/json', self::headers($options));
        self::assertTrue(
            self::signer()
                ->verify(
                    self::signature($options),
                    'GET',
                    '/subscriptions?topic=urn%3Ac%3Anotifications%3A5',
                    '',
                    self::NOW,
                ),
        );
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(0, $options['max_redirects']);
        self::assertFalse($transport->listening('urn:c:notifications:5'));
        self::assertFalse($transport->listening('urn:c:notifications:5'));
    }

    public function testAServerThatDoesNotAnswerReadsAsNobodyListeningAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'The hub did not say who listens to a topic.',
                self::callback(static fn(array $context): bool => $context['exception'] instanceof \Throwable),
            );
        $transport = $this->transport([new MockResponse('nope', ['http_code' => 500])], logger: $logger);

        self::assertFalse($transport->listening('urn:c:notifications:5'));
        self::assertFalse($this->transport([new MockResponse('nope', ['http_code' => 500])])->listening(
            'urn:c:notifications:5',
        ));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function publicUrls(): iterable
    {
        yield 'path' => ['/collaborate-hub', true];
        yield 'root' => ['/', true];
        yield 'wss' => ['wss://hub.example.com/ws', true];
        yield 'ws on localhost' => ['ws://localhost:8090', true];
        yield 'ws on 127.0.0.1' => ['ws://127.0.0.1:8090/', true];
        yield 'ws on ::1' => ['ws://[::1]:8090/', true];
        yield 'ws on a .localhost host' => ['ws://app.localhost:8090/', true];
        yield 'ws on LOCALHOST' => ['ws://LOCALHOST:8090/', true];
        yield 'ws on a host' => ['ws://hub.example.com/ws', false];
        yield 'ws on a localhost lookalike' => ['ws://localhost.example.com/', false];
        yield 'https' => ['https://hub.example.com/ws', false];
        yield 'protocol-relative' => ['//hub.example.com/ws', false];
        yield 'relative' => ['collaborate-hub', false];
        yield 'empty' => ['', false];
        yield 'no host' => ['wss:///ws', false];
    }

    #[DataProvider('publicUrls')]
    public function testBrowsersConnectOnTheApplicationsOriginOrOverTlsUnlessTheServerIsLocal(
        string $url,
        bool $valid,
    ): void {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('collaborate.hub.websocket.public_url must be');
        }
        WebSocketTransport::checkPublicUrl($url);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPublishUrls(): iterable
    {
        yield 'path' => ['http://127.0.0.1:8091/hub'];
        yield 'query' => ['http://127.0.0.1:8091/?x=1'];
        yield 'ws' => ['ws://127.0.0.1:8091'];
        yield 'no scheme' => ['127.0.0.1:8091'];
        yield 'no host' => ['http:///'];
        yield 'garbage' => ['http://:80'];
    }

    public function testTheTransportChecksItsPublicUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collaborate.hub.websocket.public_url must be');

        $this->transport([], publicUrl: 'ws://hub.example.com');
    }

    #[DataProvider('invalidPublishUrls')]
    public function testThePrivateSideIsTheRootOfAnHttpServer(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collaborate.hub.websocket.publish_url must be the http(s) root');

        $this->transport([], publishUrl: $url);
    }

    public function testTheRootOfAnHttpsPrivateSideIsAccepted(): void
    {
        $this->transport([new MockResponse('', ['http_code' => 204])], publishUrl: 'https://hub.internal')->publish([
            new Notice('urn:c:a', '{}'),
        ]);

        self::assertSame('https://hub.internal/publish', $this->requests[0][1]);
    }

    /** @param list<MockResponse|\Closure> $responses */
    private function transport(
        array $responses,
        string $publicUrl = '/collaborate-hub',
        string $publishUrl = 'http://127.0.0.1:8091',
        ?string $socket = null,
        ?LoggerInterface $logger = null,
        bool $cursorEvents = true,
    ): WebSocketTransport {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (
            &$responses,
        ): MockResponse {
            $this->requests[] = [$method, $url, $options];
            $response = array_shift($responses);
            if ($response instanceof \Closure) {
                $response();
            }
            self::assertInstanceOf(MockResponse::class, $response);

            return $response;
        });

        return new WebSocketTransport(
            $client,
            self::signer(),
            self::tokens(),
            $publicUrl,
            $publishUrl,
            $socket,
            $logger,
            new MockClock(new \DateTimeImmutable('@' . self::NOW)),
            $cursorEvents,
        );
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private static function headers(array $options): array
    {
        self::assertIsArray($options['headers'] ?? null);

        $headers = [];
        /** @var mixed $header */
        foreach ($options['headers'] as $header) {
            self::assertIsString($header);
            $headers[] = $header;
        }

        return $headers;
    }

    /** @param array<array-key, mixed> $options */
    private static function signature(array $options): string
    {
        foreach (self::headers($options) as $header) {
            if (str_starts_with($header, 'X-Collaborate-Signature: ')) {
                return substr($header, \strlen('X-Collaborate-Signature: '));
            }
        }
        self::fail('The request is not signed.');
    }

    private static function signer(): RequestSigner
    {
        return new RequestSigner(new Keys(self::SECRET));
    }

    private static function tokens(): SubscriberToken
    {
        return new SubscriberToken(new Keys(self::SECRET), '/collaborate-hub', 'urn:c:', 300, 8);
    }
}
