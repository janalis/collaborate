<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftSave;
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
 * A draft's class is `<entity type>:<bundle>`; a record's id its entity's. Fields Form API adds with "Add another
 * item" are shared once saved: an item added meanwhile is the page's own.
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
        $sharing = $this->sharing($state);
        if (null === $sharing) {
            return $form;
        }
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
        $this->drafts->begin($sharing['request'], $sharing['user'], $scope, static fn(): array => self::savedValues(
            $scope['fields'],
            $state->getUserInput(),
        ));
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
     * The values the save recorded, by field: what the form submitted, as the schema types them. A value the site
     * derives on save (an alias, say) shows on the next page, and to the others as the saved record's.
     *
     * @param array<string, SchemaField>   $fields
     * @param array<array-key, mixed>      $input
     *
     * @return array<string, mixed>
     */
    private static function savedValues(array $fields, array $input): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            $name = $field['formName'] ?? $key;
            if (str_ends_with($name, '[__mode__]')) {
                $values[$key] = $field['initial'];
                continue;
            }
            $parents = explode('[', str_replace(']', '', preg_replace('/\[\]$/', '', $name) ?? $name));
            $value = NestedArray::getValue($input, $parents, $exists);
            $values[$key] = match (true) {
                \is_bool($field['initial']) => (bool) $value,
                \is_array($field['initial']) => \is_array($value) ? array_values($value) : [],
                \is_string($field['initial']) => \is_scalar($value) ? (string) $value : '',
                default => $exists ? $value : $field['initial'],
            };
        }

        return $values;
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
     * @return array<string, SchemaField>
     */
    private function fields(array $element, bool $rich = false): array
    {
        $fields = [];
        foreach (Element::children($element) as $key) {
            $child = $element[$key];
            if (
                !\is_array($child)
                || false === ($child['#access'] ?? true)
                || true === ($child['#disabled'] ?? false)
            ) {
                continue;
            }
            $type = \is_string($child['#type'] ?? null) ? $child['#type'] : null;
            $name = \is_string($child['#name'] ?? null) ? $child['#name'] : null;
            if ('text_format' === $type) {
                $fields += $this->fields(
                    $child,
                    rich: \in_array('text_format', $this->rich, true) && self::editor($child),
                );
                continue;
            }
            if (null !== $name && null !== $type && !$this->excluded($name)) {
                // A formatted text's own value is its editor's; a summary beside it is a plain textarea.
                $fields += $this->describe($child, $type, $name, $rich && 'value' === $key && 'textarea' === $type);
            }
            $fields += $this->fields($child, $rich);
        }

        return $fields;
    }

    /** @return array<string, SchemaField> */
    private function describe(array $control, string $type, string $name, bool $rich): array
    {
        $value = $control['#default_value'] ?? null;
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
            'initial' => $multiple ? array_values((array) $value) : self::value($value),
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
        if ($value instanceof ContentEntityInterface) {
            // An entity autocomplete shows its entity as "label (id)".
            return $value->label() . ' (' . $value->id() . ')';
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
