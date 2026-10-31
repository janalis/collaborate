<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('janalis_collaborate.identity.security', SecurityCollaboratorProvider::class);
    $services->set('janalis_collaborate.access.role', RoleDraftAccessChecker::class);
    // CollaboratorProviderInterface, DraftAccessCheckerInterface and janalis_collaborate.grant_cache are
    // aliased by CollaborateBundle::loadExtension() to the configured services.

    $services->set(Topics::class)->args([param('collaborate.hub.topic_prefix')]);

    // The page grant also admits a page to the hub when cursors are disabled.
    $services->set(PageGrant::class)->args([
        param('kernel.secret'),
        service(Topics::class),
        param('collaborate.cursors.grant_ttl'),
    ]);

    $services->set(DraftAccess::class)->args([
        param('kernel.secret'),
        param('collaborate.drafts.enabled'),
        service(Topics::class),
        service(CollaboratorProviderInterface::class),
        service(DraftAccessCheckerInterface::class),
        service('janalis_collaborate.grant_cache'),
        param('collaborate.drafts.grant_ttl'),
        param('collaborate.drafts.signature_ttl'),
        param('collaborate.drafts.max_request_bytes'),
    ]);
};
