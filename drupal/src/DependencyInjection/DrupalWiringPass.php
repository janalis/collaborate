<?php

declare(strict_types=1);

namespace Drupal\collaborate\DependencyInjection;

use Drupal\collaborate\Security\DrupalCurrentUser;
use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\RecordIdentifierInterface;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Janalis\Collaborate\Security\SymfonyCurrentUser;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Points what the bundle's services ask of Symfony's framework at Drupal's services and the module's adapters,
 * renames the framework's tags to Drupal's, and drops what serves Symfony forms and Doctrine flushes alone: the
 * module shares Drupal's entity forms and hears of its entity saves itself.
 */
final class DrupalWiringPass implements CompilerPassInterface
{
    /** Symfony's service ids the bundle's definitions name => Drupal's, or the module's. */
    public const array SERVICES = [
        'security.csrf.token_manager' => 'collaborate.csrf',
        'router' => 'url_generator',
        'translator' => 'collaborate.translator',
        'logger' => 'logger.channel.collaborate',
        'clock' => 'collaborate.clock',
        'database_connection' => 'collaborate.dbal',
        'doctrine.orm.entity_manager' => 'collaborate.entity_manager',
        // Drupal's `http_client` is Guzzle's; the bundle's transports speak Symfony's contracts.
        'http_client' => 'collaborate.http_client',
        'twig' => 'twig',
        'request_stack' => 'request_stack',
    ];

    /** Symfony's tags => Drupal's. */
    private const array TAGS = ['kernel.event_subscriber' => 'event_subscriber'];

    /** What serves Symfony's form component and Doctrine's flushes: the module replaces both. */
    private const array REMOVED = [
        CollaborateFormExtension::class,
        ChangeCollector::class,
        RecordIdentifier::class,
        SymfonyCurrentUser::class,
    ];

    private ?ContainerBuilder $container = null;

    public function process(ContainerBuilder $container): void
    {
        $container->setParameter('collaborate.bridge', Bridge::Drupal->value);
        foreach (self::REMOVED as $id) {
            $container->removeDefinition($id);
        }
        $container->setAlias(CurrentUserInterface::class, DrupalCurrentUser::class);
        $container->setAlias(RecordIdentifierInterface::class, 'collaborate.record_identifier');
        // The notifications' Twig functions call their runtime, which Drupal's Twig finds in the container.
        if ($container->hasDefinition('twig')) {
            $container->getDefinition('twig')->addMethodCall('addRuntimeLoader', [new Reference(
                'collaborate.twig_runtime_loader',
            )]);
        }
        $this->container = $container;
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'Janalis\\Collaborate\\') && !str_starts_with($id, 'janalis_collaborate.')) {
                continue;
            }
            $this->rewire($definition);
            // Drupal resolves a route's controller, a queue's handler and a Drush command's service by its id.
            $definition->setPublic(true);
        }
    }

    private function rewire(Definition $definition): void
    {
        $definition->setArguments($this->map($definition->getArguments()));
        $calls = [];
        foreach ($definition->getMethodCalls() as [$method, $arguments]) {
            $calls[] = [$method, $this->map($arguments)];
        }
        $definition->setMethodCalls($calls);
        $tags = $definition->getTags();
        foreach (self::TAGS as $symfony => $drupal) {
            if (isset($tags[$symfony])) {
                $tags[$drupal] = $tags[$symfony];
                unset($tags[$symfony]);
            }
        }
        // Monolog's channels and the kernel's resets mean nothing to Drupal's container.
        unset($tags['monolog.logger'], $tags['kernel.reset'], $tags['controller.service_arguments']);
        $definition->setTags($tags);
    }

    /**
     * A parameter holding a `%` (a sentence's `%name%`), as the argument itself: Drupal's dumper escapes a nested
     * parameter once per level, so its sentences would reach the services with their `%` multiplied, while it reads
     * an argument's escaped `%%` once.
     */
    private function inline(string $name, string $argument): mixed
    {
        $bag = $this->container?->getParameterBag();
        if (null === $bag || !$bag->has($name)) {
            return $argument;
        }
        $value = $bag->get($name);

        return self::holdsPercent($value) ? $value : $argument;
    }

    private static function holdsPercent(mixed $value): bool
    {
        if (\is_string($value)) {
            return str_contains($value, '%');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (self::holdsPercent($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function map(array $arguments): array
    {
        foreach ($arguments as $key => $argument) {
            if (\is_string($argument) && 1 === preg_match('/^%([^%]+)%$/', $argument, $match)) {
                $arguments[$key] = $this->inline($match[1], $argument);
            } elseif ($argument instanceof Reference && isset(self::SERVICES[(string) $argument])) {
                $arguments[$key] = new Reference(self::SERVICES[(string) $argument], $argument->getInvalidBehavior());
            } elseif (\is_array($argument)) {
                $arguments[$key] = $this->map($argument);
            } elseif ($argument instanceof ArgumentInterface) {
                $argument->setValues($this->map($argument->getValues()));
            }
        }

        return $arguments;
    }
}
