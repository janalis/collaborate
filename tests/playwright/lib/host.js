import { expect } from '@playwright/test';

// The host library (tests/Application/Host): its readers, and what it tells them.

/** A reader: a browser context signed in as `username`, on `path` (a folder of the library). */
export async function signedIn(browser, baseURL, username, path = '/admin/library/music', options = {}) {
  const context = await browser.newContext({ baseURL, ...options });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Username').fill(username);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await page.goto(path);
  return { context, page };
}

/** The layer of a page's cursors, `data-connected` once the hub answered it. */
export const layer = (page) => page.locator('[data-collaborate-layer]');

/** The library tells somebody something, as its own code would. */
export async function notify(request, to, record, type = 'record_shared') {
  const parameters = type === 'record_shared' ? { '%name%': 'Bob', '%record%': record } : { '%text%': record };
  const response = await request.post('/e2e/notify', {
    data: { to, type, parameters, url: '/admin/library/films' },
  });
  expect(response.ok()).toBeTruthy();
}
