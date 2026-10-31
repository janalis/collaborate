import { expect, test } from './lib/app.js';

// Every kind of control a shared draft carries, from one editor to another, and what it never carries.
test.describe('the fields of a shared form', () => {
  test('carry text, a multi-line textarea, a number, a date, a select, a checkbox, radios and an association', async ({
    article,
    editor,
  }) => {
    const id = await article({ summary: '' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    // Code deriving one field from others hears of a peer's choice through `collaborate:applied`.
    await alice.form.evaluate((form) => {
      window.applied = [];
      form.addEventListener('collaborate:applied', (event) => window.applied.push(event.target.name));
    });

    await bob.control('title').fill('Every field');
    await bob.control('summary').click();
    await bob.page.keyboard.type('Line one');
    await bob.page.keyboard.press('Enter');
    await bob.page.keyboard.type('Line two');
    await bob.control('rating').fill('4');
    await bob.control('publishedOn').fill('2026-05-17');
    await bob.control('status').selectOption('review');
    await bob.control('featured').check();
    await bob.page.locator(`[name="${bob.name('audience')}"][value="members"]`).check();
    await bob.pickCategory('History');

    await expect(alice.control('title')).toHaveValue('Every field');
    // Text controls share the line breaks they show.
    await expect(alice.control('summary')).toHaveValue('Line one\nLine two');
    await expect(alice.control('rating')).toHaveValue('4');
    await expect(alice.control('publishedOn')).toHaveValue('2026-05-17');
    await expect(alice.control('status')).toHaveValue('review');
    await expect(alice.control('featured')).toBeChecked();
    await expect(alice.page.locator(`[name="${alice.name('audience')}"][value="members"]`)).toBeChecked();
    await expect.poll(() => alice.categoryLabel()).toBe('History');
    if (alice.easyAdmin) {
      // The autocomplete shows the peer's pick under the label its event carried.
      await expect(
        alice.form.locator(`[name="${alice.name('category', 'autocomplete')}"] ~ .ts-wrapper .item`)
      ).toHaveText('History');
    }
    expect(await alice.page.evaluate(() => window.applied)).toEqual(
      expect.arrayContaining([alice.name('status'), alice.name('featured'), alice.name('audience')])
    );

    // Alice saves what Bob typed: every value reaches the record.
    await alice.synchronized();
    await alice.save();
    await alice.edit(id);
    await expect(alice.control('title')).toHaveValue('Every field');
    await expect(alice.control('summary')).toHaveValue('Line one\nLine two');
    await expect(alice.control('rating')).toHaveValue('4');
    await expect(alice.control('publishedOn')).toHaveValue('2026-05-17');
    await expect(alice.control('status')).toHaveValue('review');
    await expect(alice.control('featured')).toBeChecked();
    await expect(alice.page.locator(`[name="${alice.name('audience')}"][value="members"]`)).toBeChecked();
    await expect.poll(() => alice.categoryLabel()).toBe('History');
  });

  test('take the last value written to a choice, everywhere', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.control('status').selectOption('review');
    await expect(bob.control('status')).toHaveValue('review');
    await bob.control('status').selectOption('published');
    await expect(alice.control('status')).toHaveValue('published');
    await bob.control('featured').check();
    await expect(alice.control('featured')).toBeChecked();
    await alice.control('featured').uncheck();
    await expect(bob.control('featured')).not.toBeChecked();
  });

  test('merge text two editors type at once', async ({ article, editor }) => {
    const id = await article({ summary: 'middle' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.control('summary').focus();
    await alice.control('summary').evaluate((field) => field.setSelectionRange(0, 0));
    await bob.control('summary').focus();
    await bob.control('summary').evaluate((field) => field.setSelectionRange(field.value.length, field.value.length));
    await Promise.all([
      alice.control('summary').pressSequentially('start ', { delay: 40 }),
      bob.control('summary').pressSequentially(' end', { delay: 40 }),
    ]);

    await expect(alice.control('summary')).toHaveValue('start middle end');
    await expect(bob.control('summary')).toHaveValue('start middle end');
  });

  test('never carry a password, which stays on the page that typed it', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await bob.control('previewPassword').fill('hunter2');
    await bob.control('title').fill('Sent after the password');
    await expect(alice.control('title')).toHaveValue('Sent after the password');
    await expect(alice.control('previewPassword')).toHaveValue('');
    await bob.synchronized();
    // Nothing of it reached the draft: a reload shows the shared title, and no password.
    await bob.edit(id);
    await expect(bob.control('title')).toHaveValue('Sent after the password');
    await expect(bob.control('previewPassword')).toHaveValue('');
  });
});
