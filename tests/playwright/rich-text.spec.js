import { expect, expectState, test } from './lib/app.js';

// CKEditor fields: model operations merged across pages, local undo, formatting, and the Markdown source mode.
test.describe('a rich-text field', () => {
  test('merges what two editors type at once, formatting included', async ({ article, editor }) => {
    const id = await article({ content: '<p>Hello</p>' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.caretRich('start');
    await bob.caretRich('end');
    await Promise.all([
      alice.page.keyboard.type('Alice: ', { delay: 30 }),
      bob.page.keyboard.type(' world', { delay: 30 }),
    ]);
    await expect(alice.richText).toHaveText('Alice: Hello world');
    await expect(bob.richText).toHaveText('Alice: Hello world');

    // Bold typed on one page is bold on the other.
    await bob.toolbar('Bold').click();
    await bob.page.keyboard.type('loud');
    await expect(alice.richText.locator('strong')).toHaveText('loud');
  });

  test('undoes only the own edits, on every page', async ({ article, editor }) => {
    const id = await article({ content: '<p>Hello</p>' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.typeRich(' from Alice');
    await expect(bob.richText).toHaveText('Hello from Alice');
    await bob.typeRich('Bob says ', 'content', 'start');
    await expect(alice.richText).toHaveText('Bob says Hello from Alice');

    // Alice's undo takes back her own typing, not Bob's.
    await alice.toolbar('Undo').click();
    await expect(alice.richText).toHaveText('Bob says Hello');
    await expect(bob.richText).toHaveText('Bob says Hello');
    await alice.toolbar('Redo').click();
    await expect(bob.richText).toHaveText('Bob says Hello from Alice');
  });
});

test.describe('a Markdown field', () => {
  test('shares its source mode, and carries source typing to the rendered editors', async ({ article, editor }) => {
    const id = await article({ notes: 'Plain _note_' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    const aliceSource = alice.control('notes');
    const bobSource = bob.control('notes');
    await expect(alice.rich('notes').locator('i')).toHaveText('note');

    await bob.page.getByText('Markdown source', { exact: true }).click();
    await expect(bobSource).toBeVisible();
    await expect(bobSource).toHaveValue('Plain _note_');
    // The peer's page switches too: the bundle checks its radio and the host's handler shows the source.
    await expect(
      alice.page.locator(`[name="${await aliceSource.getAttribute('id')}_view"][value="markdown"]`)
    ).toBeChecked();
    await expect(aliceSource).toBeVisible();
    // The mode is shared, but it is no unsaved change.
    await expectState(alice, 'saved');

    await bobSource.click();
    await bobSource.press('End');
    await bobSource.pressSequentially(' and **bold**');
    await expect(aliceSource).toHaveValue('Plain _note_ and **bold**');

    // Back to rendered editing, on both pages: the source typed is the model.
    await alice.page.getByText('Rendered', { exact: true }).click();
    await expect(bobSource).toBeHidden();
    await expect(bob.rich('notes').locator('strong')).toHaveText('bold');
    await expect(alice.rich('notes').locator('strong')).toHaveText('bold');
    await expectState(alice, 'draft');
  });

  test('keeps source typed mid-syntax as typed while a peer edits', async ({ article, editor }) => {
    const id = await article({ notes: 'First' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await bob.page.getByText('Markdown source', { exact: true }).click();
    const bobSource = bob.control('notes');
    await expect(alice.control('notes')).toBeVisible();

    await bobSource.click();
    await bobSource.press('End');
    await bobSource.pressSequentially(' **bo');
    await alice.control('notes').click();
    await alice.control('notes').evaluate((field) => field.setSelectionRange(0, 0));
    await alice.control('notes').pressSequentially('Intro ');

    // Bob's unfinished `**bo` is not rewritten to what the editor would serialize (`\*\*bo`).
    await expect(bobSource).toHaveValue(/^Intro First \*\*bo$/);
    await expect(bobSource).toBeFocused();
    await bobSource.pressSequentially('ld**');
    await expect(alice.control('notes')).toHaveValue('Intro First **bold**');
  });
});
