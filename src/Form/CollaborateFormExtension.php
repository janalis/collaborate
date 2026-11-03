<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Form;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\CrudAutocompleteType;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FieldKeyResolver;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shares the root form of an edit or new page as a draft: it describes the form's editable fields (the
 * field schema the grant signs), keys collection entries stably, holds the draft's save lock across a
 * signed submission and renders the `data-collaborate` attribute. {@see FormContextInterface} says which
 * record the page edits; without one, or with drafts disabled, the extension does nothing.
 *
 * @phpstan-import-type Scope from DraftAccess
 * @phpstan-import-type SchemaField from DraftJournal
 * @phpstan-import-type FormRecord from FormContextInterface
 *
 * @phpstan-type Sharing array{user: UserInterface, record: FormRecord, context: FormContextInterface, request: Request, permission: 'create'|'update'}
 *
 * @phpstan-type Options array{max_update_bytes?: int, rich_text?: array{block_prefixes?: list<string>, schema_option?: string|null, ckeditor_version?: string|null, ...}, position_field?: string, sensitive_fields?: list<string>, replay?: array{connected?: int, disconnected?: int, join_retry?: int, ...}, label_length?: int, ...}
 */
final class CollaborateFormExtension extends AbstractTypeExtension
{
    /** The bar's, dialogs' and notices' strings, by the key the client reads them under (domain `collaborate`). */
    public const array MESSAGES = [
        'connecting' => 'Connecting to the shared draft…',
        'saved' => 'Shared draft saved.',
        'draft' => 'Shared draft has unsaved changes.',
        'pending' => 'Synchronizing changes…',
        'offline' => 'Connection interrupted. Keep this page open to synchronize your changes.',
        'denied' => 'Collaboration access expired. Your local content is retained.',
        'unavailable' => 'The shared draft is unavailable. Changes you save here are not shared live.',
        'incompatible' => 'This editor is incompatible with the shared draft. Reload the page.',
        'discarded' => 'The shared draft was reset. Your local content is retained; copy unsaved changes before reloading.',
        'discardedBy' => '%name% reset the shared draft. Your local content is retained; copy unsaved changes before reloading.',
        'savedBy' => 'Shared draft saved by %name%.',
        'changed' => 'The shared draft changed meanwhile. Review it and try again.',
        'failed' => 'The server refused this action. Copy unsaved content and reload the page.',
        'discard' => 'Discard shared draft',
        'confirmDiscardTitle' => 'Discard the shared draft?',
        'confirmDiscardBody' => 'Everyone editing this record loses its unsaved changes.',
        'confirmDiscardAccept' => 'Discard',
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

    /** Block prefixes of the controls whose value never enters a draft. */
    private const array UNEDITABLE = ['password', 'hidden', 'file', 'button', 'submit', 'reset'];

    /** Block prefixes of the controls a draft edits as plain text. */
    private const array TEXT = ['text', 'textarea', 'email', 'url', 'tel', 'search'];

    /** @var \WeakMap<FormInterface, string> Collections whose list data was rekeyed by stable keys, with their paths. */
    private \WeakMap $lists;

    /** @var list<string> */
    private array $richText;

    private ?string $schemaOption;

    private ?string $editorVersion;

    private string $positionField;

    /** The largest update: a page sends its full grant along with one. */
    private int $maxUpdateBytes;

    /** @var list<string> */
    private array $sensitive;

    /** @var array{replayConnected: int, replayDisconnected: int, joinRetry: int, labelLength: int} */
    private array $client;

    /** @param Options $options the `collaborate.drafts` configuration */
    public function __construct(
        private readonly DraftAccess $access,
        private readonly DraftJournal $journal,
        private readonly DraftSave $save,
        private readonly ?FormContextInterface $context,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly DraftAccessCheckerInterface $checker,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
        private readonly RecordIdentifier $identifier = new RecordIdentifier(),
        private readonly FieldKeyResolverInterface $keys = new FieldKeyResolver(),
        private readonly ?ImportMapConfigReader $importMap = null,
        private readonly string $role = 'ROLE_ADMIN',
        array $options = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->lists = new \WeakMap();
        $this->richText = $options['rich_text']['block_prefixes'] ?? [];
        $this->schemaOption = $options['rich_text']['schema_option'] ?? null;
        $this->editorVersion = $options['rich_text']['ckeditor_version'] ?? null;
        $this->positionField = $options['position_field'] ?? 'position';
        $this->maxUpdateBytes = $options['max_update_bytes'] ?? 262_144;
        $this->sensitive = array_map(static fn(string $name): string => strtolower(str_replace(
            ['_', '-'],
            '',
            $name,
        )), $options['sensitive_fields'] ?? CollaborateBundle::SENSITIVE_FIELDS);
        $this->client = [
            'replayConnected' => ($options['replay']['connected'] ?? 30) * 1000,
            'replayDisconnected' => ($options['replay']['disconnected'] ?? 5) * 1000,
            'joinRetry' => ($options['replay']['join_retry'] ?? 12) * 1000,
            'labelLength' => $options['label_length'] ?? 255,
        ];
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $context = $this->context;
        if (!$this->access->enabled || null === $context) {
            return;
        }
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($context): void {
                $form = $event->getForm();
                if (!$this->isCollection($form) || !$this->security->isGranted($this->role)) {
                    return;
                }
                $record = $context->current();
                if (null === $record || $form->getRoot()->getConfig()->getData() !== $record['entity']) {
                    return;
                }
                $data = $event->getData();
                if (!\is_array($data) && !$data instanceof Collection) {
                    return;
                }
                $aliases = $this->aliases($record);
                $path = $this->path($form);
                // A plain list keeps no key of its own: a save checkpointed its entries' keys in the order it
                // stored them, so the reopened entries take those keys instead of their positions.
                $listKeys = \is_array($data) && array_is_list($data) ? $this->listKeys($record)[$path] ?? [] : [];
                if (\count($listKeys) !== \count($data)) {
                    $listKeys = [];
                }
                $keyed = [];
                foreach ($data as $key => $item) {
                    $recordId = $this->recordId($item);
                    $stable = null !== $recordId
                        ? $aliases[$path][$recordId] ?? $this->memberKey($recordId)
                        : $listKeys[$key] ?? (string) $key;
                    if (ctype_digit($stable)) {
                        $stable = 'p' . $stable;
                    }
                    $keyed[$stable] = $item;
                }
                if (\is_array($data) && array_is_list($data)) {
                    $this->lists[$form] = $path;
                }
                $event->setData($data instanceof Collection ? new ArrayCollection($keyed) : $keyed);
            },
            512,
        );
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event): void {
                $form = $event->getForm();
                $data = $event->getData();
                if (!$this->isCollection($form) || !\is_array($data)) {
                    return;
                }
                // ResizeFormListener has resolved the stable keys. Keep its child
                // iteration order aligned with the submitted collection order.
                foreach (array_keys($data) as $name) {
                    if (!$form->has((string) $name)) {
                        continue;
                    }

                    $child = $form->get((string) $name);
                    $form->remove((string) $name);
                    $form->add($child);
                }
            },
            -1024,
        );
        $builder->addEventListener(
            FormEvents::SUBMIT,
            function (FormEvent $event): void {
                $form = $event->getForm();
                $data = $event->getData();
                if (!isset($this->lists[$form]) || !\is_array($data)) {
                    return;
                }
                // The stable keys name the form's children only: a plain array goes back to the record as a list,
                // in the submitted order the children follow.
                $event->setData(array_values(array_replace(array_intersect_key($form->all(), $data), $data)));
            },
            -1024,
        );
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event): void {
                $form = $event->getForm();
                $sharing = $this->sharing($form);
                if (null === $sharing) {
                    return;
                }
                ['user' => $user, 'request' => $request] = $sharing;
                $scope = $this->scope($form, $sharing);
                // Validation re-renders must describe the saved record, not the
                // submitted values that Symfony is about to map onto its entity.
                $request->attributes->set('_collaborate_form_scope', ['form' => $form, 'scope' => $scope]);
                // Non-JavaScript clients keep the ordinary Symfony submission path.
                // Their writes are detected as external changes by active drafts.
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
                    $request->attributes->set('_collaborate_snapshot', fn(): array => array_map(
                        static fn(array $field): mixed => $field['initial'],
                        $this->fields($form, $scope['new'], fresh: true),
                    ));
                }
            },
            2048,
        );
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if ($this->isCollection($form) && $this->inSchema($form)) {
            /** @var array<string, mixed> $rowAttributes */
            $rowAttributes = $view->vars['row_attr'];
            $rowAttributes['data-collaborate-collection'] = $this->path($form);
            $view->vars['row_attr'] = $rowAttributes;
            // Every stock form theme renders the widget's `attr` and each entry's `row_attr`: the scripts find the
            // entries, and the element a peer's entry is added to, by these attributes whatever the theme.
            /** @var array<string, mixed> $attributes */
            $attributes = $view->vars['attr'];
            $attributes['data-collaborate-entries'] = $this->path($form);
            /** @var string $placeholder CollectionType's option, `__name__` unless set. */
            $placeholder = $form->getConfig()->getOption('prototype_name', '__name__');
            $attributes['data-collaborate-placeholder'] = $placeholder;
            $view->vars['attr'] = $attributes;
            $entries = $view->children;
            if (($view->vars['prototype'] ?? null) instanceof FormView) {
                $entries[] = $view->vars['prototype'];
            }
            foreach ($entries as $entry) {
                self::addRowAttribute($entry, 'data-collaborate-entry');
            }
        }
        $sharing = $this->sharing($form);
        if (null === $sharing) {
            return;
        }
        ['user' => $user, 'request' => $request, 'context' => $context] = $sharing;
        $scope = $this->scope($form, $sharing);
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

            return;
        }
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];
        $attributes['data-collaborate'] = json_encode(
            [
                'grant' => $grant,
                'url' => substr($this->urls->generate('collaborate_draft', ['action' => 'join']), 0, -4) . '__action__',
                'csrf' => (string) $this->csrf->getToken('collaborate-draft'),
                'messages' => array_map(fn(string $message): string => $this->translator->trans(
                    $message,
                    [],
                    'collaborate',
                ), self::MESSAGES),
                'isNew' => $scope['new'],
                'editUrl' => $context->editUrl(),
                'invitation' => $scope['new']
                    ? $request->getPathInfo() . '?' . http_build_query(array_replace($request->query->all(), [
                        'draft' => $scope['draft'],
                    ]))
                    : null,
                'config' => $this->client,
            ],
            \JSON_THROW_ON_ERROR,
        );
        $view->vars['attr'] = $attributes;
        self::markRows($view);
        if ($scope['new']) {
            /** @var string $action */
            $action = $view->vars['action'];
            $action = '' !== $action ? $action : $request->getRequestUri();
            $parts = explode('?', $action, 2);
            $query = [];
            parse_str($parts[1] ?? '', $query);
            // A malformed key in the URL is drawn again on every request: post the one this grant names.
            if (($query['draft'] ?? null) !== $scope['draft']) {
                $query['draft'] = $scope['draft'];
                $view->vars['action'] = $parts[0] . '?' . http_build_query($query);
            }
        }
    }

    /**
     * Marks the row of every field below `$view`, collection prototypes included, with `data-collaborate-row`: a
     * peer's field mark outlines that row, and a focused widget names the field of its row, whatever the theme.
     */
    private static function markRows(FormView $view): void
    {
        $children = $view->children;
        if (($view->vars['prototype'] ?? null) instanceof FormView) {
            $children[] = $view->vars['prototype'];
        }
        foreach ($children as $child) {
            self::addRowAttribute($child, 'data-collaborate-row');
            self::markRows($child);
        }
    }

    /** Adds an empty attribute to the row of `$view`, keeping the attributes already there. */
    private static function addRowAttribute(FormView $view, string $name): void
    {
        /** @var array<string, mixed> $rowAttributes */
        $rowAttributes = $view->vars['row_attr'] ?? [];
        $rowAttributes[$name] ??= '';
        $view->vars['row_attr'] = $rowAttributes;
    }

    /** The signed-in user when they hold the collaboration role and can collaborate. */
    private function user(): ?UserInterface
    {
        $user = $this->security->getUser();
        if (null === $user || null === $this->collaborators->id($user) || !$this->security->isGranted($this->role)) {
            return null;
        }

        return $user;
    }

    /**
     * Whether a form of the page is part of the shared schema: its root form shares a draft, and neither it nor
     * an ancestor is left out of the schema {@see fields()} describes.
     */
    private function inSchema(FormInterface $form): bool
    {
        for ($parent = $form->getParent(); null !== $parent; $parent = $form->getParent()) {
            if ($this->excluded($form)) {
                return false;
            }
            $form = $parent;
        }

        return null !== $this->sharing($form);
    }

    /** A form whose value, and its children's, never enters a draft. */
    private function excluded(FormInterface $form): bool
    {
        $config = $form->getConfig();

        return (
            !$config->getMapped()
            || $config->getDisabled()
            || $this->sensitive($form->getName())
            || $this->uneditable($this->types($form))
        );
    }

    /**
     * Who shares the draft of the root form `$form`, its record, the page's context, the main request and the
     * permission the draft needs; null when the form shares none.
     *
     * @return Sharing|null
     */
    private function sharing(FormInterface $form): ?array
    {
        if (!$this->access->enabled || null !== $form->getParent()) {
            return null;
        }
        $context = $this->context;
        $user = $this->user();
        $record = $context?->current();
        $request = $this->requests->getMainRequest();
        if (null === $user || null === $record || null === $request || $form->getData() !== $record['entity']) {
            return null;
        }
        $permission = $record['new'] ? DraftAccessCheckerInterface::CREATE : DraftAccessCheckerInterface::UPDATE;
        if (!$this->checker->canEdit($user, $permission, $record['class'])) {
            return null;
        }

        return [
            'user' => $user,
            'record' => $record,
            'context' => $context,
            'request' => $request,
            'permission' => $permission,
        ];
    }

    /**
     * @param Sharing $sharing
     *
     * @return Scope
     */
    private function scope(FormInterface $form, array $sharing): array
    {
        ['record' => $record, 'request' => $request, 'permission' => $permission] = $sharing;
        $new = $record['new'];
        $entityClass = $record['class'];
        $draft = null;
        if ($new) {
            $draft = $record['draft'];
            if (null === $draft || 1 !== preg_match('/^[a-f0-9]{32}$/D', $draft)) {
                $drawn = $request->attributes->get('_collaborate_new_draft');
                if (!\is_string($drawn)) {
                    $drawn = bin2hex(random_bytes(16));
                    $request->attributes->set('_collaborate_new_draft', $drawn);
                }
                $draft = $drawn;
            }
        }
        $identity = null === $draft ? $record['id'] ?? '' : 'new:' . $draft;

        /** @var array{form: FormInterface, scope: Scope}|null $submitted */
        $submitted = $request->attributes->get('_collaborate_form_scope');
        if (null !== $submitted && $submitted['form'] === $form) {
            return $submitted['scope'];
        }

        return [
            'id' => $this->access->draftId($entityClass, $identity),
            'entity' => $entityClass,
            'permission' => $permission,
            'new' => $new,
            'draft' => $draft,
            'requestedAt' => $this->requestedAt($request),
            'fields' => $this->fields($form, $new),
        ];
    }

    /** When the page's request started: a grant's conflicts compare the draft with the record it read then. */
    private function requestedAt(Request $request): float
    {
        $time = $request->server->get('REQUEST_TIME_FLOAT');

        return is_numeric($time) ? (float) $time : microtime(true);
    }

    /** @return array<string, SchemaField> */
    private function fields(FormInterface $form, bool $new, string $prefix = '', bool $fresh = false): array
    {
        $fields = [];
        $name = '' === $prefix ? $form->getName() : $prefix;
        foreach ($form as $child) {
            if ($this->excluded($child)) {
                continue;
            }
            $config = $child->getConfig();
            $types = $this->types($child);
            $path = $name . '[' . $child->getName() . ']';
            if (\in_array('collection', $types, true)) {
                $prototype = $config->getAttribute('prototype');
                // Entries store their order in a position child; without one, the record reloads in
                // database order whatever order was submitted.
                $ordered =
                    $prototype instanceof FormInterface && $prototype->has($this->positionField)
                    || array_any($child->all(), fn(FormInterface $entry): bool => $entry->has($this->positionField));
                $unordered = $ordered ? [] : ['unordered' => true];
                $keys = [];
                foreach ($child as $key => $entry) {
                    $keys[] = $key;
                    $fields[$path . '[@' . $key . ']'] =
                        [
                            'type' => 'member',
                            'initial' => [
                                'alive' => true,
                                'order' => \count($keys),
                                'recordId' => $this->recordId($entry->getData()),
                            ],
                        ] + $unordered;
                }
                $fields[$path . '[__collection__]'] = ['type' => 'collection', 'initial' => $keys] + $unordered;
                if ($prototype instanceof FormInterface) {
                    $fields[$path . '[__member__]'] =
                        [
                            'type' => 'member',
                            'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
                        ] + $unordered;
                    $fields += $prototype->getConfig()->getCompound()
                        ? $this->fields($prototype, $new, $path . '[__name__]')
                        : $this->describe($prototype, $path . '[__name__]', $new, null, $fresh);
                }
            }
            if ($config->getCompound() && !\in_array('choice', $types, true)) {
                $fields += $this->fields($child, $new, $path, $fresh);
                continue;
            }
            $fields += $this->describe($child, $path, $new, $form, $fresh);
        }

        return $fields;
    }

    /** @return array<string, SchemaField> */
    private function describe(FormInterface $child, string $path, bool $new, ?FormInterface $form, bool $fresh): array
    {
        $config = $child->getConfig();
        $types = $this->types($child);
        if ($this->uneditable($types)) {
            return [];
        }
        $fields = [];
        if ($config->getOption('multiple', false)) {
            $path .= '[]';
        }
        $value = $child->getViewData();
        $owner = $form?->getData();
        $propertyPath = $child->getPropertyPath();
        if ($fresh && null !== $form && (\is_object($owner) || \is_array($owner)) && null !== $propertyPath) {
            // Compound children address the parent's transformed view data.
            foreach ($form->getConfig()->getModelTransformers() as $transformer) {
                $owner = $transformer->transform($owner);
            }
            foreach ($form->getConfig()->getViewTransformers() as $transformer) {
                $owner = $transformer->transform($owner);
            }
            /** @var array<array-key, mixed>|object $owner A compound form holds an object or an array. */
            if (isset($this->lists[$form]) && \is_array($owner) && array_is_list($owner)) {
                // SUBMIT stored the entries as a list; the stable keys name the children only, which hold
                // the entries it was built from.
                $owner = array_map(static fn(FormInterface $entry): mixed => $entry->getData(), $form->all());
            }
            // EasyAdmin maps an association itself to its inner autocomplete control; [autocomplete] is not a
            // property of the selected entity.
            $value = $form->getConfig()->getDataMapper() instanceof CrudAutocompleteType
                ? $owner
                : PropertyAccess::createPropertyAccessor()->getValue($owner, $propertyPath);
            foreach ($config->getModelTransformers() as $transformer) {
                $value = $transformer->transform($value);
            }
            foreach ($config->getViewTransformers() as $transformer) {
                $value = $transformer->transform($value);
            }
        }
        if (!\is_scalar($value) && null !== $value && !\is_array($value)) {
            return [];
        }
        $text = [] !== array_intersect($types, self::TEXT);
        $key = $path;
        $owner = $form?->getData();
        $ownerClass = \is_object($owner) ? $owner::class : $form?->getConfig()->getDataClass();
        if (null !== $ownerClass && class_exists($ownerClass)) {
            $key = $this->keys->key($ownerClass, $child->getName(), $path, $new);
            if ($fresh && $this->keys->savedElsewhere($ownerClass, $child->getName(), $new)) {
                $value = $child->getViewData();
            }
        }
        $kind = $text ? 'text' : 'value';
        // A text control shows a numeric property as a string; the post-save path reads the property itself.
        if ($text && (\is_int($value) || \is_float($value))) {
            $value = (string) $value;
        }
        if ($text && \is_string($value)) {
            // A text control's value reads LF; the draft models what the control shows.
            $value = str_replace(["\r\n", "\r"], "\n", $value);
        }
        if ([] !== array_intersect($types, $this->richText)) {
            $kind = 'rich';
        }
        $fields[$key] = [
            'type' => $kind,
            'initial' => $value ?? '',
            'formName' => $path,
        ];
        if (\in_array($kind, ['text', 'rich'], true)) {
            $reduced = null !== $this->schemaOption && true === $config->getOption($this->schemaOption, false);
            $fields[$key]['schema'] = $this->editor() . ':collaborate-2:' . $kind . ':' . ($reduced ? 'basic' : 'full');
        }
        if ('rich' === $kind) {
            $fields[$key . '[__mode__]'] = [
                'type' => 'value',
                'initial' => 'wysiwyg',
                'formName' => $path . '[__mode__]',
            ];
        }

        return $fields;
    }

    /** Operations recorded by one CKEditor release do not replay into another: the pinned version is part of the schema. */
    private function editor(): string
    {
        $version = $this->editorVersion ?? $this->importMap?->findRootImportMapEntry('ckeditor5')?->version;
        if (null === $version) {
            throw new \LogicException(
                'Set collaborate.drafts.rich_text.ckeditor_version, or pin a ckeditor5 version in importmap.php.',
            );
        }

        return 'ckeditor5-' . $version;
    }

    private function sensitive(string $name): bool
    {
        return \in_array(strtolower(str_replace(['_', '-'], '', $name)), $this->sensitive, true);
    }

    private function isCollection(FormInterface $form): bool
    {
        return \in_array('collection', $this->types($form), true);
    }

    /** @return list<string> The block prefixes of the form's type and its parents. */
    private function types(FormInterface $form): array
    {
        $types = [];
        $type = $form->getConfig()->getType();
        do {
            $types[] = $type->getBlockPrefix();
        } while (null !== ($type = $type->getParent()));

        return $types;
    }

    /** @param list<string> $types */
    private function uneditable(array $types): bool
    {
        return [] !== array_intersect($types, self::UNEDITABLE);
    }

    private function path(FormInterface $form): string
    {
        $parts = [$form->getName()];
        for ($parent = $form->getParent(); null !== $parent; $parent = $parent->getParent()) {
            array_unshift($parts, $parent->getName());
        }

        return (
            array_shift($parts) . implode('', array_map(static fn(string $part): string => '[' . $part . ']', $parts))
        );
    }

    /** A collection entry's record: its Doctrine identifier, or the public `id` of an unmapped object. */
    private function recordId(mixed $item): ?string
    {
        if (!\is_object($item)) {
            return null;
        }
        $id = $this->identifier->value($item) ?? get_object_vars($item)['id'] ?? null;

        return \is_int($id) || \is_string($id) || $id instanceof \Stringable ? (string) $id : null;
    }

    private function memberKey(string $id): string
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,79}$/D', $id) ? 'i' . $id : 'h' . hash('sha256', $id);
    }

    /**
     * @param FormRecord $record
     *
     * @return array<string, array<string, string>>
     */
    private function aliases(array $record): array
    {
        /** @var array<string, array<string, string>> */
        return $this->draftLookup($record, '_collaborate_aliases', $this->journal->aliases(...));
    }

    /**
     * @param FormRecord $record
     *
     * @return array<string, list<string>>
     */
    private function listKeys(array $record): array
    {
        /** @var array<string, list<string>> */
        return $this->draftLookup($record, '_collaborate_list_keys', $this->journal->listKeys(...));
    }

    /** What the edited record's draft says about its collections, read once per request; a New page has no record.
     *
     * @param FormRecord                                                $record
     * @param \Closure(string): array<string, array<array-key, string>> $read
     *
     * @return array<string, array<array-key, string>>
     */
    private function draftLookup(array $record, string $key, \Closure $read): array
    {
        $request = $this->requests->getMainRequest();
        if ($record['new'] || null === $request) {
            return [];
        }
        if (!$request->attributes->has($key)) {
            $request->attributes->set($key, $read($this->access->draftId($record['class'], (string) $record['id'])));
        }

        /** @var array<string, array<array-key, string>> */
        return $request->attributes->get($key);
    }
}
