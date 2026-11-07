import { expect, test } from '@playwright/test';

// Notifications in the host library (tests/Application/Host): the bell of a page counts what its reader is
// told at once, over the stream the page already holds; a reader leaves a topic and is told nothing more of
// it; and web push shows a system notification only when no page of the library is open.

const ALICE = 15;
const READER = 17;

/** A browser context signed in as `username`, on `path`. */
async function signedIn(browser, baseURL, username, path = '/admin/library/music', options = {}) {
  const context = await browser.newContext({ baseURL, ...options });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Username').fill(username);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await page.goto(path);
  return { context, page };
}

const toggle = (page) => page.locator('.collaborate-notifications__toggle');
const count = (page) => page.locator('.collaborate-notifications__count');
const layer = (page) => page.locator('[data-collaborate-layer]');

/** The stream of a page is open: the hub answered it (a bare layer runs no cursors, which mark it connected). */
const streaming = (page) =>
  page.waitForResponse((response) => response.url().includes('/.well-known/mercure') && response.status() === 200);

/** The library tells somebody something, as its own code would. */
async function notify(request, to, record, type = 'record_shared') {
  const parameters = type === 'record_shared' ? { '%name%': 'Bob', '%record%': record } : { '%text%': record };
  const response = await request.post('/e2e/notify', {
    data: { to, type, parameters, url: '/admin/library/films' },
  });
  expect(response.ok()).toBeTruthy();
}

test.describe('notifications', () => {
  // The scenarios of the cursors revoke a folder: the library is whole again for these.
  test.beforeEach(async ({ request }) => {
    expect((await request.post('/e2e/folders/music/revoke', { data: { revoked: false } })).ok()).toBeTruthy();
  });

  test('a page counts what its reader is told at once, and a click reads it', async ({ browser, baseURL, request }) => {
    const alice = await signedIn(browser, baseURL, 'alice');
    const reader = await signedIn(browser, baseURL, 'reader');
    try {
      await expect(layer(alice.page)).toHaveAttribute('data-connected', 'true');
      await expect(count(alice.page)).toBeHidden();
      const marker = `Song ${Date.now()}`;
      await notify(request, ALICE, marker);
      await expect(count(alice.page)).toHaveText(/^[1-9]/);
      await expect(alice.page.locator('.toast').filter({ hasText: marker })).toBeVisible();
      // The other reader is told nothing.
      await expect(reader.page.locator('.toast')).toHaveCount(0);

      await toggle(alice.page).click();
      const item = alice.page.locator('.collaborate-notification').filter({ hasText: marker });
      await expect(item).toHaveAttribute('data-unread', 'true');
      await item.click();
      await alice.page.waitForURL('**/admin/library/films');
      await alice.page.goto('/admin/notifications/all');
      await expect(alice.page.locator('.collaborate-notification-row').filter({ hasText: marker })).toHaveAttribute(
        'data-unread',
        'false'
      );
    } finally {
      await alice.context.close();
      await reader.context.close();
    }
  });

  test('a page in no place, and a reader who may not collaborate, still hear of theirs', async ({
    browser,
    baseURL,
    request,
  }) => {
    const reader = await signedIn(browser, baseURL, 'reader');
    try {
      const open = streaming(reader.page);
      await reader.page.goto('/admin/notifications/all');
      await open;
      const marker = `Paper ${Date.now()}`;
      await notify(request, READER, marker);
      await expect(reader.page.locator('.toast').filter({ hasText: marker })).toBeVisible();
      // The page of them all lists it too.
      await expect(reader.page.locator('.collaborate-notification-row').filter({ hasText: marker })).toBeVisible();
    } finally {
      await reader.context.close();
    }
  });

  test('a reader who leaves a topic is told nothing of it, and a mandatory one still reaches them', async ({
    browser,
    baseURL,
    request,
  }) => {
    const reader = await signedIn(browser, baseURL, 'reader', '/admin/notifications/topics');
    try {
      const shares = reader.page.getByRole('switch', { name: 'Shares' });
      await expect(shares).toBeChecked();
      await expect(reader.page.getByRole('switch', { name: 'Announcements' })).toBeDisabled();
      await shares.uncheck();
      await expect(reader.page.locator('.collaborate-notification-topics__status')).toContainText('Shares');
      const open = streaming(reader.page);
      await reader.page.goto('/admin/library/music');
      await open;
      const left = `Left ${Date.now()}`;
      await notify(request, READER, left);
      const kept = `Kept ${Date.now()}`;
      await notify(request, READER, kept, 'announcement');
      await expect(reader.page.locator('.toast').filter({ hasText: kept })).toBeVisible();
      await expect(reader.page.locator('.toast').filter({ hasText: left })).toHaveCount(0);
    } finally {
      await reader.page.goto('/admin/notifications/topics');
      await reader.page.getByRole('switch', { name: 'Shares' }).check();
      await expect(reader.page.locator('.collaborate-notification-topics__status')).toContainText('Shares');
      await reader.context.close();
    }
  });

  test('web push shows in the page while one is open, and in the system once none is', async ({ browser, baseURL }) => {
    const alice = await signedIn(browser, baseURL, 'alice', '/admin/library/music', { permissions: ['notifications'] });
    try {
      // A context of Playwright is incognito, where Chrome has no Push API: the scenario needs a browser that has one.
      const supported = await alice.page.evaluate(
        async (key) => {
          try {
            const registration = await navigator.serviceWorker.register('/admin/notifications/push/worker.js', {
              scope: '/',
            });
            const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 5000));
            const subscription = await Promise.race([
              registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }),
              timeout,
            ]);
            await subscription.unsubscribe();
            return true;
          } catch {
            return false;
          }
        },
        JSON.parse(
          await alice.page.locator('[data-collaborate-notifications]').getAttribute('data-collaborate-notifications')
        ).push.key
      );
      test.skip(!supported, 'This browser has no push service to subscribe with.');
      const device = alice.page.locator('.collaborate-notifications__push');
      await toggle(alice.page).click();
      await expect(device).toBeVisible();
      const subscribed = alice.page
        .waitForResponse((response) => response.url().endsWith('/notifications/push/subscribe'), { timeout: 10_000 })
        .catch(() => null);
      await device.click();
      const answer = await subscribed;
      // A context of Playwright is incognito, where Chrome has no Push API: nothing is asked of the server.
      test.skip(!answer?.ok(), 'The browser has no push service to subscribe with.');
      await expect(device).toHaveAttribute('aria-checked', 'true');
      const registration = await alice.page.evaluate(async () => {
        const worker = await navigator.serviceWorker.ready;
        return worker.scope;
      });
      expect(registration).toBe(`${baseURL}/`);

      // A push the worker hears while the page is open: in the page, no system notification.
      const cdp = await alice.context.newCDPSession(alice.page);
      await cdp.send('ServiceWorker.enable');
      const versions = new Promise((resolve) => cdp.on('ServiceWorker.workerRegistrationUpdated', resolve));
      const { registrations } = await versions;
      const id = registrations.find((entry) => entry.scopeURL === `${baseURL}/`).registrationId;
      const deliver = (data) =>
        cdp.send('ServiceWorker.deliverPushMessage', {
          origin: baseURL,
          registrationId: id,
          data: JSON.stringify(data),
        });
      await deliver({
        id: 900001,
        title: 'New notification',
        body: 'Pushed in',
        url: '/admin/library/films',
        tag: 't1',
      });
      await expect(alice.page.locator('.toast').filter({ hasText: 'Pushed in' })).toBeVisible();
      const shown = () =>
        alice.page.evaluate(async () => (await (await navigator.serviceWorker.ready).getNotifications()).length);
      expect(await shown()).toBe(0);
    } finally {
      await alice.context.close();
    }
  });
});
