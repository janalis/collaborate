<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Kernel;

use Drupal\collaborate\Bridge\EntityForms;
use Drupal\collaborate\Security\DrupalCurrentUser;
use Drupal\collaborate\Storage\Connections;
use Drupal\collaborate\Storage\Schema;
use Drupal\KernelTests\KernelTestBase;
use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\FormDraft;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** The bundle's services in Drupal's container, on the module's adapters and Drupal's database. */
#[RunTestsInSeparateProcesses]
final class ServicesTest extends KernelTestBase
{
    protected static $modules = ['system', 'user', 'collaborate'];

    protected function setUp(): void
    {
        parent::setUp();
        // Read when the hub is made, not when the container is built.
        $this->setSetting('collaborate_mercure', [
            'url' => 'http://127.0.0.1:9/.well-known/mercure',
            'jwt_secret' => str_repeat('k', 32),
        ]);
        Schema::default()->install();
    }

    public function testTheBundlesServicesRunOnTheModulesAdapters(): void
    {
        self::assertSame(Bridge::Drupal->value, $this->container->getParameter('collaborate.bridge'));
        self::assertSame('use collaborate', $this->container->getParameter('collaborate.role'));
        self::assertInstanceOf(DrupalCurrentUser::class, $this->container->get(CurrentUserInterface::class));
        foreach ([
            CollaborateExtension::class,
            DraftController::class,
            HubController::class,
            FormDraft::class,
            EntityForms::class,
        ] as $id) {
            self::assertTrue($this->container->has($id), $id);
            self::assertInstanceOf($id, $this->container->get($id));
        }
        self::assertInstanceOf(MercureTransport::class, $this->container->get(HubTransportInterface::class));
    }

    public function testTheJournalWritesItsTablesUnderTheTestsPrefix(): void
    {
        $journal = $this->container->get(DraftJournal::class);
        self::assertInstanceOf(DraftJournal::class, $journal);
        $fields = ['title[0][value]' => ['type' => 'text', 'initial' => 'Saved', 'formName' => 'title[0][value]']];

        $joined = $journal->join(hash('sha256', 'node:article:1'), $fields, microtime(true));

        self::assertSame('Saved', $joined['fields']['title[0][value]']['value'] ?? null);
        $prefixed = Connections::prefix() . 'collaborate_draft';
        self::assertSame(
            1,
            (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {collaborate_draft}')->fetchField(),
            $prefixed,
        );
    }
}
