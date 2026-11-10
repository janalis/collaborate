<?php

/**
 * @file
 * The test site's content and editors, run by `drush php:script` once the module is installed: editors alice, bob
 * and carol (password: their name) with the content editor role, one article, and notifications with one topic and
 * one type, as the Playwright scenarios expect them. The article type and the role are core's recipes.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
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

if ([] === \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'Shared article')->execute()) {
  Node::create([
    'type' => 'article',
    'title' => 'Shared article',
    'body' => ['value' => '<p>Hello world</p>', 'format' => 'basic_html'],
  ])->save();
}

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
