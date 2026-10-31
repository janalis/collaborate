import { test as base, expect } from '@playwright/test';

// The pages of each test application, by Playwright project name.
const routes = {
  plain: {
    index: '/admin/articles',
    edit: (id) => `/admin/articles/${id}/edit`,
    new: '/admin/articles/new',
  },
  easyadmin: {
    index: '/admin/article',
    edit: (id) => `/admin/article/${id}/edit`,
    new: '/admin/article/new',
  },
};

/** An editor of the application: a browser context signed in as `username`. */
class Editor {
  constructor(context, page, routes) {
    this.context = context;
    this.page = page;
    this.routes = routes;
  }

  get form() {
    return this.page.locator('form[data-collaborate]');
  }

  get bar() {
    return this.page.locator('[data-collaborate-bar] [data-collaborate-status]');
  }

  field(label) {
    return this.form.getByLabel(label, { exact: true });
  }

  get richText() {
    return this.form.locator('.ck-editor__editable');
  }

  /** Opens `path` and waits until its shared draft has joined and unlocked the form. */
  async open(path) {
    await this.page.goto(path);
    await this.joined();
  }

  async joined() {
    await expect(this.form).toHaveAttribute('data-collaborate-state', /^(saved|draft)$/);
    await expect(this.form.locator('[inert]')).toHaveCount(0);
    await expect(this.richText).toHaveAttribute('contenteditable', 'true');
  }

  async edit(id) {
    await this.open(this.routes.edit(id));
  }

  /** Submits the form with the page's main save button (EasyAdmin's sit outside the form) and waits for the page it leads to. */
  async save() {
    const posted = this.page.waitForRequest((request) => request.method() === 'POST' && request.isNavigationRequest());
    await this.page
      .getByRole('button', { name: /^(Save|Save changes|Create)$/ })
      .first()
      .click();
    await posted;
    await this.page.waitForLoadState();
  }

  /** Waits until this page's edits have reached the shared draft. */
  async synchronized() {
    await expect(this.form).not.toHaveAttribute('data-collaborate-state', /pending|connecting/);
  }
}

export const test = base.extend({
  /** A new article of this scenario's own: `article({title})` resolves its id. */
  article: async ({ request, baseURL }, use, testInfo) => {
    await use(async (data = {}) => {
      const response = await request.post(`${baseURL}/e2e/articles`, {
        data: { title: `${testInfo.title} ${Date.now()}`, ...data },
      });
      expect(response.status()).toBe(201);
      return (await response.json()).id;
    });
  },
  routes: async ({ baseURL: _baseURL }, use, testInfo) => {
    await use(routes[testInfo.project.name]);
  },
  /** `editor('alice')`: a signed-in browser context of its own, closed after the scenario. */
  editor: async ({ browser, baseURL, routes }, use) => {
    const contexts = [];
    await use(async (username) => {
      const context = await browser.newContext({ baseURL });
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
      return new Editor(context, page, routes);
    });
    for (const context of contexts) await context.close();
  },
});

export { expect };
