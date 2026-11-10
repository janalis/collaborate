<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * An open edit page compares its shared draft with the saved record again after a save elsewhere,
 * and before its own; a new-record page renews
 * its grant before saving. Asked with `X-Collaborate-Refresh: 1`, the edit or new action answers
 * the form's fresh grant and CSRF tokens instead of rendering the page.
 */
final readonly class DraftRefreshSubscriber
{
    public function __construct(
        private RequestStack $requests,
    ) {}

    public function __invoke(AfterCrudActionEvent $event): void
    {
        $request = $this->requests->getMainRequest();
        if (null === $request || !$request->isMethodSafe() || '1' !== $request->headers->get('X-Collaborate-Refresh')) {
            return;
        }
        $form = $this->form($event->getResponseParameters()->all());
        if (null === $form) {
            return;
        }
        $view = $form->createView();
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];
        $event->setResponse(
            new JsonResponse([
                'configuration' => isset($attributes['data-collaborate']) && \is_string($attributes['data-collaborate'])
                    ? json_decode($attributes['data-collaborate'], true, flags: \JSON_THROW_ON_ERROR)
                    : null,
                'tokens' => $this->tokens($view),
            ], headers: ['Cache-Control' => 'no-store', 'Vary' => 'X-Collaborate-Refresh']),
        );
    }

    /** @param array<array-key, mixed> $parameters */
    private function form(array $parameters): ?FormInterface
    {
        if (isset($parameters['edit_form']) && $parameters['edit_form'] instanceof FormInterface) {
            return $parameters['edit_form'];
        }

        return isset($parameters['new_form']) && $parameters['new_form'] instanceof FormInterface
            ? $parameters['new_form']
            : null;
    }

    /** @return array<string, string> the CSRF token of each form, by its control name */
    private function tokens(FormView $view): array
    {
        $tokens = [];
        foreach ($view->children as $name => $child) {
            if (
                '_token' === $name
                && \is_string($child->vars['full_name'] ?? null)
                && \is_string($child->vars['value'] ?? null)
            ) {
                $tokens[$child->vars['full_name']] = $child->vars['value'];
                continue;
            }
            $tokens += $this->tokens($child);
        }

        return $tokens;
    }
}
