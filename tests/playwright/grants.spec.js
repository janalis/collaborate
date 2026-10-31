import { expect, expectState, test } from './lib/app.js';

// The signed grants a page carries: the field grant of its form (sent in full, then by its signature) and
// the page grant of its layer, each renewed from the page itself when the server refuses it.
const journal = (action) => `**/admin/collaborate/${action}`;

test.describe('grants', () => {
  test('resend the full field grant when the server no longer knows its signature', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    const grants = [];
    alice.page.on('request', (request) => {
      if (request.url().endsWith('/collaborate/append')) grants.push(request.postDataJSON().grant);
    });
    // Verified once, the grant travels as its signature alone; a server that forgot it answers 412 once.
    await alice.answerOnce(journal('append'), 412);

    await alice.control('title').fill('Sent twice');
    await alice.synchronized();
    await expectState(alice, 'draft');
    await expect(bob.control('title')).toHaveValue('Sent twice');
    expect(grants.length).toBeGreaterThanOrEqual(2);
    expect(grants[0]).not.toContain('.');
    expect(grants[1]).toContain('.');
    await expectState(alice, 'draft');
  });

  /** Alice signs out and in again in another tab: her session, and every CSRF token of the open page, are new. */
  async function signInAgain(alice) {
    const tab = await alice.context.newPage();
    await tab.goto('/logout');
    await tab.getByLabel('Username').fill('alice');
    await tab.getByRole('button', { name: 'Sign in' }).click();
    await tab.waitForURL((url) => !url.pathname.startsWith('/login'));
    await tab.close();
  }

  test('renew a refused field grant from the form, and go on', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await signInAgain(alice);
    const refreshed = alice.page.waitForRequest((request) => request.headers()['x-collaborate-refresh'] === '1');

    // The edit is refused (403): the page reads a fresh grant and token from its form's URL, and sends it again.
    await alice.control('title').fill('After renewal');
    await refreshed;
    await alice.synchronized();
    await expectState(alice, 'draft');
    await expect(bob.control('title')).toHaveValue('After renewal');
  });

  test('renew a refused page grant from the page, and keep the stream', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await signInAgain(alice);

    // The stream drops; reconnecting, the page's hub session is refused and renewed from the page itself.
    const renewed = alice.page.waitForRequest((request) => request.headers()['x-collaborate-renew'] === '1');
    await alice.context.setOffline(true);
    await expect(alice.layer).toHaveAttribute('data-connected', 'false');
    await alice.context.setOffline(false);
    await renewed;

    await expect(alice.layer).toHaveAttribute('data-connected', 'true', { timeout: 30_000 });
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();
    await bob.control('title').fill('Heard on the renewed stream');
    await expect(alice.control('title')).toHaveValue('Heard on the renewed stream');
  });
});
