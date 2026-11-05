import { expect, expectState, test } from './lib/app.js';

// Discard shared draft: asked first, then reset for everyone; each page learns who did it.
test.describe('discarding a shared draft', () => {
  test('asks first, with Cancel focused, and keeps the draft when cancelled or dismissed', async ({
    article,
    editor,
  }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Kept unsaved');
    await alice.synchronized();

    await alice.discardButton.click();
    await expect(alice.dialog.getByRole('heading')).toHaveText('Discard unsaved changes?');
    await expect(alice.dialog).toContainText(
      'The unsaved changes are erased: yours and those of everyone editing this record. To keep them, cancel and click Save.'
    );
    await expect(alice.dialog.locator('[data-collaborate-confirm-accept]')).toHaveText('Erase changes');
    await alice.decide(false);
    await expectState(alice, 'draft');

    await alice.discardButton.click();
    await expect(alice.dialog.locator('[data-collaborate-confirm-cancel]')).toBeFocused();
    await alice.page.keyboard.press('Escape');
    await expect(alice.dialog).toBeHidden();
    await expectState(alice, 'draft');
    await expect(alice.control('title')).toHaveValue('Kept unsaved');
  });

  test('reloads the discarding page on the saved record, saying why once', async ({ article, editor }) => {
    const id = await article({ title: 'Saved title' });
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Thrown away');
    await alice.synchronized();

    await alice.discardButton.click();
    await alice.decide(true);
    await expect(alice.notice).toHaveText('You discarded the shared draft.');
    await expect(alice.announcer).toHaveText('You discarded the shared draft.');
    await expect(alice.control('title')).toHaveValue('Saved title');
    await expectState(alice, 'saved');

    await alice.notice.getByRole('button', { name: 'Close' }).click();
    await expect(alice.notice).toHaveCount(0);
    // The notice is kept for one load only.
    await alice.edit(id);
    await expect(alice.notice).toHaveCount(0);
  });

  test('keeps the unsaved content of another page, which says who reset the draft', async ({ article, editor }) => {
    const id = await article({ summary: 'Saved summary' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await bob.control('summary').fill('Bob unsaved');
    await expect(alice.control('summary')).toHaveValue('Bob unsaved');
    await alice.synchronized();

    await alice.discardButton.click();
    await alice.decide(true);
    await expect(alice.control('summary')).toHaveValue('Saved summary');

    await expectState(bob, 'discarded', {
      variant: 'warning',
      icon: 'fa-rotate-left',
      text: 'Alice reset the shared draft. Your local content is retained; copy unsaved changes before reloading.',
    });
    await expect(bob.control('summary')).toHaveValue('Bob unsaved');
    await expect(bob.discardButton).toBeDisabled();
  });
});
