import { expect, expectState, test } from './lib/app.js';

// A record saved outside the shared draft (here by a browser without JavaScript, or a script on an admin
// path): open edit pages compare their draft with it and review the differences.
test.describe('a record saved outside its shared draft', () => {
  const saved = {
    summary: 'The quick brown fox',
    status: 'draft',
    content: '<p>Old <strong>text</strong></p>',
  };

  /** Carol saves, without JavaScript: another summary, status and content, and the article featured. */
  const saveElsewhere = async (carol) => {
    await carol.control('summary').fill('The slow brown fox jumps');
    await carol.control('status').selectOption('review');
    await carol.control('featured').check();
    await carol.control('content').fill('<p>New text</p>');
  };

  test('lists each difference by its label, as read, with the words that changed', async ({
    article,
    editor,
    outsideSave,
  }) => {
    const id = await article(saved);
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Edited in the draft');
    await alice.synchronized();

    await outsideSave(id, saveElsewhere);

    await expectState(alice, 'conflict', {
      variant: 'warning',
      icon: 'fa-code-compare',
      text: 'The saved record differs from this shared draft. Review both versions before saving.',
    });
    const panel = alice.conflicts;
    await expect(panel.getByRole('heading', { level: 2 })).toHaveText('Differences with the saved record');
    const field = (label) =>
      panel.locator('details').filter({ has: alice.page.locator('summary', { hasText: label }) });
    for (const label of ['Summary', 'Content', 'Status', 'Featured']) await expect(field(label)).toHaveCount(1);
    // Each side says which version it is.
    await expect(field('Summary').locator('.collaborate-conflicts__side')).toHaveText(['Saved record', 'Shared draft']);
    // A choice by its option labels, a checkbox as Yes or No, rich text without markup.
    await expect(field('Status').locator('[data-saved-field]')).toHaveText('Review');
    await expect(field('Status').locator('[data-draft-field]')).toHaveText('Draft');
    await expect(field('Featured').locator('[data-saved-field]')).toHaveText('Yes');
    await expect(field('Featured').locator('[data-draft-field]')).toHaveText('No');
    await expect(field('Content').locator('[data-saved-field]')).toHaveText('New text');
    await expect(field('Content').locator('[data-draft-field]')).toHaveText('Old text');
    // The words the draft removed and added are marked.
    await expect(field('Summary').locator('[data-saved-field]')).toHaveText('The slow brown fox jumps');
    await expect(field('Summary').locator('[data-saved-field] del')).toHaveText(['slow', ' jumps']);
    await expect(field('Summary').locator('[data-draft-field] ins')).toHaveText(['quick']);
  });

  test('keeps the draft edits and takes the saved values for the other fields', async ({
    article,
    editor,
    outsideSave,
  }) => {
    const id = await article(saved);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('title').fill('Kept from the draft');
    await expect(bob.control('title')).toHaveValue('Kept from the draft');
    await alice.synchronized();
    await outsideSave(id, saveElsewhere);
    await expectState(alice, 'conflict');
    await expectState(bob, 'conflict');

    await alice.conflicts.getByRole('button', { name: 'Keep shared draft' }).click();

    await expect(alice.conflicts).toBeHidden();
    await expectState(alice, 'draft');
    await expect(alice.control('title')).toHaveValue('Kept from the draft');
    await expect(alice.control('summary')).toHaveValue('The slow brown fox jumps');
    await expect(alice.control('status')).toHaveValue('review');
    await expect(alice.control('featured')).toBeChecked();
    await expect(alice.richText).toHaveText('New text');
    // The other page converges on the same choice.
    await expect(bob.control('summary')).toHaveValue('The slow brown fox jumps');
    await expect(bob.conflicts).toBeHidden();

    // Save keeps the outside write: nothing it changed is reverted.
    await alice.synchronized();
    await alice.saveAndStay();
    await expect(alice.control('title')).toHaveValue('Kept from the draft');
    await expect(alice.control('summary')).toHaveValue('The slow brown fox jumps');
    await expect(alice.control('status')).toHaveValue('review');
    await expectState(alice, 'saved');
  });

  test('replaces the draft by the saved record for everyone once confirmed', async ({
    article,
    editor,
    outsideSave,
  }) => {
    const id = await article(saved);
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('summary').fill('Draft summary');
    await alice.synchronized();
    await outsideSave(id, saveElsewhere);
    await expectState(alice, 'conflict');

    await alice.conflicts.getByRole('button', { name: 'Use saved record' }).click();
    await expect(alice.dialog.getByRole('heading')).toHaveText('Use the saved record?');
    await expect(alice.dialog).toContainText(
      "The shared draft's conflicting changes are replaced by the saved values for everyone."
    );
    await alice.decide(false);
    await expectState(alice, 'conflict');

    await alice.conflicts.getByRole('button', { name: 'Use saved record' }).click();
    await alice.decide(true);
    await expect(alice.notice).toHaveText('The shared draft now uses the saved record.');
    await alice.joined();
    await expect(alice.control('summary')).toHaveValue('The slow brown fox jumps');
    await expect(alice.control('status')).toHaveValue('review');
    await expect(alice.conflicts).toBeHidden();
    await expectState(alice, 'saved');
  });

  test('brings the review into view and focus instead of saving', async ({ article, editor, outsideSave }) => {
    const id = await article(saved);
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Not saved yet');
    await alice.synchronized();
    await outsideSave(id, saveElsewhere);
    await expectState(alice, 'conflict');
    await alice.control('title').focus();

    const url = alice.page.url();
    await alice.saveButton.click();
    await expect(alice.conflicts.getByRole('heading', { level: 2 })).toBeFocused();
    await expect(alice.conflicts).toBeInViewport();
    expect(alice.page.url()).toBe(url);
    await expectState(alice, 'conflict');
  });

  test('names collection entries removed and reordered elsewhere', async ({ article, editor }) => {
    const id = await article({ tags: ['alpha', 'beta', 'gamma'] });
    const alice = await editor('alice');
    await alice.edit(id);
    // An editor's own change in the draft: one more tag.
    const row = await alice.addTag();
    await row.locator('input[name$="[name]"]').fill('delta');
    await alice.synchronized();

    // A script on an admin path removes beta and reorders the rest.
    const carol = await editor('carol');
    const response = await carol.page.request.post(`/admin/e2e/articles/${id}`, {
      data: { remove: ['beta'], order: ['gamma', 'alpha'] },
    });
    expect(response.ok()).toBe(true);

    await expectState(alice, 'conflict');
    const field = (label) =>
      alice.conflicts
        .locator('details')
        .filter({ has: alice.page.locator('summary', { hasText: new RegExp(`^${label}$`) }) });
    // Entries are named after their collection and the text of their row; the order after the collection.
    await expect(field('Tags › beta').locator('[data-saved-field]')).toHaveText('Entry removed');
    await expect(field('Tags › beta').locator('[data-draft-field]')).toHaveText('Entry present');
    await expect(field('Tags').locator('[data-saved-field]')).toContainText(['Order changed']);
  });
});
