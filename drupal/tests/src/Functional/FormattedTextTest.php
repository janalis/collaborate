<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Functional;

use Drupal\collaborate\Storage\Schema;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** A formatted text field, shared as rich text when CKEditor 5 edits it, and only then. */
#[RunTestsInSeparateProcesses]
final class FormattedTextTest extends BrowserTestBase
{
    protected static $modules = ['node', 'field', 'text', 'filter', 'editor', 'ckeditor5', 'collaborate'];

    protected $defaultTheme = 'stark';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::default()->install();
        $this->drupalCreateContentType(['type' => 'article']);
        FilterFormat::create(['format' => 'full', 'name' => 'Full', 'filters' => []])->save();
        FilterFormat::create(['format' => 'plain', 'name' => 'Plain', 'filters' => []])->save();
        Editor::create([
            'format' => 'full',
            'editor' => 'ckeditor5',
            'settings' => ['toolbar' => ['items' => []], 'plugins' => []],
            'image_upload' => ['status' => false],
        ])->save();
        $this->drupalLogin($this->drupalCreateUser([
            'use collaborate',
            'edit any article content',
            'access content',
            'use text format full',
            'use text format plain',
        ]));
    }

    public function testAFormatEditedWithCkeditor5IsSharedAsRichTextAndAPlainOneAsText(): void
    {
        self::assertSame('rich', $this->fields($this->article('full'))['body[0][value]']['type'] ?? null);
        self::assertSame('text', $this->fields($this->article('plain'))['body[0][value]']['type'] ?? null);
    }

    public function testWithoutRichTextAFormatEditedWithAnEditorStaysOutOfTheDraft(): void
    {
        $this->config('collaborate.settings')->set('drafts.rich_text', false)->save();
        $this->resetAll();

        // Its textarea is hidden behind an editor the draft does not follow, and its format goes with it.
        $fields = $this->fields($this->article('full'));
        self::assertArrayNotHasKey('body[0][value]', $fields);
        self::assertArrayNotHasKey('body[0][format]', $fields);
        self::assertArrayHasKey('title[0][value]', $fields);
        self::assertSame('text', $this->fields($this->article('plain'))['body[0][value]']['type'] ?? null);
    }

    public function testARebuiltFormSharesItsValueAsTheFormatItShows(): void
    {
        // A format chosen anew, the form rebuilt by a validation error: shared as that format's.
        $this->drupalGet($this->article('plain')->toUrl('edit-form'));
        $this->submitForm(['title[0][value]' => '', 'body[0][format]' => 'full'], 'Save');
        self::assertSame('rich', $this->shown()['body[0][value]']['type'] ?? null);
    }

    private function article(string $format): NodeInterface
    {
        return $this->drupalCreateNode([
            'type' => 'article',
            'title' => 'Shared',
            'body' => ['value' => '<p>Body</p>', 'format' => $format],
        ]);
    }

    /** @return array<array-key, mixed> the fields the node's edit form shares, by name */
    private function fields(NodeInterface $node): array
    {
        $this->drupalGet($node->toUrl('edit-form'));

        return $this->shown();
    }

    /** @return array<array-key, mixed> the fields the form on the page shares, by name */
    private function shown(): array
    {
        $form = $this->assertSession()->elementExists('css', 'form[data-collaborate]');
        // @mago-expect analysis:mixed-assignment the page's configuration, checked right below
        $configuration = json_decode(
            (string) $form->getAttribute('data-collaborate'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($configuration);
        self::assertIsString($configuration['grant'] ?? null);
        // @mago-expect analysis:mixed-assignment the grant's scope, checked right below
        $scope = json_decode(
            (string) base64_decode(explode('.', $configuration['grant'])[0], true),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($scope);
        self::assertIsArray($scope['fields'] ?? null);

        return $scope['fields'];
    }
}
