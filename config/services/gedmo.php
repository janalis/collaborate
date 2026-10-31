<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Doctrine\TranslationMapperInterface;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Janalis\Collaborate\Gedmo\TranslatableFieldKeyResolver;
use Janalis\Collaborate\Gedmo\TranslationMapper;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Translated properties keep a draft state per locale; records are created in the default locale.
    $services->set(TranslatableFieldKeyResolver::class)->args([
        service('request_stack'),
        param('kernel.default_locale'),
    ]);
    $services->alias(FieldKeyResolverInterface::class, TranslatableFieldKeyResolver::class);

    // A saved translation row announces its object.
    $services->set(TranslationMapper::class);
    $services->alias(TranslationMapperInterface::class, TranslationMapper::class);
};
