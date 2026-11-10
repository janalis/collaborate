<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FieldDefinitions;
use Janalis\Collaborate\Draft\FormDraft;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Shares Drupal's content entity forms as drafts: the field schema of the form Form API built (each control by its
 * `name`), the `data-collaborate` attribute on the form and `data-collaborate-row` on each item, the locked save a
 * signed submission begins when it validates and completes once the entity is saved, and the configuration a
 * page's refresh asks for.
 *
 * A draft's class is `<entity type>:<bundle>`; a record's id its entity's. A multi-value field's items are a
 * collection's entries, keyed by {@see ItemKeys}.
 *
 * @phpstan-import-type Scope from DraftAccess
 * @phpstan-import-type SchemaField from \Janalis\Collaborate\Draft\DraftJournal
 * @phpstan-import-type Configuration from FormDraft
 *
 * @phpstan-type Sharing array{user: UserInterface, entity: ContentEntityInterface, request: Request, record: array{entity: object, class: string, new: bool, id: string|null, draft: string|null}, permission: 'create'|'update'}
 */
final class EntityForms
{
    /** The request attribute the page's form leaves its configuration under, for a refresh to answer. */
    public const string REFRESH = '_collaborate_refresh';

    /** The form state value marking the build of a record's form, describing it for a save. */
    private const string RECORD = 'collaborate_record';

    /** Form API's controls a draft edits as plain text. */
    private const array TEXT = ['textfield', 'textarea', 'email', 'url', 'tel', 'search', 'machine_name'];

    /** Form API's controls a draft edits by value. */
    private const array VALUE = [
        'number',
        'select',
        'checkbox',
        'radio',
        'date',
        'datetime',
        'time',
        'range',
        'color',
        'entity_autocomplete',
        'language_select',
        'weight',
    ];

    /** Form API's own values, never a field of the entity. */
    private const array INTERNAL = ['form_build_id', 'form_token', 'form_id', 'op', 'changed'];

    /** An id no route requirement refuses, replaced by `__record__` in the edit URL. */
    private const int PLACEHOLDER = 2_147_483_646;

    /** @var list<string> */
    private readonly array $sensitive;

    /** The entity form a description is of: the prototypes of its paragraphs are built on it. */
    private ?ContentEntityFormInterface $form = null;

    /** @var list<string> */
    private readonly array $rich;

    /**
     * @param array{rich_text?: array{block_prefixes?: list<string>, ...}, sensitive_fields?: list<string>, ...} $options
     *   the `collaborate.drafts` configuration: rich text is shared when `text_format` is a block prefix
     */
    public function __construct(
        private readonly FormDraft $drafts,
        private readonly DraftAccess $access,
        private readonly CurrentUserInterface $users,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly DraftAccessCheckerInterface $checker,
        private readonly DraftSave $save,
        private readonly RequestStack $requests,
        private readonly CsrfTokenGenerator $tokens,
        private readonly EntityTypeManagerInterface $entities,
        private readonly FormBuilderInterface $builder,
        array $options = [],
        private readonly string $role = 'use collaborate',
        private readonly string $editorVersion = 'drupal',
    ) {
        $this->rich = $options['rich_text']['block_prefixes'] ?? [];
        $this->sensitive = array_map(static fn(string $name): string => strtolower(str_replace(
            ['_', '-'],
            '',
            $name,
        )), $options['sensitive_fields'] ?? CollaborateBundle::SENSITIVE_FIELDS);
    }

    /** Hooks a content entity's add or edit form: its handlers, and the description once Form API built it. */
    public function alter(array &$form, FormStateInterface $state): void
    {
        $object = $state->getFormObject();
        if (!$this->access->enabled || !$object instanceof ContentEntityFormInterface) {
            return;
        }
        if (!\in_array($object->getOperation(), ['default', 'edit', 'add'], true)) {
            return;
        }
        // First of the process callbacks: the items the page submitted under their keys go back to their deltas.
        $form['#process'] ??= [];
        array_unshift($form['#process'], [ItemKeys::class, 'onProcess']);
        $form['#after_build'][] = [self::class, 'onAfterBuild'];
        // First of the validators: a draft that changed meanwhile sends the editor back before the others run.
        array_unshift($form['#validate'], [self::class, 'onValidate']);
        foreach (['submit', 'publish', 'unpublish'] as $action) {
            if (isset($form['actions'][$action]['#submit'])) {
                $form['actions'][$action]['#submit'][] = [self::class, 'onSubmit'];
            }
        }
        // The bar's Save stands for the form's own, not for the first button of the form (an image's Upload).
        if (isset($form['actions']['submit'])) {
            $form['actions']['submit']['#attributes']['data-collaborate-submitter'] = '';
        }
    }

    /**
     * Form API's callbacks: a form's state is serialized with the form, the service it reaches is not.
     *
     * @return array<array-key, mixed>
     */
    public static function onAfterBuild(array $form, FormStateInterface $state): array
    {
        return self::service()->afterBuild($form, $state);
    }

    public static function onValidate(array &$form, FormStateInterface $state): void
    {
        self::service()->validate($form, $state);
    }

    public static function onSubmit(array &$form, FormStateInterface $state): void
    {
        self::service()->submitted($form, $state);
    }

    private static function service(): self
    {
        $service = \Drupal::service(self::class);
        \assert($service instanceof self);

        return $service;
    }

    /** @return array<array-key, mixed> */
    public function afterBuild(array $form, FormStateInterface $state): array
    {
        if (true === $state->get(self::RECORD)) {
            ItemKeys::rename($form);

            return $form;
        }
        $sharing = $this->sharing($state);
        if (null === $sharing) {
            return $form;
        }
        $object = $state->getFormObject();
        $this->form = $object instanceof ContentEntityFormInterface ? $object : null;
        ItemKeys::rename($form);
        $scope = $this->scope($form, $sharing);
        $state->set('collaborate_scope', $scope);
        $configuration = $this->drafts->configuration(
            $scope,
            $sharing['user'],
            $sharing['request'],
            $this->editUrl($sharing['entity']),
        );
        if (null === $configuration) {
            return $form;
        }
        $form['#attributes']['data-collaborate'] = json_encode($configuration, \JSON_THROW_ON_ERROR);
        self::markRows($form);
        if ($scope['new'] && \is_string($scope['draft'])) {
            $action = \is_string($form['#action'] ?? null) ? $form['#action'] : $sharing['request']->getRequestUri();
            $form['#action'] = FormDraft::action($action, $scope['draft']) ?? $action;
        }
        $sharing['request']->attributes->set(self::REFRESH, [
            'configuration' => $configuration,
            // The page renders its token as a placeholder Drupal fills in last: the refresh answers the token itself.
            'tokens' => isset($form['form_token']) && \is_string($form['#token'] ?? null)
                ? ['form_token' => $this->tokens->get($form['#token'])]
                : [],
        ]);
        $form['#attached']['library'][] = 'collaborate/drupal';
        // Plain text fields follow the draft on CKEditor 5's model: its build, whether or not a field edits rich text.
        $form['#attached']['library'][] = 'core/ckeditor5';

        return $form;
    }

    /** Begins the locked save of a signed submission, before the entity is built from it. */
    public function validate(array &$form, FormStateInterface $state): void
    {
        $sharing = $this->sharing($state);
        $scope = $state->get('collaborate_scope');
        if (null === $sharing || !\is_array($scope) || $state->getErrors()) {
            return;
        }
        /** @var Scope $scope */
        $object = $state->getFormObject();
        \assert($object instanceof ContentEntityFormInterface);
        $this->form = $object;
        // The form as submitted: what its controls hold, its items under their keys.
        $submitted = $this->fields($form, fresh: true);
        // The save compares the record with what the draft last saw of it: the form the record builds now.
        $record = $this->record($object);
        $this->drafts->begin(
            $sharing['request'],
            $sharing['user'],
            ['fields' => $record] + $scope,
            fn(): array => $this->savedValues($record, $submitted, $object->getEntity()),
        );
    }

    /** The entity is saved: the draft's checkpoint waits for the response. */
    public function submitted(array &$form, FormStateInterface $state): void
    {
        $object = $state->getFormObject();
        if ($object instanceof ContentEntityFormInterface) {
            $this->save->completed($object->getEntity());
        }
    }

    /**
     * The fields of the record as stored, as a page opening it now would describe them: the submitted form's own
     * defaults are what its last rebuild ("Add another item") was submitted with.
     *
     * @return array<string, SchemaField>
     */
    private function record(ContentEntityFormInterface $object): array
    {
        $entity = $object->getEntity();
        $storage = $this->entities->getStorage($entity->getEntityTypeId());
        $bundle = $entity->getEntityType()->getKey('bundle');
        // The entities it holds as revisions (paragraphs) as stored: the submission changed those it loaded.
        foreach ($entity->getFieldDefinitions() as $definition) {
            $target = $definition->getSetting('target_type');
            if ('entity_reference_revisions' === $definition->getType() && \is_string($target)) {
                $this->entities->getStorage($target)->resetCache();
            }
        }
        $fresh = $entity->isNew()
            ? $storage->create(\is_string($bundle) && '' !== $bundle ? [$bundle => $entity->bundle()] : [])
            : $storage->loadUnchanged($entity->id());
        if (!$fresh instanceof ContentEntityInterface) {
            return [];
        }
        $form = $this->entities->getFormObject($entity->getEntityTypeId(), $object->getOperation());
        $form->setEntity($fresh);
        $state = (new FormState())->setFormObject($form)->set(self::RECORD, true);
        // Its base form's alterations too (`hook_form_node_form_alter`).
        $id = $this->builder->getFormId($form, $state);
        $built = $this->builder->retrieveForm($id, $state);
        $this->builder->prepareForm($id, $built, $state);
        $built = $this->builder->doBuildForm($id, $built, $state);

        return $this->fields($built);
    }

    /**
     * The values the save recorded, by field: what the form submitted, as the schema types them, its items under
     * their keys. A multi-value field's items are those the entity holds once saved, in its order: Drupal leaves
     * an empty one out. A value the site derives on save (an alias, say) shows on the next page, and to the others
     * as the saved record's.
     *
     * @param array<string, SchemaField> $fields    the page's
     * @param array<string, SchemaField> $submitted the submitted form's, `initial` holding what it submitted
     *
     * @return array<string, mixed>
     */
    private function savedValues(array $fields, array $submitted, ContentEntityInterface $entity): array
    {
        $values = [];
        foreach ($fields + $submitted as $key => $field) {
            if (FieldDefinitions::prototype($key)) {
                continue;
            }
            $values[$key] = str_ends_with($key, '[__mode__]')
                ? $field['initial']
                : self::typed(($submitted[$key] ?? $field)['initial'], $field['initial']);
        }
        foreach ($values as $key => $value) {
            if ('collection' !== ($fields[$key] ?? $submitted[$key])['type'] || !str_ends_with($key, '[__collection__]')) {
                continue;
            }
            $path = substr($key, 0, -\strlen('[__collection__]'));
            $name = ItemKeys::segments($path);
            if (1 !== \count($name) || !$entity->hasField($name[0])) {
                continue;
            }
            $saved = ItemKeys::savedKeys($entity->get($name[0]));
            if (null === $saved) {
                continue;
            }
            $values[$key] = $saved;
            foreach ($values as $member => $state) {
                if (str_starts_with($member, $path . '[@') && 'member' === ($fields[$member] ?? $submitted[$member])['type']) {
                    $entry = substr($member, \strlen($path) + 2, -1);
                    $order = array_search($entry, $saved, true);
                    $values[$member] = ['alive' => false !== $order, 'order' => false === $order ? 0 : $order + 1, 'recordId' => null];
                }
            }
        }

        return $values;
    }

    /** A submitted value, typed as the record's: a checkbox's `1` is true, a select's `''` among none is null. */
    private static function typed(mixed $value, mixed $as): mixed
    {
        return match (true) {
            \is_bool($as) => (bool) $value,
            \is_array($as) => \is_array($value) ? array_values($value) : [],
            \is_string($as) => \is_scalar($value) ? (string) $value : '',
            \is_int($as) => is_numeric($value) ? (int) $value : $value,
            default => '' === $value ? null : $value,
        };
    }

    /** @return Sharing|null */
    private function sharing(FormStateInterface $state): ?array
    {
        $object = $state->getFormObject();
        $request = $this->requests->getMainRequest();
        $user = $this->users->getUser();
        if (
            !$this->access->enabled
            || !$object instanceof ContentEntityFormInterface
            || null === $request
            || null === $user
            || null === $this->collaborators->id($user)
            || !$this->users->isGranted($this->role)
        ) {
            return null;
        }
        $entity = $object->getEntity();
        $new = $entity->isNew();
        $class = $entity->getEntityTypeId() . ':' . $entity->bundle();
        $permission = $new ? DraftAccessCheckerInterface::CREATE : DraftAccessCheckerInterface::UPDATE;
        if (!$this->checker->canEdit($user, $permission, $class)) {
            return null;
        }
        $draft = $request->query->get('draft');

        return [
            'user' => $user,
            'entity' => $entity,
            'request' => $request,
            'permission' => $permission,
            'record' => [
                'entity' => $entity,
                'class' => $class,
                'new' => $new,
                'id' => $new ? null : (string) $entity->id(),
                'draft' => \is_string($draft) ? $draft : null,
            ],
        ];
    }

    /**
     * @param Sharing $sharing
     *
     * @return Scope
     */
    private function scope(array $form, array $sharing): array
    {
        /** @var Scope */
        return $this->drafts->scope(
            $sharing['record'],
            $sharing['permission'],
            $sharing['request'],
            $this->fields($form),
        );
    }

    /**
     * The controls of the form a draft shares, by their `name`: the text ones as text, CKEditor 5's as rich text,
     * the others by value.
     *
     * A multi-value field's keyed items are a collection's entries ({@see ItemKeys}).
     *
     * @param bool $fresh whether a field's `initial` is what the form was submitted with, not what it showed
     *
     * @return array<string, SchemaField>
     */
    private function fields(array $element, bool $rich = false, bool $fresh = false): array
    {
        $fields = [];
        foreach (Element::children($element) as $key) {
            $child = $element[$key];
            if (
                !\is_array($child)
                || false === ($child['#access'] ?? true)
                || true === ($child['#disabled'] ?? false)
                || \is_string($key) && str_starts_with($key, '_collaborate_')
            ) {
                continue;
            }
            if (true === ($child[ItemKeys::WIDGET] ?? false)) {
                $fields += $this->collection($child, $fresh);
                continue;
            }
            $type = \is_string($child['#type'] ?? null) ? $child['#type'] : null;
            $name = \is_string($child['#name'] ?? null) ? $child['#name'] : null;
            if ('text_format' === $type) {
                $fields += $this->fields(
                    $child,
                    \in_array('text_format', $this->rich, true) && self::editor($child),
                    $fresh,
                );
                continue;
            }
            if (null !== $name && null !== $type && !$this->excluded($name)) {
                // A formatted text's own value is its editor's; a summary beside it is a plain textarea.
                $fields += $this->describe(
                    $child,
                    $type,
                    $name,
                    $rich && 'value' === $key && 'textarea' === $type,
                    $fresh,
                );
            }
            $fields += $this->fields($child, $rich, $fresh);
        }

        return $fields;
    }

    /**
     * A keyed multi-value widget's items as a collection: a member per item in the order its weights give, the
     * items' fields under their keys, and the prototype of an item a peer adds, from the widget's empty row.
     *
     * @return array<string, SchemaField>
     */
    private function collection(array $widget, bool $fresh): array
    {
        $path = $widget['#attributes']['data-collaborate-collection'] ?? null;
        if (!\is_string($path)) {
            return [];
        }
        $entries = [];
        $empty = null;
        foreach (Element::children($widget) as $delta) {
            if (!\is_int($delta) || !\is_array($widget[$delta][ItemKeys::KEY] ?? null)) {
                continue;
            }
            $key = $widget[$delta][ItemKeys::KEY]['#value'] ?? '';
            if (!\is_string($key) || '' === $key) {
                $empty ??= [$delta, 't' . $delta];
                continue;
            }
            $weight = $widget[$delta]['_weight']['#value'] ?? $delta;
            $entries[] = [$key, is_numeric($weight) ? (int) $weight : $delta, $delta];
        }
        usort($entries, static fn(array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        $fields = [];
        $keys = [];
        foreach ($entries as [$key, , $delta]) {
            $keys[] = $key;
            $fields[$path . '[@' . $key . ']'] = [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => \count($keys), 'recordId' => null],
            ];
            $fields += self::unweighted($this->fields($widget[$delta], false, $fresh), $path, $key);
        }
        $fields[$path . '[__collection__]'] = ['type' => 'collection', 'initial' => $keys];
        $fields[$path . '[__member__]'] = [
            'type' => 'member',
            'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
        ];
        $paragraphs = $widget[ItemKeys::PARAGRAPHS] ?? null;
        if (\is_array($paragraphs)) {
            // A paragraph of any type a peer adds: each type's subform.
            /** @var array{types: list<string>, mode: string} $paragraphs */
            return $fields + $this->paragraphPrototypes($paragraphs, $path);
        }
        [$source, $from] = $empty ?? ([] === $entries ? [null, null] : [$entries[0][2], $entries[0][0]]);
        if (null === $source) {
            return $fields;
        }
        $prefix = $path . '[' . $from . ']';
        foreach (self::unweighted($this->fields($widget[$source]), $path, (string) $from) as $name => $field) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (null === $empty) {
                $field['initial'] = match (true) {
                    \is_string($field['initial']) => '',
                    \is_bool($field['initial']) => false,
                    \is_array($field['initial']) => [],
                    default => null,
                };
            }
            if (isset($field['formName'])) {
                $field['formName'] = $path . '[__name__]' . substr($field['formName'], \strlen($prefix));
            }
            $fields[$path . '[__name__]' . substr($name, \strlen($prefix))] = $field;
        }

        return $fields;
    }

    /**
     * The fields of a new paragraph of each type, under the placeholder of its key: its subform, as Paragraphs
     * builds it, on a form of its own.
     *
     * @param array{types: list<string>, mode: string} $paragraphs
     *
     * @return array<string, SchemaField>
     */
    private function paragraphPrototypes(array $paragraphs, string $path): array
    {
        $fields = [];
        $storage = $this->entities->getStorage('paragraph');
        foreach ($paragraphs['types'] as $type) {
            $paragraph = $storage->create(['type' => $type]);
            \assert($paragraph instanceof ContentEntityInterface);
            $state = new FormState();
            if (null !== $this->form) {
                $state->setFormObject($this->form);
            }
            $subform = ['#parents' => [...ItemKeys::segments($path), '__name__', 'subform'], '#array_parents' => [], '#tree' => true];
            EntityFormDisplay::collectRenderDisplay($paragraph, $paragraphs['mode'])->buildForm($paragraph, $subform, $state);
            $built = $this->builder->doBuildForm('collaborate_paragraph_' . $type, $subform, $state);
            ItemKeys::rename($built);
            $fields += $this->fields($built);
        }

        return $fields;
    }

    /**
     * An item's fields but its weight: the members' order is the items', which each page numbers their weights by.
     *
     * @param array<string, SchemaField> $fields
     *
     * @return array<string, SchemaField>
     */
    private static function unweighted(array $fields, string $path, string $key): array
    {
        unset($fields[$path . '[' . $key . '][_weight]']);

        return $fields;
    }

    /** @return array<string, SchemaField> */
    private function describe(array $control, string $type, string $name, bool $rich, bool $fresh = false): array
    {
        $value = $fresh ? $control['#value'] ?? null : $control['#default_value'] ?? null;
        if ('checkbox' === $type) {
            $value = (bool) $value;
        }
        if (\in_array($type, self::TEXT, true)) {
            $kind = $rich ? 'rich' : 'text';
            $fields = [$name => [
                'type' => $kind,
                'initial' => \is_scalar($value) ? (string) $value : '',
                'formName' => $name,
                'schema' => 'ckeditor5-' . $this->editorVersion . ':collaborate-2:' . $kind . ':full',
            ]];
            if ($rich) {
                $fields[$name . '[__mode__]'] = [
                    'type' => 'value',
                    'initial' => 'wysiwyg',
                    'formName' => $name . '[__mode__]',
                ];
            }

            return $fields;
        }
        if ('radio' === $type || !\in_array($type, self::VALUE, true)) {
            // A radio is its group's: the radios element names the value.
            return (
                'radios' === $type
                    ? [$name => ['type' => 'value', 'initial' => self::value($value), 'formName' => $name]]
                    : []
            );
        }
        $multiple = 'select' === $type && true === ($control['#multiple'] ?? false);
        $key = $multiple ? $name . '[]' : $name;

        return [$key => [
            'type' => 'value',
            'initial' => match (true) {
                $multiple => array_values((array) $value),
                // An entity autocomplete shows its entities as "label (id), label (id)", none as empty text.
                'entity_autocomplete' === $type => \is_array($value) || $value instanceof EntityInterface
                    ? EntityAutocomplete::getEntityLabels(array_filter(
                        \is_array($value) ? $value : [$value],
                        static fn(mixed $entity): bool => $entity instanceof EntityInterface,
                    ))
                    : (\is_scalar($value) ? (string) $value : ''),
                // A weight is a number: the form submits its text.
                'weight' === $type => is_numeric($value) ? (int) $value : 0,
                default => self::value($value),
            },
            'formName' => $key,
        ]];
    }

    private static function value(mixed $value): mixed
    {
        if (\is_scalar($value) || null === $value) {
            return $value;
        }
        if (\is_array($value)) {
            return array_values(array_filter($value, static fn(mixed $item): bool => \is_scalar($item)));
        }
        return null;
    }

    private function excluded(string $name): bool
    {
        $base = strtolower(str_replace(['_', '-'], '', (string) strtok($name, '[')));

        return \in_array((string) strtok($name, '['), self::INTERNAL, true) || \in_array($base, $this->sensitive, true);
    }

    /** Whether a formatted text's format is edited with CKEditor 5. */
    private static function editor(array $element): bool
    {
        $format = $element['format']['format']['#default_value'] ?? $element['#format'] ?? null;
        if (!\is_string($format)) {
            return false;
        }
        $entities = \Drupal::entityTypeManager();
        // A site without the Editor module edits every format in a plain textarea.
        if (!$entities->hasDefinition('editor')) {
            return false;
        }
        $editor = $entities->getStorage('editor')->load($format);

        return null !== $editor && 'ckeditor5' === $editor->get('editor');
    }

    /** Marks every item of the form, the element a peer's field mark outlines. */
    private static function markRows(array &$element): void
    {
        foreach (Element::children($element) as $key) {
            if (!\is_array($element[$key])) {
                continue;
            }
            if (
                isset($element[$key]['#name'])
                || isset($element[$key]['#type']) && 'container' !== $element[$key]['#type']
            ) {
                $element[$key]['#wrapper_attributes']['data-collaborate-row'] = '';
            }
            self::markRows($element[$key]);
        }
    }

    /** The URL of an entity's edit page, `__record__` standing for its id. */
    private function editUrl(ContentEntityInterface $entity): string
    {
        $type = $entity->getEntityTypeId();
        if (!$entity->getEntityType()->hasLinkTemplate('edit-form')) {
            return '';
        }
        $url = Url::fromRoute('entity.' . $type . '.edit_form', [$type => self::PLACEHOLDER])->toString();

        return str_replace((string) self::PLACEHOLDER, '__record__', \is_string($url) ? $url : $url->getGeneratedUrl());
    }
}
