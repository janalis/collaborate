<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Holds the draft's named lock until the admin has finished its existing persistence path, which calls
 * {@see completed()} once the record is persisted (the EasyAdmin adapter does on its after-entity events).
 * The editorial flush commits on its own, so its postFlush side effects see committed rows.
 * A checkpoint that fails after that commit is logged, not reported: the record is saved, and the
 * stale draft baseline surfaces to editors as a saved-record conflict.
 *
 * @phpstan-import-type SignedScope from DraftAccess
 * @phpstan-import-type Actor from DraftJournal
 *
 * @phpstan-type SaveState array{scope: SignedScope, completed: bool, actor: Actor}
 */
class DraftSave implements EventSubscriberInterface
{
    public const string CHANGED = 'The shared draft changed while saving. Review it and save again.';
    public const string BUSY = 'Another save of the shared draft is in progress. Save again in a moment.';
    public const string SYNC = 'Synchronize the shared draft before saving.';

    public function __construct(
        private readonly DraftJournal $journal,
        private readonly RequestStack $requests,
        private readonly RecordIdentifier $identifier,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['finish', -2048],
            KernelEvents::EXCEPTION => ['failed', 2048],
        ];
    }

    /** @param SignedScope $scope
     * @param array<string, mixed> $current
     * @param string               $actor   the saving editor's name, shown to the others
     */
    public function begin(Request $request, array $scope, array $current, string $actor): void
    {
        $generation = $request->request->get('_collaborate_generation');
        $revision = $request->request->get('_collaborate_revision');
        if (
            !\is_string($generation)
            || !\is_string($revision)
            || !ctype_digit($generation)
            || !ctype_digit($revision)
        ) {
            // A form posted without the revision it was synchronized at: the client's Save sends it.
            $request->attributes->set('_collaborate_conflict', self::SYNC);
            throw new ConflictHttpException(self::SYNC);
        }
        try {
            $this->journal->lock($scope['id']);
        } catch (ServiceUnavailableHttpException $busy) {
            // The client flushed its edits before submitting; they are in the draft.
            $request->attributes->set('_collaborate_conflict', self::BUSY);
            throw $busy;
        }
        try {
            $this->journal->lockSave($scope['id'], (int) $generation, (int) $revision, $current, $scope['new']);
        } catch (ConflictHttpException $conflict) {
            $this->journal->unlock($scope['id']);
            $request->attributes->set(
                '_collaborate_conflict',
                DraftJournal::ALREADY_CREATED === $conflict->getMessage()
                    ? DraftJournal::ALREADY_CREATED
                    : self::CHANGED,
            );
            throw $conflict;
        } catch (\Throwable $error) {
            $this->journal->unlock($scope['id']);
            throw $error;
        }
        $request->attributes->set('_collaborate_save', [
            'scope' => $scope,
            'completed' => false,
            'actor' => ['userId' => $scope['userId'], 'label' => $actor],
        ]);
    }

    /** The admin has persisted `$entity`: the checkpoint waits for the response, which tells whether the save held. */
    public function completed(object $entity): void
    {
        $request = $this->requests->getMainRequest();
        if (null === $request || !$request->attributes->has('_collaborate_save')) {
            return;
        }
        /** @var SaveState|null $state */
        $state = $request->attributes->get('_collaborate_save');
        \assert(null !== $state);
        /** @var (callable(): array<string, mixed>)|null $snapshot */
        $snapshot = $request->attributes->get('_collaborate_snapshot');
        if (null !== $snapshot) {
            $request->attributes->set('_collaborate_values', $snapshot());
        }
        if ($state['scope']['new']) {
            $id = $this->identifier->value($entity);
            // An edit page addresses single-column ids only: a composite key has no edit page to open.
            if (\is_int($id) || \is_string($id)) {
                $this->journal->created($state['scope']['id'], (string) $id);
            }
        }
        $state['completed'] = true;
        $request->attributes->set('_collaborate_save', $state);
    }

    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        /** @var SaveState|null $state */
        $state = $request->attributes->get('_collaborate_save');
        if (null === $state) {
            return;
        }
        $request->attributes->remove('_collaborate_save');
        if (!$state['completed'] || $event->getResponse()->getStatusCode() >= 400) {
            $this->journal->unlock($state['scope']['id']);

            return;
        }
        /** @var array<string, mixed> $values */
        $values = $request->attributes->get('_collaborate_values', []);
        try {
            $this->journal->saved($state['scope']['id'], $values, $state['actor']);
            $request->attributes->set('_collaborate_committed', $state['scope']['id']);
        } catch (\Throwable $error) {
            $this->logger->error('Shared draft checkpoint failed after the record was saved.', [
                'draft' => $state['scope']['id'],
                'exception' => $error,
            ]);
        } finally {
            $this->journal->unlock($state['scope']['id']);
        }
        $location = $event->getResponse()->headers->get('Location');
        if ($state['scope']['new'] && null !== $location && str_contains($location, '?')) {
            $parts = explode('?', $location, 2);
            $query = [];
            parse_str($parts[1], $query);
            unset($query['draft']);
            $event->getResponse()->headers->set(
                'Location',
                $parts[0] . ([] === $query ? '' : '?' . http_build_query($query)),
            );
        }
    }

    public function failed(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        /** @var SaveState|null $state */
        $state = $request->attributes->get('_collaborate_save');
        if (null !== $state) {
            $request->attributes->remove('_collaborate_save');
            $this->journal->unlock($state['scope']['id']);
        }
        $warning = $request->attributes->getString('_collaborate_conflict');
        if ('' === $warning) {
            return;
        }
        // A peer's edit landed between the client's last pull and this POST, or
        // another save held the draft: return to the form, which rejoins the
        // draft, instead of an error page.
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $this->translator->trans($warning, [], 'collaborate'));
        }
        $event->allowCustomResponseCode();
        $event->setResponse(new RedirectResponse($request->getRequestUri(), Response::HTTP_SEE_OTHER));
    }
}
