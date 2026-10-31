import { expect, expectState, test } from './lib/app.js';

// Save: through the application's own path, with the draft's revision; what the other pages hear of it.
test.describe('saving a shared draft', () => {
  test('saves the draft and tells the other pages who saved it', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await bob.control('title').fill('Saved by Alice');
    await expectState(bob, 'draft');
    await expect(alice.control('title')).toHaveValue('Saved by Alice');
    await alice.synchronized();

    await alice.saveAndStay();
    await expectState(alice, 'saved', { variant: 'success', icon: 'fa-circle-check', text: 'Shared draft saved.' });
    await expectState(bob, 'saved', {
      variant: 'success',
      icon: 'fa-circle-check',
      text: 'Shared draft saved by Alice.',
    });
    // Bob's own save is no one else's: his page says the draft is saved, without a name.
    await bob.control('summary').fill('Saved by Bob');
    await bob.synchronized();
    await bob.saveAndStay();
    await expect(bob.bar).toHaveText('Shared draft saved.');
    await expect(alice.bar).toHaveText('Shared draft saved by Bob.');
  });

  test('sends back to the form a save a peer edit overtook, which then saves again', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('title').fill('Alice title');
    await alice.synchronized();

    // Alice's Save is posted; Bob's edit reaches the draft before the server reads it.
    let release;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    let posted;
    const held = new Promise((resolve) => {
      posted = resolve;
    });
    await alice.page.route(
      (url) => url.pathname === alice.routes.edit(id),
      async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        posted();
        await gate;
        await route.continue();
      }
    );
    await alice.page
      .getByRole('button', { name: alice.easyAdmin ? 'Save and continue editing' : 'Save', exact: true })
      .click();
    await held;
    const appended = bob.page.waitForResponse((response) => response.url().endsWith('/collaborate/append'));
    await bob.control('summary').fill('Bob overtook');
    expect((await appended).ok()).toBe(true);
    release();

    await expect(
      alice.page.getByRole('alert').filter({ hasText: 'The shared draft changed while saving.' })
    ).toHaveText(/The shared draft changed while saving\. Review it and save again\./);
    await alice.joined();
    // Both edits are in the draft; the record has neither yet.
    await expect(alice.control('title')).toHaveValue('Alice title');
    await expect(alice.control('summary')).toHaveValue('Bob overtook');
    await expectState(alice, 'draft');

    await alice.page.unrouteAll();
    await alice.saveAndStay();
    await expectState(alice, 'saved');
    await expect(alice.control('summary')).toHaveValue('Bob overtook');
  });

  test('keeps the draft and the submitted values when validation fails', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('rating').fill('9');
    await alice.control('title').fill('Invalid rating');
    await expect(bob.control('rating')).toHaveValue('9');
    await alice.synchronized();

    await alice.saveAndStay();
    await expect(alice.page.getByText('This value should be between 0 and 5.')).toBeVisible();
    await expect(alice.control('rating')).toHaveValue('9');
    await expect(alice.control('title')).toHaveValue('Invalid rating');
    await expectState(alice, 'draft');
    await expectState(bob, 'draft');

    await alice.control('rating').fill('3');
    await expect(bob.control('rating')).toHaveValue('3');
    await alice.synchronized();
    await alice.saveAndStay();
    await expectState(alice, 'saved');
    await expect(bob.bar).toHaveText('Shared draft saved by Alice.');
  });
});
