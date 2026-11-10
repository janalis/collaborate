<?php

/**
 * @file
 * The test site's content and editors, run by `drush php:script` once the module is installed: editors alice, bob
 * and carol (password: their name) with the content editor role, one article with two notes, paragraphs of two types
 * (text, quote), pages a dossier holds inline, and notifications with one topic and one type, as the Playwright scenarios expect them. The article type and the role are core's recipes.
 */

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$role = Role::load('content_editor');
foreach ([
  'use collaborate',
  'receive collaborate notifications',
  'create article content',
  'edit any article content',
  'use text format basic_html',
] as $permission) {
  $role->grantPermission($permission);
}
$role->save();

foreach (['alice', 'bob', 'carol'] as $name) {
  $account = user_load_by_name($name) ?: User::create(['name' => $name, 'mail' => "$name@example.com", 'status' => 1]);
  $account->setPassword($name);
  $account->addRole('content_editor');
  $account->save();
}

// A multi-value field of unlimited cardinality: its items are a shared collection.
if (NULL === FieldStorageConfig::loadByName('node', 'field_notes')) {
  FieldStorageConfig::create([
    'field_name' => 'field_notes',
    'entity_type' => 'node',
    'type' => 'string',
    'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
  ])->save();
  FieldConfig::create(['field_name' => 'field_notes', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Notes'])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')
    ->setComponent('field_notes', ['type' => 'string_textfield', 'weight' => 5])
    ->save();
}

// Paragraphs of two types: their items are a shared collection too, each paragraph with its subform.
\Drupal::service('module_installer')->install(['paragraphs']);
foreach (['text' => 'Text', 'quote' => 'Quote'] as $type => $label) {
  if (NULL === ParagraphsType::load($type)) {
    ParagraphsType::create(['id' => $type, 'label' => $label])->save();
  }
  $field = 'field_' . $type;
  if (NULL === FieldStorageConfig::loadByName('paragraph', $field)) {
    FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'paragraph', 'type' => 'string'])->save();
  }
  if (NULL === FieldConfig::loadByName('paragraph', $type, $field)) {
    FieldConfig::create(['field_name' => $field, 'entity_type' => 'paragraph', 'bundle' => $type, 'label' => $label])->save();
    \Drupal::service('entity_display.repository')->getFormDisplay('paragraph', $type)
      ->setComponent($field, ['type' => 'string_textfield'])
      ->save();
  }
}
if (NULL === FieldStorageConfig::loadByName('node', 'field_parts')) {
  FieldStorageConfig::create([
    'field_name' => 'field_parts',
    'entity_type' => 'node',
    'type' => 'entity_reference_revisions',
    'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    'settings' => ['target_type' => 'paragraph'],
  ])->save();
  FieldConfig::create([
    'field_name' => 'field_parts',
    'entity_type' => 'node',
    'bundle' => 'article',
    'label' => 'Parts',
    'settings' => ['handler' => 'default:paragraph', 'handler_settings' => ['target_bundles' => NULL]],
  ])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')
    ->setComponent('field_parts', ['type' => 'paragraphs', 'weight' => 6, 'settings' => ['add_mode' => 'button']])
    ->save();
}

// Pages a dossier holds with Inline Entity Form's simple widget: each page's form is an item's subform. A type
// of its own: a reference without pages shows an empty page form, whose required title would block a save.
\Drupal::service('module_installer')->install(['inline_entity_form']);
foreach (['page' => 'Page', 'dossier' => 'Dossier'] as $type => $name) {
  if (NULL === NodeType::load($type)) {
    NodeType::create(['type' => $type, 'name' => $name])->save();
  }
}
if (NULL === FieldStorageConfig::loadByName('node', 'field_related')) {
  FieldStorageConfig::create([
    'field_name' => 'field_related',
    'entity_type' => 'node',
    'type' => 'entity_reference',
    'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    'settings' => ['target_type' => 'node'],
  ])->save();
  FieldConfig::create([
    'field_name' => 'field_related',
    'entity_type' => 'node',
    'bundle' => 'dossier',
    'label' => 'Related',
    'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['page' => 'page']]],
  ])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'dossier')
    ->setComponent('field_related', ['type' => 'inline_entity_form_simple', 'weight' => 7])
    ->save();
}

if ([] === \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'Shared article')->execute()) {
  Node::create([
    'type' => 'article',
    'title' => 'Shared article',
    'body' => ['value' => '<p>Hello world</p>', 'format' => 'basic_html'],
    'field_notes' => ['First note', 'Second note'],
  ])->save();
}

// The pages' permissions, once their type exists.
Role::load('content_editor')
  ->grantPermission('create page content')
  ->grantPermission('edit any page content')
  ->grantPermission('edit any dossier content')
  ->save();

\Drupal::configFactory()->getEditable('collaborate.settings')
  ->set('notifications.enabled', TRUE)
  ->set('advanced', [
    'notifications' => [
      'topics' => ['content' => ['label' => 'Content']],
      'types' => ['saved' => ['topic' => 'content', 'sentence' => '%name% saved %title%.']],
    ],
  ])
  ->save();
\Drupal::service('kernel')->invalidateContainer();
