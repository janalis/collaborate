<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\Push\PushEndpoints;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Security\CurrentUserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(PushController::class)]
#[UsesClass(NotificationAccess::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(PushEndpoints::class)]
final class PushControllerTest extends TestCase
{
    private const string P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const string AUTH = 'tBHItJI5svbpez7KI4CCXg';

    public function testWebPushIsOffUnlessTurnedOn(): void
    {
        $controller = new PushController(
            $this->access(),
            $this->createStub(PushSubscriptions::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $this->urls(),
            __FILE__,
        );
        $this->expectException(NotFoundHttpException::class);
        $controller->worker();
    }

    public function testTheWorkerSaysTheSentencesOfTheBundleWithoutATranslator(): void
    {
        self::assertSame(
            [
                'read' => '/read',
                'title' => 'New notification',
                'body' => 'You have a new notification.',
                'icon' => '/i.png',
            ],
            self::settings($this->controller(icon: '/i.png')->worker()->getContent()),
        );
    }

    public function testTheWorkerSaysTheSentencesTranslated(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn(string $id): string => 'T:' . $id);
        $response = $this->controller(translator: $translator)->worker();
        self::assertSame(
            [
                'read' => '/read',
                'title' => 'T:New notification',
                'body' => 'T:You have a new notification.',
                'icon' => null,
            ],
            self::settings($response->getContent()),
        );
        // The script follows what it reads.
        self::assertTrue(str_ends_with((string) $response->getContent(), (string) file_get_contents(__FILE__)));
    }

    public function testNoSubscriptionWhileWebPushIsOffWhateverTheNotifications(): void
    {
        $controller = new PushController(
            $this->access(),
            $this->createStub(PushSubscriptions::class),
            $this->csrf(),
            $this->urls(),
            __FILE__,
            false,
        );
        $this->expectException(NotFoundHttpException::class);
        $controller->subscription($this->request(['endpoint' => 'https://push.test/1']), 'unsubscribe');
    }

    public function testAVisitorSubscribesNoBrowser(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller(access: $this->access(null))->subscription($this->request([
            'endpoint' => 'https://push.test/1',
        ]), 'unsubscribe');
    }

    public function testASubscriptionIsAFewHundredBytes(): void
    {
        $this->expectRefusal('A push subscription is a few hundred bytes.', self::bodyOf((4 * 2048) + 1));
    }

    public function testABodyOfTheLongestSizeIsRead(): void
    {
        // Its endpoint is longer than any, which is what it is refused for.
        $this->expectRefusal(
            'A push subscription names the https endpoint of a known push service.',
            self::bodyOf(4 * 2048),
        );
    }

    public function testAnEndpointOfTheLongestLengthIsOne(): void
    {
        $endpoint = 'https://push.test/' . str_repeat('a', 2048 - 18);
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions->expects($this->once())->method('remove')->with(15, $endpoint);
        $response = $this->controller($subscriptions)->subscription($this->request([
            'endpoint' => $endpoint,
        ]), 'unsubscribe');
        self::assertSame(['subscribed' => false], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->expectRefusal(
            'A push subscription names the https endpoint of a known push service.',
            (string) json_encode(['endpoint' => $endpoint . 'a']),
        );
    }

    public function testSavesTheKeysOfABrowser(): void
    {
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions
            ->expects($this->once())
            ->method('save')
            ->with(15, 'https://push.test/1', self::P256DH, self::AUTH, 'aes128gcm');
        $response = $this->controller($subscriptions)->subscription($this->request([
            'endpoint' => 'https://push.test/1',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ]), 'subscribe');
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['subscribed' => true], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function keys(): iterable
    {
        foreach ([
            'something before' => ['!', ''],
            'something after' => ['', '!'],
            'a line after' => ['', "\n"],
        ] as $name => [
            $before,
            $after,
        ]) {
            yield 'a p256dh with ' . $name => [$before . self::P256DH . $after, self::AUTH];
            yield 'an auth with ' . $name => [self::P256DH, $before . self::AUTH . $after];
        }
    }

    #[DataProvider('keys')]
    public function testRefusesKeysThatAreNoBase64Url(string $p256dh, string $auth): void
    {
        $this->expectRefusal(
            'A push subscription carries its p256dh and auth keys, and a known encoding.',
            (string) json_encode([
                'endpoint' => 'https://push.test/1',
                'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
            ]),
            'subscribe',
        );
    }

    private function expectRefusal(string $message, string $body, string $action = 'unsubscribe'): void
    {
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions->expects($this->never())->method('save');
        $subscriptions->expects($this->never())->method('remove');
        try {
            $this->controller($subscriptions)->subscription($this->request($body), $action);
            self::fail('The subscription is refused.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /** A JSON body of a given number of bytes, an endpoint padded to it. */
    private static function bodyOf(int $bytes): string
    {
        $body = (string) json_encode(['endpoint' => 'https://push.test/']);
        $padded = (string) json_encode([
            'endpoint' => 'https://push.test/' . str_repeat('a', max(0, $bytes - \strlen($body))),
        ]);
        self::assertSame($bytes, \strlen($padded));

        return $padded;
    }

    /** @return array<string, mixed> */
    private static function settings(string|false $script): array
    {
        self::assertIsString($script);
        $match = [];
        self::assertSame(1, preg_match('/^self\.collaborateNotifications = (.*);\n/', $script, $match));
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
        $settings = json_decode($match[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($settings);

        /** @var array<string, mixed> */
        return $settings;
    }

    /** @param array<string, mixed>|string $body */
    private function request(array|string $body): Request
    {
        $request = Request::create('/push', 'POST', content: \is_string($body) ? $body : (string) json_encode($body));
        $request->headers->set('X-CSRF-Token', 'token');

        return $request;
    }

    private function controller(
        ?PushSubscriptions $subscriptions = null,
        ?NotificationAccess $access = null,
        ?string $icon = null,
        ?TranslatorInterface $translator = null,
    ): PushController {
        return new PushController(
            $access ?? $this->access(),
            $subscriptions ?? $this->createStub(PushSubscriptions::class),
            $this->csrf(),
            $this->urls(),
            __FILE__,
            true,
            $icon,
            $translator,
            new PushEndpoints(['push.test']),
        );
    }

    private function access(?InMemoryUser $user = new InMemoryUser('alice', null)): NotificationAccess
    {
        $security = $this->createStub(CurrentUserInterface::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn(true);
        $collaborators = $this->createStub(CollaboratorProviderInterface::class);
        $collaborators->method('id')->willReturn(15);

        return new NotificationAccess($security, $collaborators, true, 'ROLE_USER');
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/read');

        return $urls;
    }
}
