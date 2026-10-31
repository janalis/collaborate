<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Command\PurgeDraftsCommand;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Draft\FieldKeyResolver;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DraftText::class);

    $services->set(DraftJournal::class)->args([
        service('database_connection'),
        service(DraftText::class),
        param('collaborate.system_label'),
        param('collaborate.drafts'),
    ])->tag('kernel.reset', ['method' => 'reset']);

    $services->set(RecordIdentifier::class)->args([service('doctrine')->nullOnInvalid()]);

    $services
        ->set(DraftSave::class)
        ->args([
            service(DraftJournal::class),
            service('request_stack'),
            service(RecordIdentifier::class),
            service('logger'),
            service('translator'),
        ])
        ->tag('kernel.event_subscriber')->tag('monolog.logger', ['channel' => 'collaborate']);

    // gedmo.php points the alias at the translatable resolver when Gedmo is installed.
    $services->set(FieldKeyResolver::class);
    $services->alias(FieldKeyResolverInterface::class, FieldKeyResolver::class);

    // Inert while drafts are disabled or no FormContextInterface service (easyadmin.php's, or the
    // application's) says which record a page edits.
    $services
        ->set(CollaborateFormExtension::class)
        ->args([
            service(DraftAccess::class),
            service(DraftJournal::class),
            service(DraftSave::class),
            service(FormContextInterface::class)->nullOnInvalid(),
            service('request_stack'),
            service('security.helper'),
            service(CollaboratorProviderInterface::class),
            service(DraftAccessCheckerInterface::class),
            service('router'),
            service('security.csrf.token_manager'),
            service('translator'),
            service(RecordIdentifier::class),
            service(FieldKeyResolverInterface::class),
            service('asset_mapper.importmap.config_reader')->nullOnInvalid(),
            param('collaborate.role'),
            param('collaborate.drafts'),
            service('logger'),
        ])
        ->tag('form.type_extension')->tag('monolog.logger', ['channel' => 'collaborate']);

    $services
        ->set(DraftController::class)
        ->public()
        ->args([
            service(DraftAccess::class),
            service(DraftJournal::class),
            service(HubPublisher::class),
            service('security.helper'),
            service(CollaboratorProviderInterface::class),
            service('security.csrf.token_manager'),
            param('collaborate.role'),
            service('janalis_collaborate.limiter.drafts')->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');

    $services
        ->set(PurgeDraftsCommand::class)
        ->args([service(DraftJournal::class)])
        ->tag('console.command');
};
