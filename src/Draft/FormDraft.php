<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shared draft of a page's form, whatever builds the form: its signed scope, the `data-collaborate`
 * configuration its page reads, and the locked save a signed submission begins. Symfony forms reach it through
 * {@see \Janalis\Collaborate\Form\CollaborateFormExtension}, which describes their fields; Drupal's entity forms
 * through the janalis/collaborate_drupal module.
 *
 * @phpstan-import-type Scope from DraftAccess
 * @phpstan-import-type SchemaField from DraftJournal
 * @phpstan-type Record array{entity: object|null, class: string, new: bool, id: string|null, draft: string|null}
 *
 * @phpstan-type Client array{replayConnected: int, replayDisconnected: int, joinRetry: int, labelLength: int}
 * @phpstan-type Configuration array{grant: string, url: string, csrf: string, messages: array<string, string>, isNew: bool, editUrl: string, invitation: string|null, config: Client}
 * @phpstan-type Options array{max_update_bytes?: int, replay?: array{connected?: int, disconnected?: int, join_retry?: int, ...}, label_length?: int, ...}
 */
final readonly class FormDraft
{
    /** The bar's, dialogs' and notices' strings, by the key the client reads them under (domain `collaborate`). */
    public const array MESSAGES = [
        'connecting' => 'Connecting to the shared draft…',
        'saved' => 'All changes saved.',
        'draft' => 'Unsaved changes, kept automatically. Click Save to record them.',
        'pending' => 'Synchronizing changes…',
        'offline' => 'Connection interrupted. Keep this page open to synchronize your changes.',
        'denied' => 'Collaboration access expired. Your local content is retained.',
        'unavailable' => 'The shared draft is unavailable. Changes you save here are not shared live.',
        'incompatible' => 'This editor is incompatible with the shared draft. Reload the page.',
        'discarded' => 'The shared draft was reset. Your local content is retained; copy unsaved changes before reloading.',
        'discardedBy' => '%name% reset the shared draft. Your local content is retained; copy unsaved changes before reloading.',
        'savedBy' => 'All changes saved by %name%.',
        'changed' => 'The shared draft changed meanwhile. Review it and try again.',
        'failed' => 'The server refused this action. Copy unsaved content and reload the page.',
        'save' => 'Save',
        'discard' => 'Discard changes',
        'confirmDiscardTitle' => 'Discard unsaved changes?',
        'confirmDiscardBody' => 'The unsaved changes are erased: yours and those of everyone editing this record. To keep them, cancel and click Save.',
        'confirmDiscardAccept' => 'Erase changes',
        'confirmSavedTitle' => 'Use the saved record?',
        'confirmSavedBody' => "The shared draft's conflicting changes are replaced by the saved values for everyone.",
        'confirmSavedAccept' => 'Use saved record',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'copyInvitation' => 'Copy invitation link',
        'copied' => 'Copied',
        'invitationCopied' => 'Invitation link copied.',
        'copyInvitationFallback' => 'Copy this link to invite another editor:',
        'noticeDiscardedOwn' => 'You discarded the shared draft.',
        'noticeDiscarded' => 'The shared draft was discarded; this page reloaded.',
        'noticeDiscardedBy' => '%name% discarded the shared draft; this page reloaded.',
        'noticeUsedSaved' => 'The shared draft now uses the saved record.',
        'created' => 'This draft has created its record. Local changes are retained; copy unsent content before opening the saved record.',
        'createdBy' => '%name% created this record. Local changes are retained; copy unsent content before opening the saved record.',
        'openRecord' => 'Open saved record',
        'conflict' => 'The saved record differs from this shared draft. Review both versions before saving.',
        'keepDraft' => 'Keep shared draft',
        'useSaved' => 'Use saved record',
        'savedVersion' => 'Saved record',
        'draftVersion' => 'Shared draft',
        'conflictsTitle' => 'Differences with the saved record',
        'entryRemoved' => 'Entry removed',
        'entryPresent' => 'Entry present',
        'orderChanged' => 'Order changed',
        'yes' => 'Yes',
        'no' => 'No',
        'full' => 'The shared draft limit was reached. Copy unsent content before discarding this draft.',
    ];

    /** The largest update: a page sends its full grant along with one. */
    private int $maxUpdateBytes;

    /** @var Client */
    private array $client;

    /** @param Options $options the `collaborate.drafts` configuration */
    public function __construct(
        private DraftAccess $access,
        private DraftSave $save,
        private CollaboratorProviderInterface $collaborators,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private LoggerInterface $logger = new NullLogger(),
        array $options = [],
    ) {
        $this->maxUpdateBytes = $options['max_update_bytes'] ?? 262_144;
        $this->client = [
            'replayConnected' => ($options['replay']['connected'] ?? 30) * 1000,
            'replayDisconnected' => ($options['replay']['disconnected'] ?? 5) * 1000,
            'joinRetry' => ($options['replay']['join_retry'] ?? 12) * 1000,
            'labelLength' => $options['label_length'] ?? 255,
        ];
    }

    /**
     * The scope a grant signs for the form of a record: a new record's draft is the one its page was opened with
     * when it is 32 hex characters, else one drawn once per request.
     *
     * @param Record                     $record the record: its class, or the kind of record a bridge names
     * @param 'create'|'update'          $permission
     * @param array<string, SchemaField> $fields the form's field schema
     *
     * @return Scope
     */
    public function scope(array $record, string $permission, Request $request, array $fields): array
    {
        $new = $record['new'];
        $draft = null;
        if ($new) {
            $draft = $record['draft'];
            if (null === $draft || 1 !== preg_match('/^[a-f0-9]{32}$/D', $draft)) {
                $drawn = $request->attributes->getString('_collaborate_new_draft');
                if ('' === $drawn) {
                    $drawn = bin2hex(random_bytes(16));
                    $request->attributes->set('_collaborate_new_draft', $drawn);
                }
                $draft = $drawn;
            }
        }
        $identity = null === $draft ? $record['id'] ?? '' : 'new:' . $draft;

        return [
            'id' => $this->access->draftId($record['class'], $identity),
            'entity' => $record['class'],
            'permission' => $permission,
            'new' => $new,
            'draft' => $draft,
            'requestedAt' => self::requestedAt($request->server->get('REQUEST_TIME_FLOAT')),
            'fields' => $fields,
        ];
    }

    /**
     * What the form's `data-collaborate` attribute says, JSON-encoded: its grant, the draft actions' URL and
     * token, the strings the bar says, and a new record's invitation. Null when the grant is too large to send
     * with an update: the form is then edited without a draft.
     *
     * @param Scope  $scope
     * @param string $editUrl the URL of a record's edit page, `__record__` standing for its id
     *
     * @return Configuration|null
     */
    public function configuration(array $scope, UserInterface $user, Request $request, string $editUrl): ?array
    {
        $grant = $this->access->issue(
            $scope,
            (int) $this->collaborators->id($user),
            time(),
            $this->collaborators->authorization($user),
        );
        // A page sends its full grant at least once, with an update when the server has forgotten it.
        if (\strlen($grant) > ($this->access->maxRequestBytes - $this->maxUpdateBytes - 1024)) {
            $this->logger->warning('The {entity} form is too large to share as a draft; it is edited without one.', [
                'entity' => $scope['entity'],
                'draft' => $scope['id'],
                'grant_bytes' => \strlen($grant),
            ]);

            return null;
        }

        return [
            'grant' => $grant,
            'url' => substr($this->urls->generate('collaborate_draft', ['action' => 'join']), 0, -4) . '__action__',
            'csrf' => (string) $this->csrf->getToken('collaborate-draft'),
            'messages' => array_map(fn(string $message): string => $this->translator->trans(
                $message,
                [],
                'collaborate',
            ), self::MESSAGES),
            'isNew' => $scope['new'],
            'editUrl' => $editUrl,
            'invitation' => $scope['new']
                ? $request->getPathInfo() . '?' . http_build_query(array_replace($request->query->all(), [
                    'draft' => $scope['draft'],
                ]))
                : null,
            'config' => $this->client,
        ];
    }

    /**
     * The action a new record's form posts to, naming the draft its grant names; null when it names it already.
     * A malformed key in the URL is drawn again on every request: the form posts the one its grant names.
     */
    public static function action(string $action, string $draft): ?string
    {
        $parts = explode('?', $action, 2);
        $query = [];
        parse_str($parts[1] ?? '', $query);
        if (($query['draft'] ?? null) === $draft) {
            return null;
        }
        $query['draft'] = $draft;

        return $parts[0] . '?' . http_build_query($query);
    }

    /**
     * Begins the locked save of a signed submission of the form: its grant must name the form's draft. A
     * submission without one (no script) takes the ordinary path, its write an external change to the draft.
     *
     * @param Scope                            $scope    the scope of the form as the page was given it
     * @param \Closure(): array<string, mixed> $snapshot the form's values once the record is persisted, by field
     */
    public function begin(Request $request, UserInterface $user, array $scope, \Closure $snapshot): void
    {
        if (!$request->request->has('_collaborate_grant')) {
            return;
        }
        $grant = $request->request->get('_collaborate_grant', '');
        $authorized = $this->access->verify(\is_string($grant) ? $grant : '', $user, time());
        if ($scope['id'] !== $authorized['id']) {
            throw new AccessDeniedHttpException('Collaborate grant belongs to another form.');
        }
        $values = array_map(static fn(array $field): mixed => $field['initial'], $scope['fields']);
        $this->save->begin($request, $authorized, $values, $this->collaborators->label($user));
        if ($request->attributes->has('_collaborate_save')) {
            // Read once the admin has persisted the record (DraftSave::completed()).
            $request->attributes->set('_collaborate_snapshot', $snapshot);
        }
    }

    /** When the page's request started: a grant's conflicts compare the draft with the record it read then. */
    private static function requestedAt(mixed $time): float
    {
        return is_numeric($time) ? (float) $time : microtime(true);
    }
}
