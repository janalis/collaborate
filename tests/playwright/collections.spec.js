import { expect, test } from './lib/app.js';

// A form collection (an article's tags, each with a name, a hidden position and a rich-text note) edited by
// two editors: rows added, removed and reordered on one page are on the other's too.
test.describe('a shared collection', () => {
  test('shows a row a peer adds, with the widgets the host builds on its rows', async ({ article, editor }) => {
    const id = await article({ tags: ['alpha'] });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    const row = await bob.addTag();
    await row.locator('input[name$="[name]"]').fill('gamma');
    await expect(row.locator('.ck-editor__editable')).toBeVisible();
    await row.locator('.ck-editor__editable').click();
    await bob.page.keyboard.type('Added by Bob');

    await expect(alice.tagRows).toHaveCount(2);
    await expect.poll(() => alice.tagNames()).toEqual(['alpha', 'gamma']);
    // The row arrives as the bare prototype: the host's row initializer builds its editor.
    const added = alice.tagRows.nth(1);
    await expect(added.locator('textarea[data-rich-text]')).toHaveAttribute('data-editor', 'ready');
    await expect(added.locator('.ck-editor__editable')).toHaveText('Added by Bob');
    await added.locator('.ck-editor__editable').click();
    await alice.page.keyboard.press('End');
    await alice.page.keyboard.type(' and Alice');
    await expect(row.locator('.ck-editor__editable')).toHaveText('Added by Bob and Alice');

    await bob.synchronized();
    await bob.save();
    await bob.edit(id);
    await expect.poll(() => bob.tagNames()).toEqual(['alpha', 'gamma']);
    await expect(bob.tagRows.nth(1).locator('.ck-editor__editable')).toHaveText('Added by Bob and Alice');
  });

  test('removes a row a peer removes', async ({ article, editor }) => {
    const id = await article({ tags: ['alpha', 'beta', 'gamma'] });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.removeTag(1);
    await expect.poll(() => alice.tagNames()).toEqual(['alpha', 'gamma']);

    await alice.synchronized();
    await alice.save();
    await alice.edit(id);
    await expect.poll(() => alice.tagNames()).toEqual(['alpha', 'gamma']);
  });

  test('reorders rows as a peer does, renumbers their positions and saves that order', async ({ article, editor }) => {
    const id = await article({ tags: ['alpha', 'beta', 'gamma'] });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    // Alice is typing in the first row: it stays where it is in her page, focused.
    const focused = alice.tagRows.nth(0).locator('input[name$="[name]"]');
    await focused.focus();

    await bob.moveTagUp(2);
    await expect.poll(() => bob.tagNames()).toEqual(['alpha', 'gamma', 'beta']);
    await expect.poll(() => alice.tagNames()).toEqual(['alpha', 'gamma', 'beta']);
    await expect(focused).toBeFocused();
    // The hidden positions follow the order shown, so that a save from either page keeps it.
    await expect.poll(() => alice.tagPositions()).toEqual(['1', '2', '3']);

    await alice.synchronized();
    await alice.save();
    await alice.edit(id);
    await expect.poll(() => alice.tagNames()).toEqual(['alpha', 'gamma', 'beta']);
  });

  test('saves the order from the page that reordered it', async ({ article, editor }) => {
    const id = await article({ tags: ['alpha', 'beta'] });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.moveTagUp(1);
    await expect.poll(() => alice.tagNames()).toEqual(['beta', 'alpha']);
    await bob.synchronized();
    await bob.save();

    await bob.edit(id);
    await expect.poll(() => bob.tagNames()).toEqual(['beta', 'alpha']);
  });
});
