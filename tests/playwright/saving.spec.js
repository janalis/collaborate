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
    await expectState(alice, 'saved', { variant: 'success', icon: 'fa-circle-check', text: 'All changes saved.' });
    await expectState(bob, 'saved', {
      variant: 'success',
      icon: 'fa-circle-check',
      text: 'All changes saved by Alice.',
    });
    // Bob's own save is no one else's: his page says the draft is saved, without a name.
    await bob.control('summary').fill('Saved by Bob');
    await bob.synchronized();
    await bob.saveAndStay();
    await expect(bob.bar).toHaveText('All changes saved.');
    await expect(alice.bar).toHaveText('All changes saved by Bob.');
  });

  test('saves from the bar, staying on the page, and offers Save only while changes are unsaved', async ({
    article,
    editor,
  }) => {
    const id = await article({ title: 'Before' });
    const alice = await editor('alice');
    await alice.edit(id);
    await expect(alice.barSaveButton).toBeHidden();

    await alice.control('title').fill('Saved from the bar');
    await expectState(alice, 'draft', { text: 'Unsaved changes, kept automatically. Click Save to record them.' });
    await expect(alice.barSaveButton).toHaveText('Save');
    await expect(alice.barSaveButton).toBeEnabled();
    // Save is the bar's first action; Discard comes after it.
    await expect(alice.barElement.locator('.collaborate-bar__actions button').first()).toHaveAttribute(
      'data-collaborate-save',
      ''
    );
    await alice.synchronized();

    const posted = alice.page.waitForRequest((request) => request.method() === 'POST' && request.isNavigationRequest());
    await alice.barSaveButton.click();
    await posted;
    await alice.page.waitForLoadState();
    await alice.joined();
    await expect(alice.page).toHaveURL(new RegExp(`${alice.routes.edit(id)}`));
    await expectState(alice, 'saved', { text: 'All changes saved.' });
    await expect(alice.control('title')).toHaveValue('Saved from the bar');
    await expect(alice.barSaveButton).toBeHidden();
    await expect(alice.discardButton).toBeHidden();
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
    // The bar's Save posts through the same path as the form's own button.
    await alice.barSaveButton.click();
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
    await expect(bob.bar).toHaveText('All changes saved by Alice.');
  });
});
