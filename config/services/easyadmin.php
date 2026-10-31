<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\TranslationMapperInterface;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Topics;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    // EasyAdmin may be installed without being registered (a dependency of another package).
    $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : null;
    if (\is_array($bundles) && !isset($bundles['EasyAdminBundle'])) {
        return;
    }
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

    // Saved-list and saved-detail refresh: collected on flush, announced with the response.
    $services->set(ChangeCollector::class)->args([
        service(DraftAccess::class),
        service('request_stack'),
        param('collaborate.updates'),
        service(TranslationMapperInterface::class)->nullOnInvalid(),
    ])->tag('doctrine.event_listener', ['event' => 'onFlush']);

    $services
        ->set(UpdatesSubscriber::class)
        ->args([
            service(DraftAccess::class),
            service(Topics::class),
            service(HubPublisher::class),
            param('collaborate.updates.enabled'),
            param('collaborate.updates.chunk'),
            param('collaborate.drafts.enabled'),
        ])
        ->tag('kernel.event_subscriber');
};
