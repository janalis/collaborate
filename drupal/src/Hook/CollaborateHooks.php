<?php

declare(strict_types=1);

namespace Drupal\collaborate\Hook;

use Drupal\collaborate\Bridge\EntityChanges;
use Drupal\collaborate\Bridge\EntityForms;
use Drupal\collaborate\Install\Requirements\CollaborateRequirements;
use Drupal\collaborate\Page\Pages;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Janalis\Collaborate\Draft\DraftJournal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** What the module does on Drupal's hooks: entity forms, entity saves, the pages, and cron. */
final readonly class CollaborateHooks
{
    public function __construct(
        private EntityForms $forms,
        private EntityChanges $changes,
        private Pages $pages,
        private DraftJournal $journal,
        #[Autowire(param: 'collaborate.hub.transport')]
        private string $transport = 'mercure',
    ) {}

    /** @param array<array-key, mixed> $form */
    #[Hook('form_alter')]
    public function formAlter(array &$form, FormStateInterface $state, string $id): void
    {
        $this->forms->alter($form, $state);
    }

    #[Hook('entity_insert')]
    #[Hook('entity_update')]
    #[Hook('entity_delete')]
    public function entityChanged(EntityInterface $entity): void
    {
        $this->changes->changed($entity);
    }

    /** @param array<array-key, mixed> $attachments */
    #[Hook('page_attachments')]
    public function pageAttachments(array &$attachments): void
    {
        $this->pages->attach($attachments);
    }

    /** @param array<array-key, mixed> $page */
    #[Hook('page_bottom')]
    public function pageBottom(array &$page): void
    {
        $this->pages->layer($page);
    }

    /** @param array<array-key, mixed> $variables */
    #[Hook('preprocess_html')]
    public function preprocessHtml(array &$variables): void
    {
        $this->pages->mark($variables);
    }

    /** @return array<string, array<string, mixed>> */
    #[Hook('runtime_requirements')]
    public function runtimeRequirements(): array
    {
        return CollaborateRequirements::runtime('websocket' !== $this->transport);
    }

    /** Drafts saved or abandoned long enough ago go, as `collaborate:purge` does. */
    #[Hook('cron')]
    public function cron(): void
    {
        $this->journal->purge(new \DateTimeImmutable());
    }
}
