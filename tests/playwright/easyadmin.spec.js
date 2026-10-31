import { expect, test } from './lib/app.js';

// EasyAdmin pages only: a saved list refreshes itself after a save elsewhere.
test('a saved list shows a record saved by another editor', async ({ article, editor, routes }) => {
  const id = await article({ title: `Listed ${Date.now()}` });
  const alice = await editor('alice');
  const bob = await editor('bob');
  // The list hears of saves once its page's hub stream is open.
  const session = bob.page.waitForResponse(
    (response) => response.url().includes('/.well-known/mercure?') && response.ok()
  );
  await bob.page.goto(routes.index);
  await session;
  await expect(bob.page.locator('body.ea-index')).toBeVisible();
  await expect(bob.page.locator('[data-collaborate-layer][data-updates="true"]')).toBeAttached();

  await alice.edit(id);
  const title = `Renamed ${Date.now()}`;
  await alice.field('Title').fill(title);
  await alice.synchronized();
  await alice.save();

  await expect(bob.page.getByRole('cell', { name: title })).toBeVisible();
});
