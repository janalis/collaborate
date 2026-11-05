import { expect, test } from '@playwright/test';

// A host whose pages are no forms (tests/Application/Host): the readers of a folder of a library see each
// other's pointers on its records, whatever the view, the address or the scroll, in a popup of a record, and
// find each other from the presence menu. Every cursor option is on.

const BOB = 16;

/** A reader: a browser context signed in as `username`, on a folder of the library. */
async function reader(browser, baseURL, username, path = '/admin/library/music', options = {}) {
  const context = await browser.newContext({ baseURL, ...options });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Username').fill(username);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await page.goto(path);
  return { context, page };
}

const layer = (page) => page.locator('[data-collaborate-layer]');
const record = (page, key) => page.locator(`#listing [data-key="${key}"]`);
const cursorOf = (page, userId = BOB) => layer(page).locator(`.collaborate-cursor[data-user-id="${userId}"]`);
const toggle = (page) => page.locator('.collaborate-presence__toggle');
const entryOf = (page, name) =>
  page.locator('.collaborate-presence__menu .collaborate-presence__item').filter({ hasText: name });

/** The pages are shared once the hub answered each. */
async function connected(...pages) {
  for (const page of pages) await expect(layer(page)).toHaveAttribute('data-connected', 'true');
}

/** Moves the mouse onto `locator` until the other page draws the pointer over `target`. */
async function pointAt(locator, cursor, target = null, fx = 0.3, fy = 0.5) {
  await expect(async () => {
    const box = await locator.boundingBox();
    await locator.page().mouse.move(box.x + box.width * fx - 3, box.y + box.height * fy - 2);
    await locator.page().mouse.move(box.x + box.width * fx, box.y + box.height * fy, { steps: 2 });
    await expect(cursor).toBeVisible({ timeout: 1000 });
    if (target) {
      const [where, drawn] = [await target.boundingBox(), await cursor.evaluate((element) => element.style.transform)];
      const [, x, y] = /translate3d\(([-\d.]+)px, ([-\d.]+)px/.exec(drawn).map(Number);
      expect(x).toBeGreaterThanOrEqual(where.x - 1);
      expect(x).toBeLessThanOrEqual(where.x + where.width + 1);
      expect(y).toBeGreaterThanOrEqual(where.y - 1);
      expect(y).toBeLessThanOrEqual(where.y + where.height + 1);
    }
  }).toPass({ timeout: 20000 });
}

async function openMenu(page) {
  if ((await toggle(page).getAttribute('aria-expanded')) !== 'true') await toggle(page).click();
  await expect(page.locator('.collaborate-presence__menu')).toBeVisible();
}

test.describe('a host library', () => {
  let alice;
  let bob;

  test.beforeEach(async ({ browser, baseURL, request }) => {
    await request.post('/e2e/folders/music/revoke', { data: { revoked: false } });
    alice = await reader(browser, baseURL, 'alice');
    bob = await reader(browser, baseURL, 'bob', '/admin/library/music?view=tiles');
  });
  test.afterEach(async () => {
    await alice?.context.close();
    await bob?.context.close();
  });

  test('the readers of a folder see each other, and a pointer on a record in the other view', async () => {
    await connected(alice.page, bob.page);
    await expect(toggle(alice.page)).toHaveAttribute('aria-label', 'B: Bob');
    await expect(toggle(bob.page)).toHaveAttribute('aria-label', 'A: Alice');
    // Bob points at the name of a tile; Alice, in the list, sees it on the row of the same record.
    const cursor = cursorOf(alice.page);
    await pointAt(record(bob.page, 'music-2').locator('.name'), cursor, record(alice.page, 'music-2'));
    await expect(cursor).toHaveAttribute('data-region', 'listing');
    await expect(cursor.locator('.collaborate-cursor__label')).toHaveText('Bob');
    // The record is marked with his initial.
    const marker = layer(alice.page)
      .locator('.collaborate-marker')
      .filter({ has: alice.page.locator('.collaborate-marker__user') });
    await expect(marker).toHaveAttribute('title', 'Bob');
    await expect(marker.locator('.collaborate-marker__user')).toHaveText('B');
    // The menu says where he is.
    await openMenu(alice.page);
    await expect(entryOf(alice.page, 'Bob')).toContainText('on music-2.mp3');
  });

  test('two addresses of a folder are one place; another folder is another', async ({ browser, baseURL }) => {
    const home = await reader(browser, baseURL, 'carol', '/admin/homes/carol/music');
    const films = await reader(browser, baseURL, 'dave', '/admin/library/films');
    try {
      await connected(alice.page, home.page, films.page);
      await expect(toggle(alice.page)).toHaveAttribute('aria-label', 'B C: Bob, Carol');
      await expect(films.page.locator('[data-collaborate-presence]')).toBeHidden();
    } finally {
      await home.context.close();
      await films.context.close();
    }
  });

  test('a reader who loads another folder in place of the listing leaves this one, and comes back', async () => {
    await connected(alice.page, bob.page);
    await expect(toggle(alice.page)).toHaveAttribute('aria-label', 'B: Bob');
    await bob.page.getByRole('link', { name: 'films' }).click();
    await expect(bob.page.locator('.listing')).toHaveAttribute('data-folder', 'films');
    await expect(alice.page.locator('[data-collaborate-presence]')).toBeHidden();
    await bob.page.getByRole('link', { name: 'music' }).click();
    await expect(toggle(alice.page)).toHaveAttribute('aria-label', 'B: Bob');
  });

  test('the readers of a popup of a record see each other in it and type, above the open dialog', async () => {
    await connected(alice.page, bob.page);
    for (const { page } of [alice, bob]) {
      await record(page, 'music-1').getByRole('button', { name: 'Share' }).click();
      await expect(page.locator('#share')).toBeVisible();
    }
    await expect(alice.page.locator('#share [data-collaborate-popup-presence]')).toContainText('Also sharing: Bob.');
    await expect(bob.page.locator('#share [data-collaborate-popup-presence]')).toContainText('Also sharing: Alice.');
    // Bob types in the popup: his caret is drawn over Alice's field, in the layer above her modal dialog.
    const message = bob.page.locator('#share input[name="message"]');
    await message.fill('Hello');
    await message.press('End');
    const caret = layer(alice.page).locator(`.collaborate-caret[data-user-id="${BOB}"]`);
    await expect(caret).toBeVisible();
    await pointAt(bob.page.locator('#share-title'), cursorOf(alice.page), alice.page.locator('#share-title'));
    expect(await layer(alice.page).evaluate((element) => element.matches(':popover-open'))).toBe(true);
    // A search typed in the toolbar is the reader's own.
    await bob.page.locator('#share [data-close]').click();
    await bob.page.locator('input[name="q"]').fill('beat');
    await expect(caret).toHaveCount(0);
    await expect(alice.page.locator('#share [data-collaborate-popup-presence]')).toBeEmpty();
  });

  test('a pointer scrolled out of sight is an arrow, and its reader is found from the menu', async () => {
    await connected(alice.page, bob.page);
    const last = record(bob.page, 'music-40');
    await last.scrollIntoViewIfNeeded();
    const edge = layer(alice.page).locator('.collaborate-edge');
    await expect(async () => {
      await last.locator('.name').hover();
      await expect(edge).toBeVisible({ timeout: 1000 });
    }).toPass({ timeout: 20000 });
    await expect(edge).toHaveAttribute('data-side', 'below');
    await expect(edge).toHaveAttribute('title', 'Bob');
    await expect(cursorOf(alice.page)).toBeHidden();
    await openMenu(alice.page);
    await entryOf(alice.page, 'Bob').click();
    await expect(record(alice.page, 'music-40')).toBeInViewport();
    await expect(cursorOf(alice.page)).toBeVisible();
    await expect(cursorOf(alice.page)).toHaveClass(/collaborate-pulse/);
    await expect(edge).toBeHidden();
    await expect(alice.page.locator('.collaborate-announce')).toHaveText('Bob is on music-40.mp3');
  });

  test('a still pointer goes idle, and wakes as it moves', async () => {
    await connected(alice.page, bob.page);
    const cursor = cursorOf(alice.page);
    await pointAt(record(bob.page, 'music-1').locator('.name'), cursor);
    await expect(cursor).toHaveClass(/collaborate-cursor--idle/, { timeout: 15000 });
    await openMenu(alice.page);
    await expect(entryOf(alice.page, 'Bob')).toContainText('idle');
    await alice.page.keyboard.press('Escape');
    await pointAt(record(bob.page, 'music-3').locator('.name'), cursor, null, 0.6);
    await expect(cursor).not.toHaveClass(/collaborate-cursor--idle/);
  });

  test('a reader hides the pointers of the others in every tab, and keeps seeing who they are', async () => {
    await connected(alice.page, bob.page);
    const second = await alice.context.newPage();
    await second.goto('/admin/library/music');
    const cursor = cursorOf(alice.page);
    await pointAt(record(bob.page, 'music-2').locator('.name'), cursor);
    await openMenu(alice.page);
    const pointers = alice.page.locator('.collaborate-presence__pointers');
    await expect(pointers).toHaveAttribute('aria-checked', 'true');
    await pointers.click();
    await expect(layer(alice.page)).toHaveAttribute('data-hidden', 'true');
    await expect(cursor).toBeHidden();
    await expect(toggle(alice.page)).toHaveAttribute('aria-label', 'B: Bob');
    // The other tab of the browser follows.
    await expect(layer(second)).toHaveAttribute('data-hidden', 'true');
    await openMenu(alice.page);
    await pointers.click();
    await expect(layer(second)).toHaveAttribute('data-hidden', 'false');
    await pointAt(record(bob.page, 'music-3').locator('.name'), cursor, null, 0.7);
  });

  test('a folder revoked stops its pages from sharing', async ({ request }) => {
    await connected(alice.page, bob.page);
    await expect(toggle(bob.page)).toHaveAttribute('aria-label', 'A: Alice');
    await request.post('/e2e/folders/music/revoke', { data: { revoked: true } });
    // The next event of either page is refused, and the server refuses the page's session it asks again for.
    const refused = (page) =>
      page.waitForResponse((response) => response.url().endsWith('/cursors/session') && response.status() === 403);
    const sessions = Promise.all([refused(alice.page), refused(bob.page)]);
    await bob.page.mouse.move(100, 200);
    await bob.page.mouse.move(150, 250);
    await sessions;
    for (const { page } of [alice, bob]) await expect(layer(page)).toHaveAttribute('data-connected', 'false');
    await expect(alice.page.locator('[data-collaborate-presence]')).toBeHidden();
  });
});
