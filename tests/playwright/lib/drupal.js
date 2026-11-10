import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { test as base, expect } from '@playwright/test';

// The Drupal test site (tests/Application/Drupal, `make drupal-install`): editors alice, bob and carol, whose
// password is their name, with the content editor role and the module's permissions.
const site = path.resolve('tests/Application/Drupal');

/** Runs PHP in the site, through Drush; its output trimmed. */
export function drush(code) {
  return execFileSync('vendor/bin/drush', ['php:eval', code], { cwd: site, encoding: 'utf8' }).trim();
}

/** A new article of its own for a scenario, with its notes (a multi-value field): its id. */
export function article(title, body = '<p>Hello world</p>', notes = []) {
  return Number(
    drush(`$n = \\Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => ${JSON.stringify(title)},
      'body' => ['value' => ${JSON.stringify(body)}, 'format' => 'basic_html'],
      'field_notes' => json_decode(${JSON.stringify(JSON.stringify(notes))}, TRUE)]); $n->save(); echo $n->id();`)
  );
}

/** A new article of its own for a scenario, with its parts (`[type, text]` paragraphs): its id. */
export function articleWithParts(title, parts) {
  return Number(
    drush(`$parts = [];
      foreach (json_decode(${JSON.stringify(JSON.stringify(parts))}, TRUE) as [$type, $text]) {
        $part = \\Drupal\\paragraphs\\Entity\\Paragraph::create(['type' => $type, 'field_' . $type => $text]);
        $part->save();
        $parts[] = $part;
      }
      $n = \\Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => ${JSON.stringify(title)}, 'field_parts' => $parts]);
      $n->save(); echo $n->id();`)
  );
}

/** The parts an article holds, as saved: `[type, text]` each. */
export function savedParts(id) {
  return JSON.parse(
    drush(`\\Drupal::entityTypeManager()->getStorage('node')->resetCache();
      echo json_encode(array_map(static fn($part) => [$part->bundle(), $part->get('field_' . $part->bundle())->value],
        \\Drupal\\node\\Entity\\Node::load(${id})->get('field_parts')->referencedEntities()));`)
  );
}

/** The notes an article holds, as saved. */
export function savedNotes(id) {
  return JSON.parse(
    drush(`\\Drupal::entityTypeManager()->getStorage('node')->resetCache();
      echo json_encode(array_column(\\Drupal\\node\\Entity\\Node::load(${id})->get('field_notes')->getValue(), 'value'));`)
  );
}

/** An editor of the site: a browser context signed in as `name`. */
export class Editor {
  constructor(page) {
    this.page = page;
  }

  get form() {
    return this.page.locator('form[data-collaborate]');
  }

  get bar() {
    return this.page.locator('[data-collaborate-bar] [data-collaborate-status]');
  }

  get title() {
    return this.page.locator('#edit-title-0-value');
  }

  get body() {
    return this.page.locator('.ck-editor__editable');
  }

  /** The rows of the article's notes, empty ones included, in the order shown. */
  get notes() {
    return this.form.locator('[data-collaborate-collection="field_notes"] tr.draggable');
  }

  /** The rows of the article's parts, in the order shown. */
  get parts() {
    return this.form.locator('[data-collaborate-collection="field_parts"] tr.draggable');
  }

  /** The text of a part's row. */
  part(index) {
    return this.parts.nth(index).locator('input[type="text"]');
  }

  /** The text of a note's row. */
  note(index) {
    return this.notes.nth(index).locator('input[type="text"]');
  }

  /** Saves the article with the bar's Save, and waits for its page. */
  async save(id) {
    await Promise.all([
      this.page.waitForURL(`**/node/${id}`),
      this.page.locator('[data-collaborate-bar] [data-collaborate-save]').click(),
    ]);
  }

  /** Opens an article's edit form, and waits for its draft to be joined. */
  async edit(id) {
    await this.page.goto(`/node/${id}/edit`);
    await expect(this.form).toHaveAttribute('data-collaborate-state', 'saved');
  }
}

export const test = base.extend({
  editor: async ({ browser }, use) => {
    const contexts = [];
    await use(async (name) => {
      const context = await browser.newContext();
      contexts.push(context);
      const page = await context.newPage();
      await page.goto('/user/login');
      await page.fill('#edit-name', name);
      await page.fill('#edit-pass', name);
      await page.click('#edit-submit');
      await expect(page).not.toHaveURL(/user\/login/);
      return new Editor(page);
    });
    for (const context of contexts) await context.close();
  },
});

export { expect };
