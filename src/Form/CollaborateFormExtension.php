<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Form;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FieldKeyResolver;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Janalis\Collaborate\Draft\FormDraft;
use Janalis\Collaborate\Draft\RecordIdentifierInterface;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyPathInterface;
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
    public const array MESSAGES = FormDraft::MESSAGES;

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

    /** @var list<string> */
    private array $sensitive;

    /** The draft of the root form, whatever builds it: its scope, its configuration, its locked save. */
    private FormDraft $drafts;

    /**
     * @param Options $options the `collaborate.drafts` configuration
     * @param list<class-string> $ownerMappers the data mappers of compound fields that map their parent's property
     *   to an inner control themselves (EasyAdmin's autocomplete): their children read the parent's value
     */
    public function __construct(
        private readonly DraftAccess $access,
        private readonly DraftJournal $journal,
        DraftSave $save,
        private readonly ?FormContextInterface $context,
        private readonly RequestStack $requests,
        private readonly CurrentUserInterface $security,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly DraftAccessCheckerInterface $checker,
        UrlGeneratorInterface $urls,
        CsrfTokenManagerInterface $csrf,
        TranslatorInterface $translator,
        private readonly RecordIdentifierInterface $identifier = new RecordIdentifier(),
        private readonly FieldKeyResolverInterface $keys = new FieldKeyResolver(),
        private readonly ?ImportMapConfigReader $importMap = null,
        private readonly string $role = 'ROLE_ADMIN',
        array $options = [],
        LoggerInterface $logger = new NullLogger(),
        private readonly array $ownerMappers = [],
    ) {
        $this->lists = new \WeakMap();
        $this->richText = $options['rich_text']['block_prefixes'] ?? [];
        $this->schemaOption = $options['rich_text']['schema_option'] ?? null;
        $this->editorVersion = $options['rich_text']['ckeditor_version'] ?? null;
        $this->positionField = $options['position_field'] ?? 'position';
        $this->sensitive = array_map(static fn(string $name): string => strtolower(str_replace(
            ['_', '-'],
            '',
            $name,
        )), $options['sensitive_fields'] ?? CollaborateBundle::SENSITIVE_FIELDS);
        $this->drafts = new FormDraft($access, $save, $collaborators, $urls, $csrf, $translator, $logger, $options);
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
                $data = self::entries($event->getData());
                if (null === $data) {
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
                foreach ($data as $key => $_) {
                    $recordId = $this->recordId($data[$key]);
                    $stable = null !== $recordId
                        ? $aliases[$path][$recordId] ?? $this->memberKey($recordId)
                        : $listKeys[$key] ?? (string) $key;
                    if (ctype_digit($stable)) {
                        $stable = 'p' . $stable;
                    }
                    $keyed[$stable] = $data[$key];
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
                $data = self::arrayOrNull($event->getData());
                if (!$this->isCollection($form) || null === $data) {
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
                $data = self::arrayOrNull($event->getData());
                if (!isset($this->lists[$form]) || null === $data) {
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
                $this->drafts->begin($request, $user, $scope, fn(): array => array_map(
                    static fn(array $field): mixed => $field['initial'],
                    $this->fields($form, $scope['new'], fresh: true),
                ));
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
            foreach (self::childrenOf($view) as $entry) {
                self::addRowAttribute($entry, 'data-collaborate-entry');
            }
        }
        $sharing = $this->sharing($form);
        if (null === $sharing) {
            return;
        }
        ['user' => $user, 'request' => $request, 'context' => $context] = $sharing;
        $scope = $this->scope($form, $sharing);
        $configuration = $this->drafts->configuration($scope, $user, $request, $context->editUrl());
        if (null === $configuration) {
            return;
        }
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];
        $attributes['data-collaborate'] = json_encode($configuration, \JSON_THROW_ON_ERROR);
        $view->vars['attr'] = $attributes;
        self::markRows($view);
        // Only a new record's scope has a draft of its own.
        if (null !== $scope['draft']) {
            /** @var string $action */
            $action = $view->vars['action'];
            $view->vars['action'] =
                FormDraft::action('' !== $action ? $action : $request->getRequestUri(), $scope['draft'])
                ?? $view->vars['action'];
        }
    }

    /**
     * Marks the row of every field below `$view`, collection prototypes included, with `data-collaborate-row`: a
     * peer's field mark outlines that row, and a focused widget names the field of its row, whatever the theme.
     */
    private static function markRows(FormView $view): void
    {
        foreach (self::childrenOf($view) as $child) {
            self::addRowAttribute($child, 'data-collaborate-row');
            self::markRows($child);
        }
    }

    /**
     * The children of `$view`, its collection prototype included.
     *
     * @return array<array-key, FormView>
     */
    private static function childrenOf(FormView $view): array
    {
        $children = $view->children;
        if (($view->vars['prototype'] ?? null) instanceof FormView) {
            $children[] = $view->vars['prototype'];
        }

        return $children;
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
        /** @var array{form: FormInterface, scope: Scope}|null $submitted */
        $submitted = $request->attributes->get('_collaborate_form_scope');
        if (null !== $submitted && $submitted['form'] === $form) {
            return $submitted['scope'];
        }

        return $this->drafts->scope($record, $permission, $request, $this->fields($form, $record['new']));
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
                $prototype = self::formOrNull($config->getAttribute('prototype'));
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
        if ($config->getOption('multiple', false)) {
            $path .= '[]';
        }

        return $this->describeValue($child, $path, $new, $form, $types, $this->viewValue($child, $form, $fresh));
    }

    /**
     * @param list<string> $types
     *
     * @return array<string, SchemaField>
     */
    private function describeValue(
        FormInterface $child,
        string $path,
        bool $new,
        ?FormInterface $form,
        array $types,
        mixed $value,
    ): array {
        if (!\is_scalar($value) && null !== $value && !\is_array($value)) {
            return [];
        }
        $config = $child->getConfig();
        $fields = [];
        $text = [] !== array_intersect($types, self::TEXT);
        $key = $path;
        $ownerClass = self::ownerClass($form?->getData(), $form);
        if (null !== $ownerClass && class_exists($ownerClass)) {
            $key = $this->keys->key($ownerClass, $child->getName(), $path, $new);
        }
        $kind = $text ? 'text' : 'value';
        if ([] !== array_intersect($types, $this->richText)) {
            $kind = 'rich';
        }
        $fields[$key] = [
            'type' => $kind,
            // After a save, the property the record holds, translated ones included: what the server
            // saved, which can differ from what was submitted (a slug it derives, say).
            'initial' => self::initial($text, $value),
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

    /** The value a child's control shows: on a fresh form, read again from its owner's current data. */
    private function viewValue(FormInterface $child, ?FormInterface $form, bool $fresh): mixed
    {
        $propertyPath = $child->getPropertyPath();

        return $fresh && null !== $form && null !== $propertyPath
            ? $this->freshValue($child, $form, $propertyPath, $form->getData())
            : $child->getViewData();
    }

    private function freshValue(
        FormInterface $child,
        FormInterface $form,
        PropertyPathInterface $propertyPath,
        mixed $owner,
    ): mixed {
        if (!\is_object($owner) && !\is_array($owner)) {
            return $child->getViewData();
        }
        $config = $form->getConfig();

        // Compound children address the parent's transformed view data.
        return $this->propertyValue(
            $child,
            $form,
            $propertyPath,
            self::transform([...$config->getModelTransformers(), ...$config->getViewTransformers()], $owner),
        );
    }

    private function propertyValue(
        FormInterface $child,
        FormInterface $form,
        PropertyPathInterface $propertyPath,
        mixed $owner,
    ): mixed {
        /** @var array<array-key, mixed>|object $owner A compound form holds an object or an array. */
        if (isset($this->lists[$form]) && \is_array($owner) && array_is_list($owner)) {
            // SUBMIT stored the entries as a list; the stable keys name the children only, which hold
            // the entries it was built from.
            $owner = array_map(static fn(FormInterface $entry): mixed => $entry->getData(), $form->all());
        }
        $config = $child->getConfig();

        // A bridge's mapper may map an association itself to its inner control (EasyAdmin's autocomplete):
        // [autocomplete] is not a property of the selected entity.
        $mapper = $form->getConfig()->getDataMapper();

        return self::transform(
            [...$config->getModelTransformers(), ...$config->getViewTransformers()],
            null !== $mapper && \in_array($mapper::class, $this->ownerMappers, true)
                ? $owner
                : PropertyAccess::createPropertyAccessor()->getValue($owner, $propertyPath),
        );
    }

    /** @param list<DataTransformerInterface> $transformers */
    private static function transform(array $transformers, mixed $value): mixed
    {
        return array_reduce(
            $transformers,
            static fn(mixed $value, DataTransformerInterface $transformer): mixed => $transformer->transform($value),
            $value,
        );
    }

    /** @return string|null The class of a form's data, or the class it declares. */
    private static function ownerClass(mixed $owner, ?FormInterface $form): ?string
    {
        return \is_object($owner) ? $owner::class : $form?->getConfig()->getDataClass();
    }

    /** A field's initial value, as its control shows it. */
    private static function initial(bool $text, mixed $value): mixed
    {
        // A text control shows a numeric property as a string; the post-save path reads the property itself.
        if ($text && (\is_int($value) || \is_float($value))) {
            return (string) $value;
        }
        if ($text && \is_string($value)) {
            // A text control's value reads LF; the draft models what the control shows.
            return str_replace(["\r\n", "\r"], "\n", $value);
        }

        return $value ?? '';
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

        return self::idString($this->identifier->value($item) ?? get_object_vars($item)['id'] ?? null);
    }

    private static function idString(mixed $id): ?string
    {
        return \is_int($id) || \is_string($id) || $id instanceof \Stringable ? (string) $id : null;
    }

    /** @return array<array-key, mixed>|Collection<array-key, mixed>|null A collection form's data, when it holds entries. */
    private static function entries(mixed $data): array|Collection|null
    {
        return \is_array($data) || $data instanceof Collection ? $data : null;
    }

    /** @return array<array-key, mixed>|null */
    private static function arrayOrNull(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    private static function formOrNull(mixed $value): ?FormInterface
    {
        return $value instanceof FormInterface ? $value : null;
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
