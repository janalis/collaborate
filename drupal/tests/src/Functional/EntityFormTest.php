<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Functional;

use Drupal\collaborate\Storage\Schema;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** A node's edit form, shared as a draft with the editors who may collaborate. */
#[RunTestsInSeparateProcesses]
final class EntityFormTest extends BrowserTestBase
{
    protected static $modules = ['node', 'collaborate'];

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

    public function testAnEditorWhoMayNotCollaborateEditsWithoutADraft(): void
    {
        $node = $this->drupalCreateNode(['type' => 'article']);
        $this->drupalLogin($this->drupalCreateUser(['edit any article content', 'access content']));
        $this->drupalGet('node/' . $node->id() . '/edit');

        $this->assertSession()->elementNotExists('css', 'form[data-collaborate]');
        $this->assertSession()->elementNotExists('css', '[data-collaborate-layer]');
    }
}
