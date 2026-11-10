import { article, expect, savedNotes, test } from './lib/drupal.js';

// A multi-value field's items, shared as a collection: Drupal's rows keep their keys through its rebuilds
// ("Add another item", Remove), a peer's new item takes an empty row or one Drupal adds, and the save stores the
// items the draft holds.
test.describe("an article's notes edited by two editors", () => {
  test('carries an edit of a note, and a note typed in the empty row', async ({ editor }) => {
    const id = article('Notes', '<p>Hello world</p>', ['First note', 'Second note']);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    // The two notes and Drupal's empty row.
    await expect(alice.notes).toHaveCount(3);
    await alice.note(1).click();
    await alice.page.keyboard.press('End');
    await alice.page.keyboard.type(' by Alice');
    await expect(bob.note(1)).toHaveValue('Second note by Alice');
    await alice.note(2).fill('Third note');
    // Bob's empty row took Alice's note.
    await expect(bob.note(2)).toHaveValue('Third note');
    await bob.save(id);
    await expect.poll(() => savedNotes(id)).toEqual(['First note', 'Second note by Alice', 'Third note']);
  });

  test('carries the notes "Add another item" adds, through a row Drupal adds to the other page', async ({ editor }) => {
    const id = article('More notes', '<p>Hello world</p>', ['First note']);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.note(1).fill('Second note');
    await expect(bob.note(1)).toHaveValue('Second note');
    // Bob has no empty row left: his page asks Drupal for one.
    await expect(bob.notes).toHaveCount(2);
    await alice.page.locator('[name="field_notes_add_more"]').click();
    await expect(alice.notes).toHaveCount(3);
    await alice.note(2).fill('Third note');
    await expect(bob.notes).toHaveCount(3);
    await expect(bob.note(2)).toHaveValue('Third note');
    // Alice's first notes kept their keys through her page's rebuild: Bob's edit reaches them.
    await bob.note(0).fill('First note by Bob');
    await expect(alice.note(0)).toHaveValue('First note by Bob');
    await alice.save(id);
    await expect.poll(() => savedNotes(id)).toEqual(['First note by Bob', 'Second note', 'Third note']);
  });

  test('removes a note on the other page, and saves without it', async ({ editor }) => {
    const id = article('Fewer notes', '<p>Hello world</p>', ['First note', 'Second note', 'Third note']);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.notes.nth(0).locator('input[type="submit"][value="Remove"]').click();
    await expect(alice.notes).toHaveCount(3);
    await expect(alice.note(0)).toHaveValue('Second note');
    await expect(bob.notes).toHaveCount(3);
    await expect(bob.note(0)).toHaveValue('Second note');
    await bob.note(1).fill('Third note by Bob');
    await expect(alice.note(1)).toHaveValue('Third note by Bob');
    await bob.save(id);
    await expect.poll(() => savedNotes(id)).toEqual(['Second note', 'Third note by Bob']);
  });

  test('moves a note reordered on the other page, and saves the new order', async ({ editor }) => {
    const id = article('Ordered notes', '<p>Hello world</p>', ['First note', 'Second note']);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    // Drupal's drag handle, moved with its keyboard: the second note above the first.
    await alice.notes.nth(1).locator('.tabledrag-handle').focus();
    await alice.page.keyboard.press('ArrowUp');
    await expect(alice.note(0)).toHaveValue('Second note');
    await expect(bob.note(0)).toHaveValue('Second note');
    await expect(bob.note(1)).toHaveValue('First note');
    await bob.save(id);
    await expect.poll(() => savedNotes(id)).toEqual(['Second note', 'First note']);
  });

  test('reopens the saved notes under the keys the save recorded', async ({ editor }) => {
    const id = article('Reopened notes', '<p>Hello world</p>', ['First note']);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.note(1).fill('Second note');
    await expect(bob.note(1)).toHaveValue('Second note');
    await bob.save(id);
    await expect(alice.bar).toContainText('All changes saved by bob.');
    // A page opened now shows the two notes once, and Drupal's empty row.
    const carol = await editor('carol');
    await carol.edit(id);
    await expect(carol.notes).toHaveCount(3);
    await expect(carol.note(1)).toHaveValue('Second note');
    await carol.note(1).fill('Second note by Carol');
    await expect(alice.note(1)).toHaveValue('Second note by Carol');
    // Alice's page holds the same two notes, not a copy of them: she typed in her only empty row.
    await expect(alice.notes).toHaveCount(2);
  });
});
