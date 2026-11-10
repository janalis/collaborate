<?php

declare(strict_types=1);

namespace Drupal\collaborate;

use Drupal\collaborate\DependencyInjection\DrupalWiringPass;
use Drupal\collaborate\DependencyInjection\Options;
use Drupal\collaborate\Hub\MercureHubs;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Janalis\Collaborate\CollaborateBundle;
use Symfony\Component\Mercure\HubInterface;

/**
 * Registers the bundle's services in Drupal's container: the bundle's own extension loads them from the options
 * the administration form and settings.php give ({@see Options}), then {@see DrupalWiringPass} points what they
 * ask of Symfony's framework at Drupal's services and the module's adapters.
 */
final class CollaborateServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        $extension = new CollaborateBundle()->getContainerExtension();
        if (null === $extension) {
            return;
        }
        // What a Symfony kernel sets before it loads a bundle's extension, and what the services read of it: the
        // project's directory (where `collaborate:push:setup` writes its keys) and the site's language.
        $root = $container->hasParameter('app.root') ? $container->getParameter('app.root') : \DRUPAL_ROOT;
        foreach ([
            'kernel.environment' => 'prod',
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => \dirname(\is_string($root) ? $root : \DRUPAL_ROOT),
            'kernel.default_locale' => Options::language(),
        ] as $name => $value) {
            if (!$container->hasParameter($name)) {
                $container->setParameter($name, $value);
            }
        }
        $container->setParameter('kernel.secret', Options::secret());
        // The bundle's templates, for Drupal's Twig (`@JanalisCollaborate`).
        $bundle = \dirname((string) new \ReflectionClass(CollaborateBundle::class)->getFileName(), 2);
        $container->setParameter('collaborate.templates', $bundle . '/templates');
        // A shared rich-text field's schema names its editor: a Drupal update can change CKEditor's model.
        $container->setParameter('collaborate.editor_version', 'drupal-' . \Drupal::VERSION);
        $options = Options::bundle();
        $hub = \is_array($options['hub'] ?? null) ? $options['hub'] : [];
        if ('websocket' !== ($hub['transport'] ?? 'mercure')) {
            // MercureBundle's service, which the transport is aliased to.
            $name = $hub['mercure']['name'] ?? 'default';
            $container
                ->register('mercure.hub.' . (\is_string($name) ? $name : 'default'), HubInterface::class)
                ->setFactory([MercureHubs::class, 'hub']);
        }
        $extension->load([$options], $container);
        $container->addCompilerPass(new DrupalWiringPass());
    }
}
