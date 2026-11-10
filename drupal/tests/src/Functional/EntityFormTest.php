<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Functional;

use Drupal\collaborate\Storage\Schema;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** A node's edit form, shared as a draft with the editors who may collaborate. */
#[RunTestsInSeparateProcesses]
final class EntityFormTest extends BrowserTestBase
{
    protected static $modules = ['node', 'field', 'collaborate'];

    protected $defaultTheme = 'stark';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::default()->install();
        $this->config('system.logging')->set('error_level', 'verbose')->save();
        $this->drupalCreateContentType(['type' => 'article']);
    }

    public function testAnEditorWhoCollaboratesGetsTheFormsDraftAndTheLayer(): void
    {
        $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Shared']);
        $this->drupalLogin($this->drupalCreateUser(['use collaborate', 'edit any article content', 'access content']));
        $this->drupalGet('node/' . $node->id() . '/edit');

        $form = $this->assertSession()->elementExists('css', 'form[data-collaborate]');
        $configuration = json_decode(
            (string) $form->getAttribute('data-collaborate'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $scope = json_decode(
            (string) base64_decode(explode('.', $configuration['grant'])[0], true),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertSame('node:article', $scope['entity']);
        self::assertSame('Shared', $scope['fields']['title[0][value]']['initial']);
        self::assertSame('text', $scope['fields']['title[0][value]']['type']);
        self::assertStringEndsWith('/admin/collaborate/__action__', $configuration['url']);
        self::assertStringContainsString('/node/__record__/edit', $configuration['editUrl']);
        $this->assertSession()->elementExists('css', '[data-collaborate-layer]');
        $this->assertSession()->elementAttributeContains('css', 'body', 'data-collaborate-page', 'node:edit');
        $this->assertSession()->elementExists('css', 'script[type="importmap"]');
    }

    public function testAPagesRefreshIsAnsweredWithItsConfigurationAlone(): void
    {
        $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Refreshed']);
        $this->drupalLogin($this->drupalCreateUser(['use collaborate', 'edit any article content', 'access content']));

        $json = $this->drupalGet(
            'node/' . $node->id() . '/edit',
            [],
            ['X-Collaborate-Refresh' => '1', 'Accept' => 'application/json'],
        );
        $refreshed = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($refreshed['configuration']);
        self::assertMatchesRegularExpression('/^[\w-]{20,}$/', $refreshed['tokens']['form_token']);
    }

    public function testAMultiValueFieldsItemsAreACollectionKeptThroughASubmission(): void
    {
        FieldStorageConfig::create([
            'field_name' => 'field_notes',
            'entity_type' => 'node',
            'type' => 'string',
            'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
        ])->save();
        FieldConfig::create(['field_name' => 'field_notes', 'entity_type' => 'node', 'bundle' => 'article'])->save();
        $this->container->get('entity_display.repository')->getFormDisplay('node', 'article')
            ->setComponent('field_notes', ['type' => 'string_textfield'])
            ->save();
        $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Notes', 'field_notes' => ['First', 'Second']]);
        $this->drupalLogin($this->drupalCreateUser(['use collaborate', 'edit any article content', 'access content']));
        $this->drupalGet('node/' . $node->id() . '/edit');

        // The items are named by their keys, Drupal's empty row by its delta.
        $this->assertSession()->elementExists('css', '[data-collaborate-collection="field_notes"]');
        $this->assertSession()->fieldValueEquals('field_notes[p0][value]', 'First');
        $this->assertSession()->fieldValueEquals('field_notes[p1][value]', 'Second');
        $this->assertSession()->fieldExists('field_notes[t2][value]');
        $form = $this->assertSession()->elementExists('css', 'form[data-collaborate]');
        $configuration = json_decode((string) $form->getAttribute('data-collaborate'), true, flags: \JSON_THROW_ON_ERROR);
        $fields = json_decode(
            (string) base64_decode(explode('.', $configuration['grant'])[0], true),
            true,
            flags: \JSON_THROW_ON_ERROR,
        )['fields'];
        self::assertSame(['p0', 'p1'], $fields['field_notes[__collection__]']['initial']);
        self::assertSame(['alive' => true, 'order' => 2, 'recordId' => null], $fields['field_notes[@p1]']['initial']);
        self::assertSame('Second', $fields['field_notes[p1][value]']['initial']);
        self::assertSame('', $fields['field_notes[__name__][value]']['initial']);
        self::assertArrayNotHasKey('field_notes[t2][value]', $fields);

        // A submission under the keys is read back by the deltas the form was built with.
        $this->submitForm([
            'field_notes[p0][value]' => 'First edited',
            'field_notes[p1][value]' => 'Second',
            'field_notes[t2][value]' => 'Third',
        ], 'Save');

        $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
        self::assertSame(['First edited', 'Second', 'Third'], array_column($saved->get('field_notes')->getValue(), 'value'));
    }

    public function testAnEditorWhoMayNotCollaborateEditsWithoutADraft(): void
    {
        $node = $this->drupalCreateNode(['type' => 'article']);
        $this->drupalLogin($this->drupalCreateUser(['edit any article content', 'access content']));
        $this->drupalGet('node/' . $node->id() . '/edit');

        $this->assertSession()->elementNotExists('css', 'form[data-collaborate]');
        $this->assertSession()->elementNotExists('css', '[data-collaborate-layer]');
    }
}
