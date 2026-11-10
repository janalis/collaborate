<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\HubSessions;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(HubSessions::class)]
#[CoversClass(HubSession::class)]
#[UsesClass(Admission::class)]
#[UsesClass(CursorIdentity::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Topics::class)]
#[UsesClass(NotificationAccess::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
final class HubSessionsTest extends TestCase
{
    private TestUser $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new TestUser(15, 'alice', ['ROLE_ADMIN']);
    }

    public function testAPageThatAsksForItsNotificationsHearsThemBesideItsRoom(): void
    {
        $session = $this->sessions(true)->open(new Admission($this->user, 15, 'urn:room', [
            'notifications' => true,
            'updates' => true,
        ]));

        self::assertSame(15, $session->userId);
        self::assertSame(['urn:room', 'urn:collaborate:notifications:15', 'urn:collaborate:updates'], $session->topics);
        self::assertSame([], $session->resend);
        self::assertSame([], $session->denied);
    }

    public function testABareGrantHearsTheNotificationsAloneAndNoSavedViews(): void
    {
        $session = $this->sessions(true)->open(new Admission($this->user, 15, null, [
            'notifications' => true,
            'updates' => true,
        ]));

        self::assertSame(['urn:collaborate:notifications:15'], $session->topics);
    }

    public function testABareGrantWithoutNotificationsIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('A bare page grant subscribes to the notifications alone.');
        $this->sessions(false)->open(new Admission($this->user, 15, null, ['notifications' => true]));
    }

    public function testAPageInACursorRoomNamesItsRoomAndWhoItIsThereWhenCursorsAreOn(): void
    {
        $admission = new Admission(new TestUser(15, 'alice', ['ROLE_ADMIN'], 'Alice'), 15, 'urn:room', []);
        $session = $this->sessions(true, cursors: true, colors: ['red', 'green', 'blue'])->open($admission);

        self::assertSame('urn:room', $session->cursorRoom);
        self::assertNotNull($session->cursor);
        self::assertSame(15, $session->cursor->userId);
        self::assertSame('Alice', $session->cursor->label);
        // The user's colour is their id modulo the colours.
        self::assertSame('red', $session->cursor->color);
        self::assertNull($session->cursor->avatar);
        self::assertSame(
            'blue',
            $this
                ->sessions(true, cursors: true, colors: ['red', 'blue'])
                ->open(new Admission($this->user, 15, 'urn:room', []))
                ->cursor
                ?->color,
        );
    }

    public function testNoCursorIdentityWithoutARoomWithCursorsOffOrWithoutAProvider(): void
    {
        foreach ([
            [$this->sessions(true, cursors: true), new Admission($this->user, 15, null, ['notifications' => true])],
            [$this->sessions(true, cursors: false), new Admission($this->user, 15, 'urn:room', [])],
            [$this->sessions(true, cursors: true, provider: false), new Admission($this->user, 15, 'urn:room', [])],
        ] as [$sessions, $admission]) {
            $session = $sessions->open($admission);
            self::assertNull($session->cursorRoom);
            self::assertNull($session->cursor);
        }
    }

    public function testByDefaultAPageNamesNoCursorIdentityAndOneColourIsBlue(): void
    {
        $collaborators = new SecurityCollaboratorProvider();
        $drafts = new DraftAccess('secret', true, new Topics(), $collaborators, new RoleDraftAccessChecker());
        $admission = new Admission($this->user, 15, 'urn:room', []);

        self::assertNull(new HubSessions($drafts, new Topics(), collaborators: $collaborators)->open(
            $admission,
        )->cursor);
        self::assertSame(
            'blue',
            new HubSessions($drafts, new Topics(), collaborators: $collaborators, cursors: true)
                ->open($admission)
                ->cursor
                ?->color,
        );
    }

    /** @param non-empty-list<string> $colors */
    private function sessions(
        bool $notifications,
        bool $cursors = false,
        array $colors = ['blue'],
        bool $provider = true,
    ): HubSessions {
        $security = $this->createStub(CurrentUserInterface::class);
        $security->method('getUser')->willReturn($this->user);
        $security->method('isGranted')->willReturn(true);
        $collaborators = new SecurityCollaboratorProvider();

        return new HubSessions(
            new DraftAccess('secret', true, new Topics(), $collaborators, new RoleDraftAccessChecker()),
            new Topics(),
            true,
            8,
            new NotificationAccess($security, $collaborators, $notifications),
            $provider ? $collaborators : null,
            $cursors,
            $colors,
        );
    }
}
