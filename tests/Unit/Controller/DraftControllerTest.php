<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(DraftController::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(Notice::class)]
#[UsesClass(MercureTransport::class)]
#[UsesClass(PublishFailedException::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class DraftControllerTest extends TestCase
{
    private DraftAccess $access;
    private DraftJournal&MockObject $journal;
    private TestUser $user;
    private ?UserInterface $signedIn;
    private bool $granted = true;
    private HubInterface&MockObject $hub;
    private LoggerInterface&MockObject $logger;
    private ?RateLimiterFactory $limiter;
    private string $grant;
    private bool $validCsrf = true;

    protected function setUp(): void
    {
        $this->access = new DraftAccess(
            'secret',
            true,
            new Topics(),
            new SecurityCollaboratorProvider(),
            new RoleDraftAccessChecker(),
        );
        $this->user = new TestUser(7, 'alice', ['ROLE_ADMIN'], 'Alice');
        $this->signedIn = $this->user;
        $this->grant = $this->access->issue(
            [
                'id' => 'record',
                'entity' => TestUser::class,
                'permission' => 'update',
                'new' => false,
                'draft' => null,
                'fields' => [],
            ],
            7,
            time(),
            $this->user->getRoles(),
        );
        $this->journal = $this->createMock(DraftJournal::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->limiter = new RateLimiterFactory([
            'id' => 'draft',
            'policy' => 'token_bucket',
            'limit' => 120,
            'rate' => ['interval' => '1 second', 'amount' => 60],
        ], new InMemoryStorage());
    }

    /** @param array<string, mixed> $body */
    private function handle(string $action, array $body = [], bool $terminate = true, ?string $csrf = 'csrf'): Response
    {
        $publisher = new HubPublisher(new MercureTransport($this->hub), $this->logger);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn(): ?UserInterface => $this->signedIn);
        $security
            ->method('isGranted')
            ->willReturnCallback(fn(mixed $role): bool => $this->granted && 'ROLE_ADMIN' === $role);
        $tokens = $this->createStub(CsrfTokenManagerInterface::class);
        $tokens
            ->method('isTokenValid')
            ->willReturnCallback(
                fn(#[\SensitiveParameter] CsrfToken $token): bool => (
                    $this->validCsrf
                    && 'collaborate-draft' === $token->getId()
                    && 'csrf' === $token->getValue()
                ),
            );
        $controller = new DraftController(
            $this->access,
            $this->journal,
            $publisher,
            $security,
            new SecurityCollaboratorProvider(),
            $tokens,
            'ROLE_ADMIN',
            $this->limiter,
        );
        $request = new Request(content: json_encode($body + ['grant' => $this->grant], \JSON_THROW_ON_ERROR));
        if (null !== $csrf) {
            $request->headers->set('X-CSRF-Token', $csrf);
        }
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);
        $response = $controller->handle($action, $request);
        self::assertTrue($session->isStarted(), 'Security must persist its token before releasing the session lock.');
        if ($terminate) {
            $publisher->publish();
        }
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return $response;
    }

    public function testJoinsAndReplaysOnlyCapabilityFields(): void
    {
        // A grant that names no request time reads every field as unchanged since its form was rendered.
        $this->journal->expects($this->once())->method('join')->with('record', [], 0.0)->willReturn(['revision' => 0]);
        $this->journal
            ->expects($this->exactly(2))
            ->method('replay')
            ->willReturnCallback(static function (
                string $id,
                array $fields,
                int $since,
                float $at,
                ?string $digest,
            ): array {
                self::assertSame(['record', [], 3, 0.0], [$id, $fields, $since, $at]);

                return ['revision' => 3, 'digest' => $digest];
            });
        $this->journal
            ->expects($this->exactly(2))
            ->method('unchanged')
            ->willReturnCallback(static fn(string $id, int $since, string $stamp): bool => 'unchanged' === $stamp);
        self::assertSame(200, $this->handle('join')->getStatusCode());
        self::assertSame(200, $this->handle('replay', ['since' => 3, 'stamp' => 7])->getStatusCode());
        self::assertSame(
            ['revision' => 3, 'digest' => 'known'],
            json_decode(
                (string) $this->handle('replay', [
                    'since' => 3,
                    'digest' => 'known',
                    'stamp' => 'written',
                ])->getContent(),
                true,
                flags: \JSON_THROW_ON_ERROR,
            ),
        );
        // A draft nobody wrote to since the page's stamp answers from its row alone.
        self::assertSame(
            ['unchanged' => true, 'stamp' => 'unchanged'],
            json_decode(
                (string) $this->handle('replay', ['since' => 3, 'stamp' => 'unchanged'])->getContent(),
                true,
                flags: \JSON_THROW_ON_ERROR,
            ),
        );
        $this->hub->expects($this->never())->method('publish');
    }

    public function testJoinReplayAndReconciliationReadTheFieldsAsTheGrantsRequestTimeSawThem(): void
    {
        $this->grant = $this->access->issue(
            [
                'id' => 'record',
                'entity' => TestUser::class,
                'permission' => 'update',
                'new' => false,
                'draft' => null,
                'fields' => [],
                'requestedAt' => 1_700_000_000.25,
            ],
            7,
            time(),
            $this->user->getRoles(),
        );
        $this->journal->expects($this->once())->method('join')->with('record', [], 1_700_000_000.25)->willReturn([]);
        $this->journal
            ->expects($this->once())
            ->method('replay')
            ->with('record', [], 3, 1_700_000_000.25, null)
            ->willReturn([]);
        $this->journal
            ->expects($this->once())
            ->method('reconcile')
            ->with('record', 1, 2, [], false, 1_700_000_000.25, 7, 'Alice')
            ->willReturn([]);
        self::assertSame(200, $this->handle('join')->getStatusCode());
        self::assertSame(200, $this->handle('replay', ['since' => 3])->getStatusCode());
        self::assertSame(
            200,
            $this->handle('reconcile', ['generation' => 1, 'revision' => 2, 'keep' => false])->getStatusCode(),
        );
    }

    public function testAppendNotifiesWithoutPublishingDraftValuesAndToleratesHubFailure(): void
    {
        $this->journal
            ->expects($this->once())
            ->method('append')
            ->with('record', [], self::anything(), 7, 'Alice')
            ->willReturn(['revision' => 1]);
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with(self::callback(
                static fn(Update $update): bool => (
                    $update->isPrivate()
                    && ['urn:collaborate:draft:record'] === $update->getTopics()
                    && '{"channel":"draft","draft":"record","kind":"append"}' === $update->getData()
                ),
            ))
            ->willThrowException(new \RuntimeException('offline'));
        $this->logger->expects($this->once())->method('warning');
        self::assertSame(200, $this->handle('append', ['field' => 'body'])->getStatusCode());
    }

    public function testAnAppendNoticeNamesItsGenerationAndRevisionOnly(): void
    {
        $this->journal
            ->expects($this->once())
            ->method('append')
            ->with('record', [], self::anything(), 7, 'Alice')
            ->willReturn(['revision' => 4, 'value' => 'secret draft text']);
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with(self::callback(
                static fn(Update $update): bool => '{"channel":"draft","draft":"record","kind":"append","generation":2,"revision":4}' === $update->getData(),
            ));
        self::assertSame(
            200,
            $this->handle('append', [
                'field' => 'body',
                'generation' => 2,
                'value' => 'secret draft text',
            ])->getStatusCode(),
        );
    }

    public function testNotificationsWaitForTheResponseToReleaseTheSession(): void
    {
        $this->journal->method('append')->willReturn(['revision' => 1]);
        $this->hub->expects($this->never())->method('publish');
        self::assertSame(200, $this->handle('append', ['field' => 'body'], false)->getStatusCode());
    }

    public function testDiscardUsesRevisionAndGenerationNamesItsEditorAndNotifiesPeers(): void
    {
        $this->journal
            ->expects($this->once())
            ->method('discard')
            ->with('record', 2, 3, [], 7, 'Alice')
            ->willReturn(['generation' => 3]);
        $this->hub->expects($this->once())->method('publish');
        self::assertSame(200, $this->handle('discard', ['generation' => 2, 'revision' => 3])->getStatusCode());
    }

    public function testReconciliationUsesOnlyTheSignedFieldsNamesItsEditorAndNotifiesPeers(): void
    {
        $this->journal
            ->expects($this->once())
            ->method('reconcile')
            ->with('record', 1, 2, [], true, 0.0, 7, 'Alice')
            ->willReturn(['revision' => 2]);
        $this->hub->expects($this->once())->method('publish');
        self::assertSame(
            200,
            $this->handle('reconcile', ['generation' => 1, 'revision' => 2, 'keep' => true])->getStatusCode(),
        );
        $this->expectException(BadRequestHttpException::class);
        $this->handle('reconcile', ['keep' => 'yes']);
    }

    public function testTheFormAnnouncesNoPresenceOfItsOwn(): void
    {
        // The header's avatars, fed by the cursor topic, show who else is on the page.
        $this->hub->expects($this->never())->method('publish');
        $this->expectException(BadRequestHttpException::class);
        $this->handle('presence', ['clientId' => '12345678-1234-1234-1234-123456789abc']);
    }

    public function testInvalidRequestShapesAreRejected(): void
    {
        foreach ([
            ['replay', [], 'Invalid Collaborate since.'],
            ['replay', ['since' => -1], 'Invalid Collaborate since.'],
            ['discard', ['generation' => '2'], 'Invalid Collaborate generation.'],
            ['discard', ['generation' => 2], 'Invalid Collaborate revision.'],
            ['unknown', [], 'Unknown Collaborate action.'],
        ] as [$action, $body, $message]) {
            try {
                $this->handle($action, $body);
                self::fail('Invalid request was accepted.');
            } catch (BadRequestHttpException $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
        // A request may fill the draft request limit exactly.
        $this->journal->method('append')->willReturn(['revision' => 1]);
        $padding = 2_097_152 - \strlen(json_encode(['padding' => '', 'grant' => $this->grant], \JSON_THROW_ON_ERROR));
        \assert($padding > 0);
        self::assertSame(200, $this->handle('append', ['padding' => str_repeat('x', $padding)])->getStatusCode());
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Collaborate request is too large.');
        $this->handle('append', ['padding' => str_repeat('x', $padding + 1)]);
    }

    public function testRateLimitDoesNotTouchTheJournal(): void
    {
        $this->limiter = new RateLimiterFactory([
            'id' => 'tiny',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '1 hour',
        ], new InMemoryStorage());
        $this->journal->expects($this->once())->method('join')->willReturn([]);
        self::assertSame(200, $this->handle('join')->getStatusCode());
        $limited = $this->handle('join');
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('1', $limited->headers->get('Retry-After'));
        self::assertSame('{"error":"Collaborate rate limit exceeded."}', $limited->getContent());
    }

    public function testWithoutALimiterEveryRequestReachesTheJournal(): void
    {
        $this->limiter = null;
        $this->journal->expects($this->exactly(2))->method('join')->willReturn([]);
        self::assertSame(200, $this->handle('join')->getStatusCode());
        self::assertSame(200, $this->handle('join')->getStatusCode());
    }

    public function testRejectsInvalidOrMissingCsrfBeforeReadingTheJournal(): void
    {
        $this->journal->expects($this->never())->method('join');
        foreach ([[false, 'csrf'], [true, null]] as [$valid, $token]) {
            $this->validCsrf = $valid;
            try {
                $this->handle('join', csrf: $token);
                self::fail('A request without a valid CSRF token was accepted.');
            } catch (AccessDeniedException $denied) {
                self::assertSame('Invalid Collaborate CSRF token.', $denied->getMessage());
            }
        }
    }

    /** @return iterable<string, array{?UserInterface, bool}> */
    public static function refusedUsers(): iterable
    {
        yield 'anonymous' => [null, true];
        yield 'without the role' => [new TestUser(7), false];
        yield 'not persisted' => [new TestUser(), true];
    }

    public function testRequiresASignedInCollaboratorWithTheRole(): void
    {
        $this->journal->expects($this->never())->method('join');
        foreach (self::refusedUsers() as $case => [$user, $granted]) {
            $this->signedIn = $user;
            $this->granted = $granted;
            try {
                $this->handle('join');
                self::fail('A request of a user who cannot collaborate was accepted: ' . $case . '.');
            } catch (AccessDeniedException $denied) {
                self::assertSame('Collaborate drafts require the collaboration role.', $denied->getMessage());
            }
        }
    }
}
