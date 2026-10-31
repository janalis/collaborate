<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\Form\FormContextInterface;

// Imported by CollaborateBundle when EasyAdminBundle is registered.
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

    // ChangeCollector and UpdatesSubscriber are registered by drafts.php: a host without EasyAdmin hears of saves too.
};
