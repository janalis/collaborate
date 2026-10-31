<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

/** An admin URL generator that renders its parameters as a query string. */
final class RecordingUrlGenerator implements AdminUrlGeneratorInterface
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public array $parameters = [],
    ) {}

    public function setDashboard(string $dashboardControllerFqcn): self
    {
        return $this->set('dashboard', $dashboardControllerFqcn);
    }

    public function setController(string $crudControllerFqcn): self
    {
        return $this->set('crudControllerFqcn', $crudControllerFqcn);
    }

    public function setAction(string $action): self
    {
        return $this->set('crudAction', $action);
    }

    public function setRoute(string $routeName, array $routeParameters = []): self
    {
        return $this->setAll(['routeName' => $routeName] + $routeParameters);
    }

    public function setEntityId(mixed $entityId): self
    {
        return $this->set('entityId', $entityId);
    }

    public function get(string $paramName): mixed
    {
        return $this->parameters[$paramName] ?? null;
    }

    public function set(string $paramName, mixed $paramValue): self
    {
        $this->parameters[$paramName] = $paramValue;

        return $this;
    }

    public function setAll(array $routeParameters): self
    {
        $this->parameters = $routeParameters + $this->parameters;

        return $this;
    }

    public function unset(string $paramName): self
    {
        unset($this->parameters[$paramName]);

        return $this;
    }

    public function unsetAll(): self
    {
        $this->parameters = [];

        return $this;
    }

    public function unsetAllExcept(string ...$namesOfParamsToKeep): self
    {
        $this->parameters = array_intersect_key($this->parameters, array_flip($namesOfParamsToKeep));

        return $this;
    }

    public function generateUrl(): string
    {
        return '/admin?' . http_build_query($this->parameters);
    }
}
