import { test as base, expect } from '@playwright/test';

// The pages of each test application, by Playwright project name (`limits` is the plain application in
// its `e2e_limits` environment, `plain-*` the plain application rendered by another stock form theme, and
// `plain-ws` the plain application on the bundle's WebSocket hub).
const plain = {
  form: 'article',
  index: '/admin/articles',
  edit: (id) => `/admin/articles/${id}/edit`,
  new: '/admin/articles/new',
};
const routes = {
  plain,
  limits: plain,
  'plain-div': plain,
  'plain-table': plain,
  'plain-tailwind': plain,
  'plain-ws': plain,
  easyadmin: {
    form: 'Article',
    index: '/admin/article',
    edit: (id) => `/admin/article/${id}/edit`,
    detail: (id) => `/admin/article/${id}`,
    new: '/admin/article/new',
  },
};

/** An editor of the application: a browser context signed in as `username`. */
export class Editor {
  constructor(context, page, routes, project) {
    this.context = context;
    this.page = page;
    this.routes = routes;
    this.project = project;
  }

  get easyAdmin() {
    return this.project === 'easyadmin';
  }

  get form() {
    return this.page.locator('form[data-collaborate]');
  }

  /** The state bar, and the text of its state. */
  get barElement() {
    return this.page.locator('[data-collaborate-bar]');
  }

  get bar() {
    return this.barElement.locator('[data-collaborate-status]');
  }

  get announcer() {
    return this.barElement.locator('[data-collaborate-announcer]');
  }

  get notice() {
    return this.page.locator('[data-collaborate-notice]');
  }

  get barSaveButton() {
    return this.page.locator('[data-collaborate-save]');
  }

  get discardButton() {
    return this.page.locator('[data-collaborate-discard]');
  }

  get dialog() {
    return this.page.locator('[data-collaborate-confirm]');
  }

  get conflicts() {
    return this.page.locator('[data-collaborate-conflicts]');
  }

  get presence() {
    return this.page.locator('[data-collaborate-presence]');
  }

  get layer() {
    return this.page.locator('[data-collaborate-layer]');
  }

  /** The full form name of a field: `name('title')`, `name('tags', 0, 'name')`. */
  name(...path) {
    return `${this.routes.form}${path.map((part) => `[${part}]`).join('')}`;
  }

  /** A control by its field path, whatever its label says in either application. */
  control(...path) {
    return this.page.locator(`form [name="${this.name(...path)}"]`);
  }

  field(label) {
    return this.form.getByLabel(label, { exact: true });
  }

  /** The CKEditor editable of a rich-text field (`content`, `notes`). */
  rich(field = 'content') {
    return this.form.locator(`textarea[name="${this.name(field)}"] + .ck-editor .ck-editor__editable`);
  }

  get richText() {
    return this.rich('content');
  }

  /** The rows of the tag collection, in the order shown. */
  get tagRows() {
    return this.form.locator(
      `[data-collaborate-collection] ${this.easyAdmin ? '.field-collection-item' : '[data-collaborate-entry]'}`
    );
  }

  /** The values of the tag names, in the order shown. */
  tagNames() {
    return this.tagRows.locator('input[name$="[name]"]').evaluateAll((inputs) => inputs.map((input) => input.value));
  }

  /** The hidden positions of the tag rows, in the order shown. */
  tagPositions() {
    return this.tagRows
      .locator('input[type="hidden"][name$="[position]"]')
      .evaluateAll((inputs) => inputs.map((input) => input.value));
  }

  /** Adds an empty tag row with the application's own button; resolves the new row. */
  async addTag() {
    const before = await this.tagRows.count();
    await this.page.getByRole('button', { name: this.easyAdmin ? 'Add a new item' : 'Add tag' }).click();
    await expect(this.tagRows).toHaveCount(before + 1);
    return this.tagRows.nth(before);
  }

  async removeTag(index) {
    const row = this.tagRows.nth(index);
    if (this.easyAdmin) await row.locator('.field-collection-delete-button').click();
    else await row.getByRole('button', { name: 'Remove' }).click();
  }

  async moveTagUp(index) {
    await this.tagRows.nth(index).locator('[data-move-up]').click();
  }

  /** The category's select: EasyAdmin's autocomplete names its own. */
  get category() {
    return this.easyAdmin ? this.control('category', 'autocomplete') : this.control('category');
  }

  /** Chooses a category: a select on the plain page, EasyAdmin's autocomplete (TomSelect) on the other. */
  async pickCategory(name) {
    if (!this.easyAdmin) {
      await this.category.selectOption({ label: name });
      return;
    }
    const wrapper = this.form.locator(`[name="${this.name('category', 'autocomplete')}"] ~ .ts-wrapper`);
    await wrapper.locator('.ts-control').click();
    await this.page.keyboard.type(name.slice(0, 3));
    await this.page.locator('.ts-dropdown .option', { hasText: name }).first().click();
  }

  /** The selected category's label, whichever widget shows it. */
  categoryLabel() {
    return this.category.evaluate((select) => select.selectedOptions[0]?.textContent.trim() ?? '');
  }

  /** Opens `path` and waits until its shared draft has joined and unlocked the form. */
  async open(path) {
    await this.page.goto(path);
    await this.joined();
  }

  async joined() {
    await expect(this.form).toHaveAttribute('data-collaborate-state', /^(saved|draft|conflict)$/);
    await expect(this.form.locator('[inert]')).toHaveCount(0);
    for (const textarea of await this.form.locator('textarea[data-rich-text]').all())
      await expect(textarea).toHaveAttribute('data-editor', 'ready');
  }

  async edit(id) {
    await this.open(this.routes.edit(id));
  }

  get saveButton() {
    // The form's own button, not the bar's Save that stands for it.
    return this.page
      .getByRole('button', { name: /^(Save|Save changes|Create)$/ })
      .and(this.page.locator(':not([data-collaborate-save])'))
      .first();
  }

  /** Submits the form with the page's main save button (EasyAdmin's sit outside the form) and waits for the page it leads to. */
  async save() {
    const posted = this.page.waitForRequest((request) => request.method() === 'POST' && request.isNavigationRequest());
    await this.saveButton.click();
    await posted;
    await this.page.waitForLoadState();
  }

  /** The draft session of this page's shared form, as a page-side handle (`window.collaborateSession()`). */
  async exposeSession() {
    await this.page.evaluate(async () => {
      const { sessionFor } = await import('@janalis/collaborate/easyadmin.js');
      window.collaborateSession = () => {
        const form = document.querySelector('form[data-collaborate]');
        const control = Array.from(form?.elements ?? []).find((element) => element.name);
        return control ? sessionFor(control) : null;
      };
    });
  }

  /** Saves and stays on the edit page (EasyAdmin's "Save and continue editing"), joined again. */
  async saveAndStay() {
    const posted = this.page.waitForRequest((request) => request.method() === 'POST' && request.isNavigationRequest());
    await this.page
      .getByRole('button', { name: this.easyAdmin ? 'Save and continue editing' : 'Save', exact: true })
      .and(this.page.locator(':not([data-collaborate-save])'))
      .click();
    await posted;
    await this.page.waitForLoadState();
    await this.joined();
  }

  /**
   * Waits until every edit of this page is in the shared draft: nothing waits to be sent or is under way
   * (or the session stopped, keeping what it could not send).
   */
  async synchronized() {
    await this.exposeSession();
    await this.page.waitForFunction(() => {
      const session = window.collaborateSession();
      if (session?.stopped) return true;
      return (
        session?.initialized &&
        !session.timer &&
        !session.running &&
        !session.pulling &&
        Array.from(session.adapters.values()).every((adapter) =>
          adapter.hasPending ? !adapter.hasPending() : !adapter.next()
        )
      );
    });
  }

  /** A button of a rich-text field's toolbar (`Bold`, `Undo`…). */
  toolbar(name, field = 'content') {
    return this.form
      .locator(`textarea[name="${this.name(field)}"] + .ck-editor .ck-toolbar`)
      .getByRole('button', { name, exact: true });
  }

  /** Focuses a rich-text field with its caret at the `start` or `end` of its content. */
  async caretRich(where = 'end', field = 'content') {
    await this.rich(field).click();
    await this.rich(field).evaluate((editable, where) => {
      const { model } = editable.ckeditorInstance;
      const root = model.document.getRoot();
      const block = where === 'start' ? root.getChild(0) : root.getChild(root.childCount - 1);
      model.change((writer) => writer.setSelection(writer.createPositionAt(block, where === 'start' ? 0 : 'end')));
    }, where);
  }

  /** Types at the end (or `start`) of a rich-text field. */
  async typeRich(text, field = 'content', where = 'end') {
    await this.caretRich(where, field);
    await this.page.keyboard.type(text);
  }

  /** Starts recording every state the form passes through; `states()` reads them. */
  async recordStates() {
    await this.form.evaluate((form) => {
      window.collaborateStates = [form.dataset.collaborateState];
      new MutationObserver(() => window.collaborateStates.push(form.dataset.collaborateState)).observe(form, {
        attributeFilter: ['data-collaborate-state'],
      });
    });
  }

  states() {
    return this.page.evaluate(() => window.collaborateStates);
  }

  /**
   * Holds the requests whose URL matches `pattern` until the returned `release()` is called; `held` resolves
   * once one is held.
   */
  async hold(pattern) {
    let release;
    let heldResolve;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const held = new Promise((resolve) => {
      heldResolve = resolve;
    });
    await this.page.route(pattern, async (route) => {
      heldResolve();
      await gate;
      await route.continue().catch(() => {});
    });
    return {
      held,
      release: async () => {
        release();
        await this.page.unroute(pattern);
      },
    };
  }

  /**
   * Answers the next request matching `pattern` with `status` (and an optional JSON body), once; later ones
   * go through. (The route stays: one removed while its requests are under way can stall the next request.)
   */
  async answerOnce(pattern, status, body = { error: 'Refused by the scenario.' }) {
    let answered = false;
    await this.page.route(pattern, (route) => {
      if (answered) return route.fallback();
      answered = true;
      return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    });
  }

  /** Confirms or cancels the open dialog, once it shows (Bootstrap ignores a click while it fades in). */
  async decide(accept) {
    await expect(this.dialog).toBeVisible();
    await expect(this.dialog.locator('[data-collaborate-confirm-cancel]')).toBeFocused();
    await this.dialog
      .locator(accept ? '[data-collaborate-confirm-accept]' : '[data-collaborate-confirm-cancel]')
      .click();
    await expect(this.dialog).toBeHidden();
  }
}

/** Expects the bar in `state`: the form's state, its alert variant and icon, and its text (also announced). */
export async function expectState(editor, state, { variant, icon, text, announced = true } = {}) {
  await expect(editor.form).toHaveAttribute('data-collaborate-state', state);
  if (variant) await expect(editor.barElement).toHaveClass(new RegExp(`\\balert-${variant}\\b`));
  if (icon) await expect(editor.barElement.locator('.collaborate-bar__icon')).toHaveClass(new RegExp(`\\b${icon}\\b`));
  if (text) {
    await expect(editor.bar).toHaveText(text);
    if (announced) await expect(editor.announcer).toHaveText(text);
  }
}

export const test = base.extend({
  /** A new article of this scenario's own: `article({title})` resolves its id. */
  article: async ({ request, baseURL }, use, testInfo) => {
    await use(async (data = {}) => {
      const response = await request.post(`${baseURL}/e2e/articles`, {
        data: { title: `${testInfo.title.slice(0, 100)} ${Date.now()}`, ...data },
      });
      expect(response.status()).toBe(201);
      return (await response.json()).id;
    });
  },
  routes: async ({ baseURL: _baseURL }, use, testInfo) => {
    await use(routes[testInfo.project.name]);
  },
  /** `editor('alice')`: a signed-in browser context of its own, closed after the scenario; `options` go to the context. */
  editor: async ({ browser, baseURL, routes }, use, testInfo) => {
    const contexts = [];
    await use(async (username, options = {}) => {
      const context = await browser.newContext({ baseURL, ...options });
      contexts.push(context);
      const page = await context.newPage();
      if (process.env.E2E_DEBUG) {
        page.on('console', (message) => console.log(username, message.type(), message.text()));
        page.on(
          'request',
          (request) =>
            (request.method() === 'POST' || request.headers()['x-collaborate-refresh']) &&
            console.log(username, request.method(), request.url())
        );
      }
      await page.goto('/login');
      await page.getByLabel('Username').fill(username);
      await page.getByRole('button', { name: 'Sign in' }).click();
      await page.waitForURL((url) => !url.pathname.startsWith('/login'));
      return new Editor(context, page, routes, testInfo.project.name);
    });
    for (const context of contexts) await context.close();
  },
  /**
   * `outsideSave(id, fill)`: saves the record outside every shared draft, as a browser without JavaScript
   * does (signed in as carol); `fill(editor)` changes the form first.
   */
  outsideSave: async ({ editor }, use) => {
    await use(async (id, fill) => {
      const carol = await editor('carol', { javaScriptEnabled: false });
      await carol.page.goto(carol.routes.edit(id));
      await fill(carol);
      await carol.save();
      await expect(carol.page.locator('.invalid-feedback, .form-error-message')).toHaveCount(0);
      return carol;
    });
  },
});

export { expect };
