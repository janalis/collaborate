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
use Janalis\Collaborate\Twig\NotificationRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Node\Node;

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
            [
                'collaborate_grant',
                'collaborate_enabled',
                'collaborate_layer',
                'collaborate_presence',
                'collaborate_notifications',
                'collaborate_notification_text',
                'collaborate_notification_icon',
                'collaborate_notification_types',
                'collaborate_notification_topics',
                'collaborate_notifications_settings',
                'collaborate_notifications_layout',
            ],
            array_map(static fn($function): string => $function->getName(), $functions),
        );
        // The notifications are the runtime's: made only when a template calls one of them.
        $notifications = \array_slice($functions, 4);
        self::assertSame(
            ['bell', 'text', 'icon', 'types', 'topics', 'settings', 'layout'],
            array_map(static function ($function): string {
                $callable = $function->getCallable();
                self::assertIsArray($callable);
                self::assertCount(2, $callable);
                self::assertSame(NotificationRuntime::class, $callable[0]);

                return $callable[1];
            }, $notifications),
        );
        self::assertTrue($notifications[0]->needsEnvironment());
        self::assertSame(['html'], $notifications[0]->getSafe(new Node()));
        foreach (\array_slice($notifications, 1) as $function) {
            self::assertFalse($function->needsEnvironment());
            self::assertSame([], $function->getSafe(new Node()));
        }
    }

    public function testIssuesAnAccountBoundGrantForTheMainRequest(): void
    {
        $user = new TestUser(7, 'alice', ['ROLE_ADMIN', 'ROLE_USER']);
        $grant = $this->extension($user)->grant();
        self::assertNotNull($grant);
        self::assertStringStartsWith(
            'urn:collaborate:cursors:',
            (string) $this->pages->topic($grant, 7, time(), ['ROLE_USER', 'ROLE_ADMIN']),
        );
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

    public function testTheLayerNamesTheHostOptionsThatDifferFromTheDefaultsWithTheSentencesTranslated(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static fn(string $id, array $parameters, ?string $domain): string => ($domain ?? '') . ':' . $id,
            );
        $extension = $this->extension(
            new TestUser(7),
            host: [
                'regions' => ['listing' => '#list', 'body' => 'body'],
                'keyAttribute' => 'data-key',
                'keyScope' => '#list',
                'anchorAttributes' => CollaborateBundle::ANCHOR_ATTRIBUTES,
                'anchorClasses' => true,
                'caretRegions' => ['modal'],
                'popups' => [
                    'share' => ['where' => 'in the sharing of %name%', 'line' => 'Shared with %names%.'],
                    'metadata' => [],
                    'broken' => 'x',
                ],
                'idle' => 60,
                'markers' => true,
                'edges' => '.pane',
                'topLayer' => true,
                'presence' => 'menu',
                'distinctColors' => true,
                'shareStream' => true,
                'silentAlone' => true,
                'unknown' => true,
            ],
            translator: $translator,
        );
        /** @var array<string, mixed> $config */
        $config = json_decode(
            $this->element($extension->layer($this->twig()))->getAttribute('data-config'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            [
                'throttle' => 100,
                'heartbeat' => 5000,
                'expiry' => 15_000,
                'maxAvatars' => 5,
                'renew' => 240_000,
                'colors' => ['blue'],
                'regions' => ['listing' => '#list', 'body' => 'body'],
                'keyAttribute' => 'data-key',
                'keyScope' => '#list',
                'anchorClasses' => true,
                'caretRegions' => ['modal'],
                'popups' => [
                    'share' => [
                        'where' => 'collaborate:in the sharing of %name%',
                        'line' => 'collaborate:Shared with %names%.',
                    ],
                    'metadata' => ['where' => 'collaborate:in %name%', 'line' => 'collaborate:Also open by %names%.'],
                    'broken' => ['where' => 'collaborate:in %name%', 'line' => 'collaborate:Also open by %names%.'],
                ],
                'idle' => 60_000,
                'markers' => true,
                'edges' => '.pane',
                'topLayer' => true,
                'presence' => 'menu',
                'distinctColors' => true,
                'shareStream' => true,
                'silentAlone' => true,
                'messages' => [
                    'showPointers' => 'collaborate:Show pointers',
                    'idle' => 'collaborate:idle',
                    'on' => 'collaborate:on %name%',
                    'here' => 'collaborate:on this page',
                    'is' => 'collaborate:%name% is %place%',
                    'line' => 'collaborate:Also open by %names%.',
                    'where' => 'collaborate:in %name%',
                ],
            ],
            $config,
        );
    }

    public function testWithoutATranslatorTheSentencesAreTheKeys(): void
    {
        $layer = $this->element($this->extension(new TestUser(7), host: [
            'idle' => 'x',
            'popups' => 'x',
        ])->layer($this->twig()));
        /** @var array{idle: int, popups: array<array-key, mixed>, messages: array<string, string>} $config */
        $config = json_decode($layer->getAttribute('data-config'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(0, $config['idle']);
        self::assertSame([], $config['popups']);
        self::assertSame('%name% is %place%', $config['messages']['is']);
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
