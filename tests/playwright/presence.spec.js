import { expect, test } from './lib/app.js';

// Who else is on the page: avatars in the header, a `+N` counter past `cursors.max_avatars` (2 in the test
// applications), departures and rooms.
test.describe('presence', () => {
  test('lists the other collaborators by id, past two as a counter naming the rest', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    const presence = alice.presence;
    // Nobody else: the group is hidden.
    await expect(presence).toBeHidden();

    for (const name of ['dave', 'bob', 'carol']) await (await editor(name)).edit(id);

    const users = presence.locator('.collaborate-presence__user');
    await expect(presence).toBeVisible();
    await expect(presence).toHaveAttribute('aria-label', 'Also on this page');
    // Ordered by id (bob 16, carol 18, dave 19), each an initial on their colour, named for assistive technologies.
    await expect(users).toHaveCount(2);
    await expect(users.nth(0)).toHaveAttribute('title', 'Bob');
    await expect(users.nth(0)).toHaveAttribute('aria-label', 'Bob');
    await expect(users.nth(0)).toHaveAttribute('role', 'img');
    await expect(users.nth(0)).toHaveText('B');
    await expect(users.nth(0)).toHaveAttribute('data-color', /.+/);
    await expect(users.nth(1)).toHaveAttribute('title', 'Carol');
    const more = presence.locator('.collaborate-presence__more');
    await expect(more).toHaveText('+1');
    await expect(more).toHaveAttribute('title', 'Dave');
  });

  test("hides the collaborator's own other tabs", async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    const tab = await alice.context.newPage();
    await tab.goto(alice.routes.edit(id));
    await expect(tab.locator('form[data-collaborate]')).toHaveAttribute('data-collaborate-state', 'saved');
    await tab.mouse.move(100, 200);
    await tab.mouse.move(150, 250, { steps: 3 });
    const bob = await editor('bob');
    await bob.edit(id);

    await expect(alice.presence.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();
    await expect(alice.presence.locator('.collaborate-presence__user[title="Alice"]')).toHaveCount(0);
    await expect(alice.layer.locator('.collaborate-cursor')).toHaveCount(0);
    // Bob sees Alice once, whichever of her tabs he hears.
    await expect(bob.presence.locator('.collaborate-presence__user')).toHaveCount(1);
  });

  test('drops a collaborator whose tab is hidden, and shows them again when it is back', async ({
    article,
    editor,
  }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    const bobAvatar = alice.presence.locator('.collaborate-presence__user[title="Bob"]');
    await expect(bobAvatar).toBeVisible();

    // The browser hides Bob's tab (another tab in front, a minimized window).
    const visibility = (hidden) =>
      bob.page.evaluate((hidden) => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
        Object.defineProperty(document, 'visibilityState', {
          configurable: true,
          get: () => (hidden ? 'hidden' : 'visible'),
        });
        document.dispatchEvent(new Event('visibilitychange'));
      }, hidden);
    await visibility(true);
    await expect(bobAvatar).toHaveCount(0);
    await expect(alice.presence).toBeHidden();

    await visibility(false);
    await expect(bobAvatar).toBeVisible();
  });

  test('drops a collaborator whose tab went silent', async ({ article, editor }) => {
    test.setTimeout(60_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    const bobAvatar = alice.presence.locator('.collaborate-presence__user[title="Bob"]');
    await expect(bobAvatar).toBeVisible();

    // A lost connection sends no departure: Bob's heartbeats stop, and he expires after 15 seconds.
    await bob.context.setOffline(true);
    await expect(bobAvatar).toHaveCount(0, { timeout: 30_000 });
  });

  test('separates pages by their query string, not by locale', async ({ editor, routes }) => {
    const alice = await editor('alice');
    const bob = await editor('bob');
    const carol = await editor('carol');
    await alice.page.goto(`${routes.index}?view=all`);
    await bob.page.goto(`${routes.index}?view=all&_locale=en`);
    await carol.page.goto(`${routes.index}?view=mine`);

    await expect(carol.layer).toHaveAttribute('data-connected', 'true');
    await expect(alice.presence.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();
    await expect(bob.presence.locator('.collaborate-presence__user[title="Alice"]')).toBeVisible();
    await expect(carol.presence).toBeHidden();
    await expect(alice.presence.locator('.collaborate-presence__user[title="Carol"]')).toHaveCount(0);
  });
});
