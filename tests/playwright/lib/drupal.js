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

/** A new article of its own for a scenario: its id. */
export function article(title, body = '<p>Hello world</p>') {
  return Number(
    drush(`$n = \\Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => ${JSON.stringify(title)},
      'body' => ['value' => ${JSON.stringify(body)}, 'format' => 'basic_html']]); $n->save(); echo $n->id();`)
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
