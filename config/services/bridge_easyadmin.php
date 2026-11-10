<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\CrudAutocompleteType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Janalis\Collaborate\Bridge\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\Bridge\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\Bridge\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Form\FormContextInterface;

// Imported by CollaborateBundle when collaborate.bridge is easyadmin, or auto with EasyAdminBundle registered.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(EasyAdminFormContext::class)->args([
        service(AdminContextProviderInterface::class),
        service(AdminUrlGeneratorInterface::class),
    ]);
    $services->alias(FormContextInterface::class, EasyAdminFormContext::class);

    $services->set(DraftRefreshSubscriber::class)->args([service('request_stack')])->tag('kernel.event_listener', [
        'event' => AfterCrudActionEvent::class,
        'method' => '__invoke',
    ]);

    $services
        ->set(SaveSubscriber::class)
        ->args([service(DraftSave::class)])
        ->tag('kernel.event_subscriber');

    // An association's autocomplete maps its parent's property to its inner control itself.
    $services->get(CollaborateFormExtension::class)->arg('$ownerMappers', [CrudAutocompleteType::class]);

    // ChangeCollector and UpdatesSubscriber are registered by drafts.php: a host without EasyAdmin hears of saves too.
};
