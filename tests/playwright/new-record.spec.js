import { expect, expectState, test } from './lib/app.js';

// A new-record page owns its draft: others join it through its invitation link, and it creates its record once.
test.describe('a new-record draft', () => {
  test('copies its invitation link to the clipboard', async ({ editor, routes }) => {
    const alice = await editor('alice', { permissions: ['clipboard-read', 'clipboard-write'] });
    await alice.open(routes.new);
    const invitation = await alice.form.evaluate((form) => JSON.parse(form.dataset.collaborate).invitation);

    const button = alice.barElement.getByRole('button', { name: 'Copy invitation link' });
    await button.evaluate((element) => {
      window.labels = [];
      new MutationObserver(() => window.labels.push(element.textContent)).observe(element, {
        subtree: true,
        childList: true,
        characterData: true,
      });
    });
    await button.click();
    await expect.poll(() => alice.page.evaluate(() => window.labels)).toContain('Copied');
    await expect(alice.announcer).toHaveText('Invitation link copied.');
    expect(await alice.page.evaluate(() => navigator.clipboard.readText())).toBe(
      new URL(invitation, alice.page.url()).href
    );
    await expect(button).toHaveText('Copy invitation link');
  });

  test('shows its invitation link selected where the clipboard refuses', async ({ editor, routes }) => {
    const alice = await editor('alice');
    await alice.page.addInitScript(() => {
      navigator.clipboard.writeText = () => Promise.reject(new DOMException('Refused.', 'NotAllowedError'));
    });
    await alice.open(routes.new);
    const invitation = await alice.form.evaluate((form) => JSON.parse(form.dataset.collaborate).invitation);

    await alice.barElement.getByRole('button', { name: 'Copy invitation link' }).click();
    const link = alice.barElement.locator('[data-collaborate-invitation-url]');
    await expect(alice.barElement).toContainText('Copy this link to invite another editor:');
    await expect(link).toHaveValue(new URL(invitation, alice.page.url()).href);
    await expect(link).toBeFocused();
    expect(await link.evaluate((input) => [input.selectionStart, input.selectionEnd])).toEqual([
      0,
      new URL(invitation, alice.page.url()).href.length,
    ]);
  });

  test('is not shared with another new-record page opened without the link', async ({ editor, routes }) => {
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.open(routes.new);
    await bob.open(routes.new);

    await alice.control('title').fill('Only Alice');
    await alice.synchronized();
    await bob.control('summary').fill('Only Bob');
    await bob.synchronized();
    await expect(bob.control('title')).toHaveValue('');
    await expect(alice.control('summary')).toHaveValue('');
  });

  test('tells every page of the draft it created its record, and the pages that join later', async ({
    editor,
    routes,
  }) => {
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.open(routes.new);
    const invitation = await alice.form.evaluate((form) => JSON.parse(form.dataset.collaborate).invitation);
    await bob.open(invitation);
    // Alice's second tab on the same draft is hers: it names no one.
    const aliceTab = await alice.context.newPage();
    const second = new alice.constructor(alice.context, aliceTab, alice.routes, alice.project);
    await second.open(invitation);
    await alice.control('title').fill('Created once');
    await expect(bob.control('title')).toHaveValue('Created once');
    await alice.synchronized();

    await alice.save();
    await expect(alice.page).not.toHaveURL(/\/new|draft=/);

    await expectState(bob, 'created', {
      variant: 'success',
      icon: 'fa-circle-check',
      text: 'Alice created this record. Local changes are retained; copy unsent content before opening the saved record.',
    });
    await expect(bob.discardButton).toBeDisabled();
    await expectState(second, 'created', {
      text: 'This draft has created its record. Local changes are retained; copy unsent content before opening the saved record.',
    });
    // Bob's Save cannot create it a second time: his page stays.
    const url = bob.page.url();
    await bob.saveButton.click();
    await expectState(bob, 'created');
    expect(bob.page.url()).toBe(url);

    // A page opening the invitation now is told at once.
    const carol = await editor('carol');
    await carol.page.goto(invitation);
    await expectState(carol, 'created', { text: /Alice created this record\./ });
    await carol.page.locator('[data-collaborate-open-record]').click();
    await carol.joined();
    await expect(carol.control('title')).toHaveValue('Created once');
    await expect(carol.page).toHaveURL(/\/\d+\/edit$/);
  });
});
