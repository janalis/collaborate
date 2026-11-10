<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\file\Plugin\Field\FieldWidget\FileWidget;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Security\CurrentUserInterface;

/**
 * Keys the items of a multi-value field, so that a draft shares them as a collection's entries: Drupal numbers
 * them by their delta, which its rebuilds ("Add another item", Remove, a reorder) renumber.
 *
 * Each item carries its key in a hidden control, and its delta in another; the page names the item's controls
 * by its key (`field_tags[p0][target_id]`), and its submission is read back by the deltas the form was built
 * with before Form API reads it. The key rides on the field item (`_collaborate_key`, a value no column stores)
 * through Drupal's rebuilds. An item saved before takes the key the draft's last save recorded for its place,
 * else `p<delta>`; an item the page adds, `n…`; an empty row, none: the page names it `t<delta>`, and it is no
 * entry until typed in.
 *
 * Fields of unlimited cardinality only: the others render all their rows, which their deltas name.
 */
final class ItemKeys
{
    /** An item's key, a value of its field item and of its form element. */
    public const string KEY = '_collaborate_key';

    /** The delta of an item's element in the form the page shows. */
    public const string DELTA = '_collaborate_delta';

    /** The property marking a multi-value widget whose items are keyed. */
    public const string WIDGET = '#collaborate_items';

    /** The input a page asks for an item with, to be given that key: a peer's item it adds a row for. */
    public const string ADD = '_collaborate_add';

    /** The property naming a Paragraphs widget's types and their form mode, for the prototypes of new items. */
    public const string PARAGRAPHS = '#collaborate_paragraphs';

    /** Paragraphs' widget: its items are paragraphs, each with its subform. */
    private const string PARAGRAPHS_WIDGET = 'Drupal\\paragraphs\\Plugin\\Field\\FieldWidget\\ParagraphsWidget';

    /** A key an item may take. */
    private const string PATTERN = '/^[a-zA-Z0-9_-]{1,80}$/D';

    /** What preg_match() returns for a match. */
    private const int KEYS = 1;

    /** The weights an item's order selects among: a peer's page may hold more items than this one. */
    private const int WEIGHTS = 100;

    /** @var array<string, array<string, list<string>>> draft id => the keys its last save recorded, by collection */
    private array $saved = [];

    public function __construct(
        private readonly DraftAccess $access,
        private readonly DraftJournal $journal,
        private readonly CurrentUserInterface $users,
        private readonly string $role = 'use collaborate',
    ) {}

    /**
     * `hook_field_widget_single_element_form_alter`: an item's key and delta.
     *
     * @param array<array-key, mixed> $element
     * @param array<string, mixed>    $context
     */
    public function element(array &$element, FormStateInterface $state, array $context): void
    {
        $items = $context['items'] ?? null;
        $delta = $context['delta'] ?? null;
        if (!$items instanceof FieldItemListInterface || !\is_int($delta) || !$this->keyed($state, $context)) {
            return;
        }
        $item = $items->get($delta);
        $key = $item?->{self::KEY};
        if (!\is_string($key) || self::KEYS !== preg_match(self::PATTERN, $key)) {
            $key = match (true) {
                null === $item || $item->isEmpty() => '',
                $state->isRebuilding() => 'n' . bin2hex(random_bytes(8)),
                default => $this->savedKey($items, $delta, [] !== ($context['form']['#parents'] ?? [])),
            };
        }
        $element[self::KEY] = ['#type' => 'hidden', '#default_value' => $key];
        $element[self::DELTA] = ['#type' => 'hidden', '#value' => (string) $delta];
    }

    /**
     * `hook_field_widget_complete_form_alter`: marks the widget, widens its items' weights, and keys the item a
     * rebuild added: the one a peer's page asked for (`_collaborate_add`), a paragraph this page added
     * (`n<type>-<uuid>`, its type for the others' pages to add one too). A paragraph stays open, as it shares
     * its subform's controls: the buttons that collapse, duplicate or convert one are left out.
     *
     * @param array<array-key, mixed> $form
     * @param array<string, mixed>    $context
     */
    public function widget(array &$form, FormStateInterface $state, array $context): void
    {
        if (!isset($form['widget']) || !\is_array($form['widget']) || !$this->keyed($state, $context)) {
            return;
        }
        $widget = &$form['widget'];
        $widget[self::WIDGET] = true;
        $keyless = [];
        foreach (Element::children($widget) as $delta) {
            if (!\is_int($delta) || !\is_array($widget[$delta])) {
                continue;
            }
            if (isset($widget[$delta]['_weight']) && \is_array($widget[$delta]['_weight'])) {
                $widget[$delta]['_weight']['#delta'] = max(
                    self::WEIGHTS,
                    \is_int($widget[$delta]['_weight']['#delta'] ?? null) ? $widget[$delta]['_weight']['#delta'] : 0,
                );
            }
            if ('' === ($widget[$delta][self::KEY]['#default_value'] ?? null)) {
                $keyless[] = $delta;
            }
        }
        $paragraphs = self::paragraphs($context);
        if (null !== $paragraphs) {
            $widget[self::PARAGRAPHS] = $paragraphs;
            self::openParagraphs($widget);
        }
        if ([] === $keyless || !$state->isRebuilding()) {
            return;
        }
        $added = max($keyless);
        $requested = $state->getUserInput()[self::ADD] ?? null;
        if (\is_string($requested) && self::KEYS === preg_match(self::PATTERN, $requested) && self::triggeredBy($state, $context)) {
            $widget[$added][self::KEY]['#default_value'] = $requested;

            return;
        }
        // A paragraph this page added is an item at once: an empty one is one of its own.
        $entity = null === $paragraphs ? null : self::paragraph($state, $context, $added);
        if (null !== $entity && $entity->isNew()) {
            $widget[$added][self::KEY]['#default_value'] = 'n' . $entity->bundle() . '-' . \Drupal::service('uuid')->generate();
        }
    }

    /**
     * Whether the rebuild was asked for by one of the widget's own buttons ("Add another item", "Add <type>").
     *
     * @param array<string, mixed> $context
     */
    private static function triggeredBy(FormStateInterface $state, array $context): bool
    {
        $trigger = $state->getTriggeringElement();
        $items = $context['items'] ?? null;
        if (!\is_array($trigger) || !\is_array($trigger['#parents'] ?? null) || !$items instanceof FieldItemListInterface) {
            return false;
        }
        $parents = [...(\is_array($context['form']['#parents'] ?? null) ? $context['form']['#parents'] : []), $items->getName()];

        return \array_slice($trigger['#parents'], 0, \count($parents)) === $parents;
    }

    /**
     * The paragraph types a Paragraphs widget adds, and the form mode of their subforms; null for another widget.
     *
     * @param array<string, mixed> $context
     *
     * @return array{types: list<string>, mode: string}|null
     */
    private static function paragraphs(array $context): ?array
    {
        $widget = $context['widget'] ?? null;
        if (!\is_object($widget) || !is_a($widget, self::PARAGRAPHS_WIDGET)) {
            return null;
        }
        $mode = $widget->getSetting('form_display_mode');

        return [
            'types' => array_map(strval(...), array_keys($widget->getAllowedTypes())),
            'mode' => \is_string($mode) && '' !== $mode ? $mode : 'default',
        ];
    }

    /**
     * A paragraph of the widget's state, as Paragraphs keeps it between rebuilds.
     *
     * @param array<string, mixed> $context
     */
    private static function paragraph(FormStateInterface $state, array $context, int $delta): ?ContentEntityInterface
    {
        $items = $context['items'] ?? null;
        if (!$items instanceof FieldItemListInterface) {
            return null;
        }
        $parents = \is_array($context['form']['#parents'] ?? null) ? $context['form']['#parents'] : [];
        $entity = WidgetBase::getWidgetState($parents, $items->getName(), $state)['paragraphs'][$delta]['entity'] ?? null;

        return $entity instanceof ContentEntityInterface ? $entity : null;
    }

    /**
     * Leaves out a paragraph's buttons that would close it, copy it, convert it or reorder it outside the table.
     *
     * @param array<array-key, mixed> $element
     */
    private static function openParagraphs(array &$element): void
    {
        foreach (Element::children($element) as $child) {
            if (!\is_array($element[$child])) {
                continue;
            }
            $name = $element[$child]['#name'] ?? null;
            if (\is_string($name) && preg_match('/_(collapse|collapse_all|edit|edit-all|edit_all|duplicate|convert|dragdrop_mode)$/D', $name)) {
                $element[$child]['#access'] = false;
            }
            self::openParagraphs($element[$child]);
        }
    }

    /**
     * `hook_entity_form_display_alter`: an entity form a collaborator opens shows its paragraphs open.
     */
    public function display(EntityFormDisplayInterface $display): void
    {
        if (!$this->access->enabled || !$this->users->isGranted($this->role)) {
            return;
        }
        foreach ($display->getComponents() as $name => $component) {
            if ('paragraphs' === ($component['type'] ?? null)) {
                $component['settings'] = ['edit_mode' => 'open', 'autocollapse' => 'none', 'closed_mode_threshold' => 0] + ($component['settings'] ?? []);
                $display->setComponent($name, $component);
            }
        }
    }

    /**
     * The form's first process callback, before any element reads its input: the items the page submitted under
     * their keys go back to the deltas the form was built with, each with its key; an empty row's, with none.
     *
     * @param array<array-key, mixed> $element
     * @param array<array-key, mixed> $complete
     *
     * @return array<array-key, mixed>
     */
    public static function onProcess(array $element, FormStateInterface $state, array &$complete): array
    {
        $input = $state->getUserInput();
        if (!\is_array($input) || [] === $input) {
            return $element;
        }
        $map = [];
        $input = self::input($input, [], $map);
        $trigger = $input['_triggering_element_name'] ?? null;
        if (\is_string($trigger) && [] !== $map) {
            $input['_triggering_element_name'] = self::translateName($trigger, $map);
        }
        $state->setUserInput($input);

        return $element;
    }

    /**
     * @param array<array-key, mixed>   $input
     * @param list<string>              $prefix the names above, as the page named them
     * @param array<string, int>        $map    each item's name, as the page named it => its delta
     *
     * @return array<array-key, mixed>
     */
    private static function input(array $input, array $prefix, array &$map): array
    {
        $rekeyed = [];
        foreach ($input as $key => $value) {
            if (\is_array($value)) {
                $value = self::input($value, [...$prefix, (string) $key], $map);
            }
            $delta = \is_array($value) ? $value[self::DELTA] ?? null : null;
            if (\is_string($delta) && ctype_digit($delta) && !\is_int($key)) {
                // An empty row's name is none; a name no key could be, nothing the draft may record.
                $value[self::KEY] = self::KEYS !== preg_match(self::PATTERN, $key) || preg_match('/^t\d+$/D', $key)
                    ? ''
                    : $key;
                $map[self::path([...$prefix, $key])] = (int) $delta;
                $rekeyed[(int) $delta] = $value;
                continue;
            }
            $rekeyed[$key] = $value;
        }

        return $rekeyed;
    }

    /**
     * A control's name, as the page named it, as the form was built.
     *
     * @param array<string, int> $map
     */
    private static function translateName(string $name, array $map): string
    {
        $segments = self::segments($name);
        $prefix = [];
        $translated = [];
        foreach ($segments as $segment) {
            $prefix[] = $segment;
            $path = self::path($prefix);
            $translated[] = isset($map[$path]) ? (string) $map[$path] : $segment;
        }

        return self::name($translated) . (str_ends_with($name, '[]') ? '[]' : '');
    }

    /**
     * Names the keyed items' controls by their keys, and marks each keyed widget with its collection's path
     * (`data-collaborate-collection`); the page's draft follows them under those names.
     *
     * @param array<array-key, mixed> $form
     */
    public static function rename(array &$form): void
    {
        $keys = [];
        self::collect($form, $keys);
        if ([] !== $keys) {
            self::apply($form, $keys);
        }
    }

    /**
     * The keys of a built form's keyed items, by their widget's parents: an empty row's is `t<delta>`.
     *
     * @param array<array-key, mixed>              $element
     * @param array<string, array<int, string>>    $keys
     */
    private static function collect(array &$element, array &$keys): void
    {
        if (true === ($element[self::WIDGET] ?? false) && \is_array($element['#parents'] ?? null)) {
            $deltas = [];
            foreach (Element::children($element) as $delta) {
                if (!\is_int($delta) || !\is_array($element[$delta][self::KEY] ?? null)) {
                    continue;
                }
                $key = $element[$delta][self::KEY]['#value'] ?? '';
                $deltas[$delta] = \is_string($key) && '' !== $key ? $key : 't' . $delta;
            }
            $keys[self::path(array_map(strval(...), $element['#parents']))] = $deltas;
        }
        foreach (Element::children($element) as $child) {
            if (\is_array($element[$child])) {
                self::collect($element[$child], $keys);
            }
        }
    }

    /**
     * @param array<array-key, mixed>           $element
     * @param array<string, array<int, string>> $keys
     */
    private static function apply(array &$element, array $keys): void
    {
        $parents = \is_array($element['#parents'] ?? null) ? array_map(strval(...), $element['#parents']) : null;
        if (null !== $parents && [] !== $parents) {
            $name = self::name($parents);
            if (true === ($element[self::WIDGET] ?? false)) {
                $element['#attributes']['data-collaborate-collection'] = self::name(self::keyedParents($parents, $keys));
            }
            $current = $element['#name'] ?? null;
            if ($current === $name || $current === $name . '[]') {
                $element['#name'] = self::name(self::keyedParents($parents, $keys)) . substr($current, \strlen($name));
            }
        }
        foreach (Element::children($element) as $child) {
            if (\is_array($element[$child])) {
                self::apply($element[$child], $keys);
            }
        }
    }

    /**
     * Parents, each item's delta replaced by its key.
     *
     * @param list<string>                      $parents
     * @param array<string, array<int, string>> $keys
     *
     * @return list<string>
     */
    private static function keyedParents(array $parents, array $keys): array
    {
        $prefix = [];
        $keyed = [];
        foreach ($parents as $segment) {
            $path = self::path($prefix);
            $keyed[] = ctype_digit($segment) && isset($keys[$path][(int) $segment]) ? $keys[$path][(int) $segment] : $segment;
            $prefix[] = $segment;
        }

        return $keyed;
    }

    /**
     * The keys of a field's items in the order the entity holds them once saved, or null for a field the form
     * does not key.
     *
     * @return list<string>|null
     */
    public static function savedKeys(FieldItemListInterface $items): ?array
    {
        $keys = [];
        foreach ($items as $item) {
            $key = $item->{self::KEY};
            if (!\is_string($key) || self::KEYS !== preg_match(self::PATTERN, $key)) {
                return null;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** Whether a widget's items are keyed: a multi-value one of unlimited cardinality, in a content entity form. */
    private function keyed(FormStateInterface $state, array $context): bool
    {
        $widget = $context['widget'] ?? null;
        $items = $context['items'] ?? null;

        return $this->access->enabled
            && !$state->isProgrammed()
            && true !== ($context['default'] ?? false)
            && $state->getFormObject() instanceof ContentEntityFormInterface
            && $widget instanceof WidgetInterface
            && !$widget instanceof FileWidget
            && true !== ($widget->getPluginDefinition()['multiple_values'] ?? false)
            && $items instanceof FieldItemListInterface
            && FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED === $items->getFieldDefinition()->getFieldStorageDefinition()->getCardinality();
    }

    /**
     * The key the draft's last save recorded for a saved item's place, else one of its delta. A nested field's
     * items (a paragraph's) take their delta's: the draft records the host entity's own fields.
     */
    private function savedKey(FieldItemListInterface $items, int $delta, bool $nested): string
    {
        $entity = $items->getEntity();
        if ($nested || $entity->isNew()) {
            return 'p' . $delta;
        }
        $id = $this->access->draftId($entity->getEntityTypeId() . ':' . $entity->bundle(), (string) $entity->id());
        $this->saved[$id] ??= $this->journal->listKeys($id);
        $keys = $this->saved[$id][$items->getName()] ?? [];
        $count = 0;
        foreach ($items as $item) {
            $count += $item->isEmpty() ? 0 : 1;
        }

        $key = \count($keys) === $count ? $keys[$delta] ?? null : null;

        return \is_string($key) && self::KEYS === preg_match(self::PATTERN, $key) ? $key : 'p' . $delta;
    }

    /** @param list<string> $segments */
    public static function name(array $segments): string
    {
        $first = array_shift($segments);

        return $first . ([] === $segments ? '' : '[' . implode('][', $segments) . ']');
    }

    /** @return list<string> */
    public static function segments(string $name): array
    {
        $name = preg_replace('/\[\]$/', '', $name) ?? $name;

        return explode('[', str_replace(']', '', $name));
    }

    /** @param list<string> $segments */
    private static function path(array $segments): string
    {
        return implode("\0", $segments);
    }
}
