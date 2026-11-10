<?php

declare(strict_types=1);

namespace Drupal\collaborate\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Janalis\Collaborate\Controller\NotificationController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bundle's pages of all notifications and of the topics, in the administration theme's page: the bundle
 * renders their body (the module's layout, `notifications.layout`), Drupal the rest. A page's own requests for
 * more rows, or its list, are the bundle's answers as they are.
 */
final class NotificationPages implements ContainerInjectionInterface
{
    use StringTranslationTrait;

    public function __construct(
        private readonly NotificationController $controller,
    ) {}

    public static function create(ContainerInterface $container): self
    {
        $controller = $container->get(NotificationController::class);
        \assert($controller instanceof NotificationController);

        return new self($controller);
    }

    /** @return array<array-key, mixed>|Response */
    public function page(Request $request): array|Response
    {
        return $this->wrap($this->controller->page($request), $request, (string) $this->t('Notifications'));
    }

    /** @return array<array-key, mixed>|Response */
    public function topics(Request $request): array|Response
    {
        return $this->wrap($this->controller->topics($request), $request, (string) $this->t('What I am notified of'));
    }

    /** @return array<array-key, mixed>|Response */
    private function wrap(Response $response, Request $request, string $title): array|Response
    {
        if ($request->isXmlHttpRequest() || !$response->isSuccessful()) {
            return $response;
        }

        return [
            '#title' => $title,
            '#markup' => Markup::create((string) $response->getContent()),
            '#attached' => ['library' => ['collaborate/drupal']],
            '#cache' => ['max-age' => 0],
        ];
    }
}
