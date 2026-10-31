<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Twig;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(CollaborateExtension::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Topics::class)]
#[UsesClass(Grant::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
final class CollaborateExtensionTest extends ExtensionTestCase
{
    public function testRegistersTheLayoutFunctions(): void
    {
        $functions = $this->extension()->getFunctions();
        self::assertSame(
            ['collaborate_grant', 'collaborate_enabled', 'collaborate_layer', 'collaborate_presence'],
            array_map(static fn($function): string => $function->getName(), $functions),
        );
    }

    public function testIssuesAnAccountBoundGrantForTheMainRequest(): void
    {
        $user = new TestUser(7, 'alice', ['ROLE_ADMIN', 'ROLE_USER']);
        $grant = $this->extension($user)->grant();
        self::assertNotNull($grant);
        self::assertStringStartsWith('urn:collaborate:cursors:', $this->pages->topic(
            $grant,
            7,
            time(),
            ['ROLE_USER', 'ROLE_ADMIN'],
        ));
    }

    public function testChecksTheConfiguredRole(): void
    {
        $granted = [];
        $extension = $this->extension(new TestUser(7), role: 'ROLE_EDITOR', granted: $granted);
        self::assertNull($extension->grant());
        self::assertSame(['ROLE_EDITOR'], $granted);
    }

    public function testDoesNotIssueCredentialsWithoutAnAuthorizedCollaboratorAndMainRequest(): void
    {
        foreach ([false, true] as $hasRequest) {
            foreach ([null, new TestUser(), new InMemoryUser('alice', null), new TestUser(7)] as $user) {
                if ($hasRequest && $user instanceof TestUser && null !== $user->id) {
                    continue;
                }
                $extension = $this->extension($user, hasRequest: $hasRequest);
                self::assertNull($extension->grant());
                self::assertSame('', $extension->layer($this->twig()));
                self::assertSame('', $extension->presence($this->twig()));
            }
        }
        $refused = $this->extension(new TestUser(7), isGranted: false);
        self::assertNull($refused->grant());
        self::assertSame('', $refused->layer($this->twig()));
        self::assertSame('', $refused->presence($this->twig()));
    }

    public function testTheLayoutExposesTheSharedDraftsFlag(): void
    {
        self::assertFalse($this->extension()->enabled());
        self::assertTrue($this->extension(drafts: true)->enabled());
    }

    public function testRendersTheLayerWithItsConfigurationInMilliseconds(): void
    {
        $extension = $this->extension(new TestUser(7), layer: [
            'cursors' => true,
            'updates' => true,
            'throttle' => 50,
            'heartbeat' => 6,
            'expiry' => 20,
            'maxAvatars' => 3,
            'renew' => 120,
            'colors' => ['red', 'green'],
        ]);
        $layer = $this->element($extension->layer($this->twig()));
        self::assertTrue($layer->hasAttribute('data-collaborate-layer'));
        self::assertSame('true', $layer->getAttribute('aria-hidden'));
        self::assertStringStartsWith('7.', $layer->getAttribute('data-grant'));
        self::assertSame('token:collaborate-hub', $layer->getAttribute('data-csrf'));
        self::assertSame('/admin/collaborate_session', $layer->getAttribute('data-session-url'));
        self::assertSame('/admin/collaborate_cursor_events', $layer->getAttribute('data-event-url'));
        self::assertSame('true', $layer->getAttribute('data-updates'));
        self::assertSame(
            [
                'throttle' => 50,
                'heartbeat' => 6000,
                'expiry' => 20_000,
                'maxAvatars' => 3,
                'renew' => 120_000,
                'colors' => ['red', 'green'],
            ],
            json_decode($layer->getAttribute('data-config'), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheLayerDefaultsMatchTheBundleDefaultsAndLeaveOutWhatIsOff(): void
    {
        $layer = $this->element($this->extension(new TestUser(7))->layer($this->twig()));
        self::assertTrue($layer->hasAttribute('data-event-url'));
        self::assertFalse($layer->hasAttribute('data-updates'));
        self::assertSame(
            '{"throttle":100,"heartbeat":5000,"expiry":15000,"maxAvatars":5,"renew":240000,"colors":["blue"]}',
            $layer->getAttribute('data-config'),
        );

        $layer = $this->element($this->extension(new TestUser(7), layer: [
            'cursors' => false,
            'colors' => CollaborateBundle::COLORS,
        ])->layer($this->twig()));
        self::assertFalse($layer->hasAttribute('data-event-url'));
        self::assertTrue($layer->hasAttribute('data-session-url'));
        /** @var array{colors: list<string>} $config */
        $config = json_decode($layer->getAttribute('data-config'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(CollaborateBundle::COLORS, $config['colors']);
    }

    public function testRendersThePresenceGroup(): void
    {
        $presence = $this->element($this->extension(new TestUser(7))->presence($this->twig()));
        self::assertSame('collaborate-presence', $presence->getAttribute('class'));
        self::assertTrue($presence->hasAttribute('data-collaborate-presence'));
        self::assertSame('group', $presence->getAttribute('role'));
        self::assertSame('Also on this page', $presence->getAttribute('aria-label'));
        self::assertTrue($presence->hasAttribute('hidden'));
    }

    public function testTheFunctionsRenderFromATemplate(): void
    {
        $twig = $this->twig($this->extension(new TestUser(7)));
        $html = $twig->createTemplate('{{ collaborate_presence() }}{{ collaborate_layer() }}')->render();
        self::assertStringStartsWith('<div class="collaborate-presence"', $html);
        self::assertStringContainsString('<div data-collaborate-layer', $html);
    }
}
