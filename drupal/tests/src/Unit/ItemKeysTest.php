<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Unit;

use Drupal\collaborate\Bridge\ItemKeys;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;

/** A multi-value field's items, named by their keys on the page and read back by their deltas. */
final class ItemKeysTest extends UnitTestCase
{
    public function testASubmissionGoesBackToTheDeltasItsItemsWereBuiltWith(): void
    {
        $state = new FormState();
        $state->setUserInput([
            'title' => [['value' => 'Kept']],
            'field_notes' => [
                'p1' => ['value' => 'Second', ItemKeys::KEY => 'p1', ItemKeys::DELTA => '1'],
                'nabc' => ['value' => 'Third', ItemKeys::KEY => '', ItemKeys::DELTA => '2'],
                'p0' => ['value' => 'First', ItemKeys::KEY => 'p0', ItemKeys::DELTA => '0'],
                't3' => ['value' => '', ItemKeys::KEY => '', ItemKeys::DELTA => '3'],
                // A name no key could be is kept as none.
                'bad key!' => ['value' => 'Fifth', ItemKeys::KEY => '', ItemKeys::DELTA => '4'],
                'add_more' => 'Add another item',
            ],
            // A paragraph's own items, below the paragraph's key.
            'field_parts' => [
                'nxyz' => [
                    ItemKeys::DELTA => '0',
                    'subform' => ['field_lines' => ['p0' => ['value' => 'Line', ItemKeys::DELTA => '0']]],
                ],
            ],
            '_triggering_element_name' => 'field_parts[nxyz][subform][field_lines][p0][value]',
        ]);
        $complete = [];

        ItemKeys::onProcess(['#type' => 'form'], $state, $complete);

        $input = $state->getUserInput();
        self::assertSame('First', $input['field_notes'][0]['value']);
        self::assertSame('p0', $input['field_notes'][0][ItemKeys::KEY]);
        self::assertSame('Second', $input['field_notes'][1]['value']);
        self::assertSame('nabc', $input['field_notes'][2][ItemKeys::KEY]);
        self::assertSame('', $input['field_notes'][3][ItemKeys::KEY]);
        self::assertSame('Fifth', $input['field_notes'][4]['value']);
        self::assertSame('', $input['field_notes'][4][ItemKeys::KEY]);
        self::assertSame('Add another item', $input['field_notes']['add_more']);
        self::assertSame([['value' => 'Kept']], $input['title']);
        self::assertSame('Line', $input['field_parts'][0]['subform']['field_lines'][0]['value']);
        self::assertSame('field_parts[0][subform][field_lines][0][value]', $input['_triggering_element_name']);
    }

    public function testAFormWithoutInputOrKeysIsLeftAlone(): void
    {
        $state = new FormState();
        $complete = [];
        ItemKeys::onProcess([], $state, $complete);
        self::assertNull($state->getUserInput());

        $state->setUserInput(['field_notes' => [['value' => 'First']], '_triggering_element_name' => 'op']);
        ItemKeys::onProcess([], $state, $complete);
        self::assertSame(
            ['field_notes' => [['value' => 'First']], '_triggering_element_name' => 'op'],
            $state->getUserInput(),
        );
    }

    public function testTheItemsControlsAreNamedByTheirKeys(): void
    {
        $control = static fn(array $parents, string $suffix = ''): array => [
            '#parents' => $parents,
            '#name' => ItemKeys::name($parents) . $suffix,
        ];
        $form = [
            'field_notes' => [
                'widget' => [
                    ItemKeys::WIDGET => true,
                    '#parents' => ['field_notes'],
                    0 => [
                        ItemKeys::KEY => ['#value' => 'p0'] + $control(['field_notes', '0', ItemKeys::KEY]),
                        'value' => $control(['field_notes', '0', 'value']),
                        'tags' => $control(['field_notes', '0', 'tags'], '[]'),
                    ],
                    1 => [
                        ItemKeys::KEY => ['#value' => ''] + $control(['field_notes', '1', ItemKeys::KEY]),
                        'value' => $control(['field_notes', '1', 'value']),
                    ],
                    'add_more' => ['#parents' => ['field_notes', 'add_more'], '#name' => 'field_notes_add_more'],
                ],
            ],
            'title' => $control(['title', '0', 'value']),
        ];

        ItemKeys::rename($form);

        $widget = $form['field_notes']['widget'];
        self::assertSame('field_notes', $widget['#attributes']['data-collaborate-collection']);
        self::assertSame('field_notes[p0][value]', $widget[0]['value']['#name']);
        self::assertSame('field_notes[p0][tags][]', $widget[0]['tags']['#name']);
        self::assertSame('field_notes[t1][value]', $widget[1]['value']['#name']);
        self::assertSame('field_notes_add_more', $widget['add_more']['#name']);
        self::assertSame('title[0][value]', $form['title']['#name']);

        $plain = ['title' => $control(['title', '0', 'value'])];
        ItemKeys::rename($plain);
        self::assertSame('title[0][value]', $plain['title']['#name']);
    }

    public function testANameSplitsIntoItsSegments(): void
    {
        self::assertSame(['field_notes', 'p0', 'value'], ItemKeys::segments('field_notes[p0][value]'));
        self::assertSame(['field_tags', 'target_id'], ItemKeys::segments('field_tags[target_id][]'));
        self::assertSame('title', ItemKeys::name(['title']));
    }
}
