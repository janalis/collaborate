import { expect, test } from './lib/app.js';

// Two editors, alice and bob, on the edit page of the same article.
test.describe('an edit page shared by two editors', () => {
  test('shows who else is on the page, and drops them when they leave', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    const presence = alice.page.locator('[data-collaborate-presence]');
    await expect(presence).toBeVisible();
    await expect(presence.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();
    await expect(
      bob.page.locator('[data-collaborate-presence] .collaborate-presence__user[title="Alice"]')
    ).toBeVisible();

    await bob.page.close();
    await expect(presence.locator('.collaborate-presence__user[title="Bob"]')).toHaveCount(0);
  });

  test("draws a peer's pointer and caret", async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.page.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();

    const title = bob.field('Title');
    const box = await title.boundingBox();
    await bob.page.mouse.move(box.x + 10, box.y + 5);
    await bob.page.mouse.move(box.x + 30, box.y + 10, { steps: 5 });
    await expect(alice.page.locator('[data-collaborate-layer] .collaborate-cursor').first()).toBeVisible();

    await title.click();
    await title.press('End');
    // The field's group carries the mark: the control itself, or its EasyAdmin `.form-group`.
    const marked = alice.form.locator('[data-peer-focus-label="Bob"]');
    await expect(marked).toHaveCount(1);
    expect(
      await marked.evaluate(
        (mark) => mark.matches('[name$="[title]"]') || mark.querySelector('[name$="[title]"]') !== null
      )
    ).toBe(true);
    await expect(alice.page.locator('[data-collaborate-layer] .collaborate-caret').first()).toBeAttached();
  });

  test('carries text edits both ways', async ({ article, editor }) => {
    const id = await article({ summary: 'Start' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.field('Summary').click();
    await bob.field('Summary').press('End');
    await bob.field('Summary').pressSequentially(' from Bob');
    await expect(alice.field('Summary')).toHaveValue('Start from Bob');

    await alice.field('Title').fill('Renamed by Alice');
    await expect(bob.field('Title')).toHaveValue('Renamed by Alice');
    await expect(bob.form).toHaveAttribute('data-collaborate-state', 'draft');
  });

  test('carries rich-text edits both ways', async ({ article, editor }) => {
    const id = await article({ content: '<p>Hello</p>' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.richText.click();
    await bob.page.keyboard.press('ControlOrMeta+End');
    await bob.page.keyboard.type(' world');
    await expect(alice.richText).toHaveText('Hello world');

    await alice.richText.click();
    await alice.page.keyboard.press('ControlOrMeta+End');
    await alice.page.keyboard.press('Enter');
    await alice.page.keyboard.type('Second paragraph');
    await expect(bob.richText.locator('p')).toHaveText(['Hello world', 'Second paragraph']);
  });

  test('tells the other editor of a save, which keeps the shared values', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.field('Title').fill('Saved by Alice');
    await expect(bob.field('Title')).toHaveValue('Saved by Alice');
    await alice.synchronized();
    await alice.save();

    await expect(bob.bar).toHaveText('Shared draft saved by Alice.');
    await expect(bob.field('Title')).toHaveValue('Saved by Alice');
    await alice.edit(id);
    await expect(alice.field('Title')).toHaveValue('Saved by Alice');
    await expect(alice.bar).toHaveText('Shared draft saved.');
  });

  test('discards the shared draft for everyone after confirmation', async ({ article, editor }) => {
    const id = await article({ summary: 'Saved summary' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.field('Summary').fill('Unsaved summary');
    await expect(alice.field('Summary')).toHaveValue('Unsaved summary');
    await alice.synchronized();

    await alice.page.locator('[data-collaborate-discard]').click();
    const dialog = alice.page.locator('[data-collaborate-confirm]');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading')).toHaveText('Discard the shared draft?');
    // Shown once it focuses Cancel: Bootstrap ignores a click while the dialog still fades in.
    await expect(dialog.locator('[data-collaborate-confirm-cancel]')).toBeFocused();
    await dialog.locator('[data-collaborate-confirm-accept]').click();
    await expect(dialog).toBeHidden();

    // The discarding page reloads on the saved record; the other one keeps its local content and says who reset it.
    await expect(alice.page.locator('[data-collaborate-notice]')).toHaveText('You discarded the shared draft.');
    await expect(alice.field('Summary')).toHaveValue('Saved summary');
    await expect(bob.page.locator('[data-collaborate-bar]')).toContainText('Alice');
  });
});

test.describe('a new record', () => {
  test('is drafted together through its invitation link, and created once', async ({ editor, routes }) => {
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.open(routes.new);
    const invitation = await alice.form.evaluate((form) => JSON.parse(form.dataset.collaborate).invitation);
    expect(invitation).toContain('draft=');
    await expect(
      alice.page.locator('[data-collaborate-bar]').getByRole('button', { name: 'Copy invitation link' })
    ).toBeVisible();

    await bob.open(invitation);
    await alice.field('Title').fill('Written together');
    await expect(bob.field('Title')).toHaveValue('Written together');
    await bob.field('Summary').fill('Bob wrote this');
    await expect(alice.field('Summary')).toHaveValue('Bob wrote this');
    await alice.synchronized();

    await alice.save();

    await expect(bob.bar).toHaveText(
      'Alice created this record. Local changes are retained; copy unsent content before opening the saved record.'
    );
    await bob.page.locator('[data-collaborate-open-record]').click();
    await bob.page.waitForURL((url) => /\/\d+\/edit$/.test(url.pathname));
    await bob.joined();
    await expect(bob.field('Title')).toHaveValue('Written together');
    await expect(bob.field('Summary')).toHaveValue('Bob wrote this');
  });
});
