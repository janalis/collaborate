<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Cursor\CursorPosition;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Application\Host\HostKernel;
use Janalis\Collaborate\Tests\Application\Host\HostPlaces;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bundle in an application whose pages are no forms: a library of folders, each the place of its pages
 * whatever their address or view, with every cursor option on and drafts off.
 */
#[CoversClass(RoleDraftAccessChecker::class)]
#[CoversClass(CollaborateBundle::class)]
#[CoversClass(CursorController::class)]
#[CoversClass(HubController::class)]
#[CoversClass(CursorEvent::class)]
#[CoversClass(CursorPosition::class)]
#[CoversClass(PageGrant::class)]
#[CoversClass(ChangeCollector::class)]
#[CoversClass(DraftAccess::class)]
#[CoversClass(CollaborateFormExtension::class)]
#[CoversClass(Grant::class)]
#[CoversClass(Admission::class)]
#[CoversClass(HubGuard::class)]
#[CoversClass(HubPublisher::class)]
#[CoversClass(RenewSubscriber::class)]
#[CoversClass(Topics::class)]
#[CoversClass(SecurityCollaboratorProvider::class)]
#[CoversClass(CollaborateExtension::class)]
// Built by the container whatever the options, and idle with drafts and saved views off.
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(UpdatesSubscriber::class)]
final class HostApplicationTest extends ApplicationTestCase
{
    private const array EVENT = [
        'clientId' => '12345678-1234-1234',
        'sequence' => 1,
        'kind' => 'move',
        'style' => 'mac',
        'anchor' => ['selector' => 'tr[data-key="music-1"] > td.name', 'region' => 'listing', 'x' => 0.5, 'y' => 0.5],
    ];

    protected static function kernel(): string
    {
        return HostKernel::class;
    }

    protected function editUrl(int $id): string
    {
        throw new \LogicException('The library edits no record.');
    }

    protected function newUrl(): string
    {
        throw new \LogicException('The library edits no record.');
    }

    protected function saveButton(bool $new): string
    {
        throw new \LogicException('The library edits no record.');
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->places()->revoke('music', false);
        $this->signIn('alice');
    }

    public function testTheLayerCarriesAPlaceGrantAndTheHostsOptions(): void
    {
        $layer = self::layer($this->open('/admin/library/music'));
        $parts = explode('.', $layer['grant']);
        self::assertCount(6, $parts);
        self::assertSame('15', $parts[0]);
        self::assertSame(
            ['library', 'music'],
            json_decode((string) base64_decode(strtr($parts[4], '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertNotNull($layer['events']);
        self::assertFalse($layer['updates']);
        $config = $layer['config'];
        self::assertSame(HostKernel::COLORS, $config['colors']);
        self::assertSame('data-key', $config['keyAttribute']);
        self::assertSame('#listing', $config['keyScope']);
        self::assertSame(['data-action', 'name', 'id'], $config['anchorAttributes']);
        self::assertSame(['modal'], $config['caretRegions']);
        self::assertSame(
            ['share' => ['where' => 'in the sharing of %name%', 'line' => 'Also sharing: %names%.']],
            $config['popups'],
        );
        self::assertSame(5000, $config['idle']);
        self::assertSame('.pane', $config['edges']);
        self::assertSame('menu', $config['presence']);
        foreach (['markers', 'topLayer', 'distinctColors', 'silentAlone', 'shareStream', 'anchorClasses'] as $flag) {
            self::assertTrue($config[$flag], $flag);
        }
        self::assertIsArray($config['messages']);
        self::assertSame('Show pointers', $config['messages']['showPointers']);
        self::assertSame('%name% is %place%', $config['messages']['is']);
    }

    public function testAFolderIsOneRoomWhateverItsAddressAndView(): void
    {
        $room = static fn(Crawler $page): string => explode('.', self::layer($page)['grant'])[3];
        $library = $room($this->open('/admin/library/music'));
        self::assertSame($library, $room($this->open('/admin/homes/alice/music')));
        self::assertSame($library, $room($this->open('/admin/library/music?view=tiles')));
        self::assertNotSame($library, $room($this->open('/admin/library/films')));
        // The listing a page loads in place of its own enters the same room.
        $listing = $this->open('/admin/library/music/listing')->filter('[data-collaborate-grant]');
        self::assertSame($library, explode('.', (string) $listing->attr('data-collaborate-grant'))[3]);
    }

    public function testAPageAndItsEventsAreAdmittedAndAPopupIsToldPrivately(): void
    {
        $layer = self::layer($this->open('/admin/library/music'));
        $session = $this->post($layer['session'], $layer['csrf'], ['grant' => $layer['grant']]);
        self::assertIsArray($session['topics']);
        [$topic] = $session['topics'];
        self::assertIsString($topic);
        $popup = ['kind' => 'share', 'key' => 'music-2'];
        self::assertIsString($layer['events']);
        $this->post(
            $layer['events'],
            $layer['csrf'],
            ['grant' => $layer['grant'], ...self::EVENT, 'popup' => $popup],
            204,
        );
        [$event] = self::on($this->published(), $topic);
        self::assertSame($popup, $event['popup']);
        self::assertSame(self::EVENT['anchor'], $event['anchor']);
        self::assertSame('Alice', $event['label']);
        self::assertContains($event['color'], HostKernel::COLORS);
    }

    public function testAnEventOutsideTheHostsRegionsOrPopupsIsRefused(): void
    {
        $layer = self::layer($this->open('/admin/library/music'));
        self::assertIsString($layer['events']);
        foreach ([
            ['anchor' => ['region' => 'content'] + self::EVENT['anchor']],
            ['popup' => ['kind' => 'metadata', 'key' => 'music-2']],
            // A caret in the toolbar's search box is the reader's own.
            [
                'anchor' => null,
                'caret' => ['selector' => 'input[name="q"]', 'region' => 'toolbar', 'start' => 0, 'end' => 0],
            ],
        ] as $invalid) {
            $this->post($layer['events'], $layer['csrf'], ['grant' => $layer['grant'], ...$invalid] + self::EVENT, 400);
        }
    }

    public function testARevokedFolderIsRefusedAtOnce(): void
    {
        $layer = self::layer($this->open('/admin/library/music'));
        self::assertIsString($layer['events']);
        $this->places()->revoke('music');
        $this->post($layer['session'], $layer['csrf'], ['grant' => $layer['grant']], 403);
        $this->post($layer['events'], $layer['csrf'], ['grant' => $layer['grant'], ...self::EVENT], 403);
        // Another folder is still shared.
        $films = self::layer($this->open('/admin/library/films'));
        $this->post($films['session'], $films['csrf'], ['grant' => $films['grant']]);
    }

    public function testAPageInNoPlaceHasNoLayer(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/library/music');
        // The login page has no place: nothing to render, whoever asks.
        $page = $this->client->request('GET', '/login');
        self::assertCount(0, $page->filter('[data-collaborate-layer]'));
    }

    private function places(): HostPlaces
    {
        $places = static::getContainer()->get(HostPlaces::class);
        self::assertInstanceOf(HostPlaces::class, $places);

        return $places;
    }
}
