import { expect, expectState, test } from './lib/app.js';

// A page left and restored through the back/forward cache: its draft is suspended, then renews its
// subscription, its grant and its saved baseline, and sends what it kept. Playwright's headless shell and
// its default flags disable the cache; the full Chromium build, without that flag, keeps it.
test.use({ channel: 'chromium', launchOptions: { ignoreDefaultArgs: ['--disable-back-forward-cache'] } });

test.describe('a page restored from the back/forward cache', () => {
  test('catches up with the draft and its peers', async ({ article, editor, routes }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(bob.presence.locator('[title="Alice"]')).toBeVisible();
    await alice.page.evaluate(() => {
      window.restoredFromCache = true;
    });

    // Alice leaves for the list: her page is cached, and she leaves Bob's page.
    await alice.page.goto(routes.index);
    await expect(bob.presence.locator('[title="Alice"]')).toHaveCount(0);
    await bob.control('title').fill('Written while Alice was away');
    await bob.synchronized();

    await alice.page.goBack({ waitUntil: 'commit' });
    expect(await alice.page.evaluate(() => window.restoredFromCache)).toBe(true);
    await expect(alice.control('title')).toHaveValue('Written while Alice was away');
    await expectState(alice, 'draft');
    await alice.control('summary').fill('Written after coming back');
    await expect(bob.control('summary')).toHaveValue('Written after coming back');
    // She is on the page again for Bob, and Bob for her.
    await expect(bob.presence.locator('[title="Alice"]')).toBeVisible();
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();
  });
});
