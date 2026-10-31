import { expect, test } from './lib/app.js';

// EasyAdmin pages only: saved lists, details and the lists embedded in a form catch up with a save made
// elsewhere, without disrupting whoever is reading them.

/** Opens an EasyAdmin page and waits until it follows the saved-views topic. */
async function watch(editor, path) {
  const opened = editor.page.waitForResponse(
    (response) => response.url().includes('/.well-known/mercure?') && response.ok()
  );
  await editor.page.goto(path);
  await opened;
  await expect(editor.page.locator('[data-collaborate-layer][data-updates="true"]')).toBeAttached();
  // A marker that a reload clears.
  await editor.page.evaluate(() => {
    window.beforeRefresh = true;
  });
}

/** Whether the page reloaded since it was marked (`null` while it is navigating). */
const reloaded = (editor) => editor.page.evaluate(() => window.beforeRefresh !== true).catch(() => null);

/** Renames an article through Alice's edit page. */
async function rename(alice, id, title) {
  await alice.edit(id);
  await alice.control('title').fill(title);
  await alice.synchronized();
  await alice.save();
}

test('a saved list shows a record saved by another editor', async ({ article, editor, routes }) => {
  const id = await article({ title: `Listed ${Date.now()}` });
  const alice = await editor('alice');
  const bob = await editor('bob');
  await watch(bob, routes.index);
  await expect(bob.page.locator('body.ea-index')).toBeVisible();

  const title = `Renamed ${Date.now()}`;
  await rename(alice, id, title);

  await expect(bob.page.getByRole('cell', { name: title })).toBeVisible();
});

test('a detail page shows its record saved by another editor', async ({ article, editor, routes }) => {
  const id = await article();
  const alice = await editor('alice');
  const bob = await editor('bob');
  await watch(bob, routes.detail(id));
  await expect(bob.page.locator('body.ea-detail')).toBeVisible();

  const title = `Detailed ${Date.now()}`;
  await rename(alice, id, title);

  await expect(bob.page.getByText(title).first()).toBeVisible();
});

test('a refreshed list keeps its scroll position, its ticked rows and the focused row', async ({
  article,
  editor,
  routes,
}) => {
  const id = await article();
  for (let index = 0; index < 4; index++) await article();
  const alice = await editor('alice');
  const bob = await editor('bob', { viewport: { width: 1280, height: 400 } });
  await watch(bob, routes.index);
  const row = (page) => page.locator(`.form-batch-checkbox[value="${id}"]`);
  await row(bob.page).scrollIntoViewIfNeeded();
  await bob.page.evaluate(() => window.scrollBy(0, 37));
  await row(bob.page).check();
  await expect(row(bob.page)).toBeFocused();
  expect(await bob.page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  // Where the page stood when it reloaded: the window's name outlives the reload.
  await bob.page.evaluate(() =>
    addEventListener('beforeunload', () => {
      window.name = String(window.scrollY);
    })
  );

  const title = `Kept in view ${Date.now()}`;
  await rename(alice, id, title);

  await expect.poll(() => reloaded(bob)).toBe(true);
  await expect(bob.page.getByRole('cell', { name: title })).toBeVisible();
  await expect(row(bob.page)).toBeChecked();
  await expect(row(bob.page)).toBeFocused();
  const scrolled = Number(await bob.page.evaluate(() => window.name));
  expect(scrolled).toBeGreaterThan(0);
  await expect.poll(() => bob.page.evaluate(() => window.scrollY)).toBe(scrolled);
});

test('a list waits while search text is typed and not submitted', async ({ article, editor, routes }) => {
  const id = await article();
  const alice = await editor('alice');
  const bob = await editor('bob');
  await watch(bob, routes.index);
  // Count the checks a waiting refresh makes, once a second.
  await bob.page.evaluate(() => {
    window.refreshChecks = 0;
    const query = document.querySelector.bind(document);
    document.querySelector = (selector) => {
      if (String(selector).includes('.offcanvas.show')) window.refreshChecks++;
      return query(selector);
    };
  });
  const search = bob.page.locator('input[name="query"]');
  await search.fill('typed, not submitted');
  await search.blur();

  const title = `Waited for ${Date.now()}`;
  await rename(alice, id, title);

  // The refresh has looked twice and waited: the page and its typed text are still there.
  await expect.poll(() => bob.page.evaluate(() => window.refreshChecks), { timeout: 10_000 }).toBeGreaterThan(1);
  expect(await reloaded(bob)).toBe(false);
  await expect(search).toHaveValue('typed, not submitted');

  // Once the text is gone, the refresh runs.
  await search.fill('');
  await search.blur();
  await expect.poll(() => reloaded(bob)).toBe(true);
  await expect(bob.page.getByRole('cell', { name: title })).toBeVisible();
});

test('a list refreshes when its lost stream comes back', async ({ article, editor, routes }) => {
  test.setTimeout(90_000);
  const id = await article();
  const alice = await editor('alice');
  const bob = await editor('bob');
  await watch(bob, routes.index);

  // Bob's connection drops while Alice saves: the hub keeps no history, so the save is not heard.
  await bob.context.setOffline(true);
  const title = `Missed ${Date.now()}`;
  await rename(alice, id, title);
  await bob.context.setOffline(false);

  await expect.poll(() => reloaded(bob), { timeout: 60_000 }).toBe(true);
  await expect(bob.page.getByRole('cell', { name: title })).toBeVisible();
});

test('a form refreshes the lists embedded in it, not itself', async ({ article, editor }) => {
  const id = await article();
  const other = await article();
  const alice = await editor('alice');
  const bob = await editor('bob');
  await bob.edit(id);
  const list = bob.page.getByRole('region', { name: 'Latest articles' });
  await expect(list.locator('li').first()).toBeVisible();
  await bob.page.evaluate(() => {
    window.beforeRefresh = true;
  });
  await bob.control('summary').fill('Unsaved while the list refreshes');
  await bob.control('summary').blur();

  const title = `Embedded ${Date.now()}`;
  await rename(alice, other, title);

  await expect(list.getByText(title)).toBeVisible();
  expect(await reloaded(bob)).toBe(false);
  await expect(bob.control('summary')).toHaveValue('Unsaved while the list refreshes');
});
