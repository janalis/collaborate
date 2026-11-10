import { articleWithParts, expect, savedParts, test } from './lib/drupal.js';

// Paragraphs, shared as a collection's items: each paragraph's subform follows the draft, a paragraph one editor
// adds is added to the other's page, of its type, and one removed is removed there.
test.describe("an article's paragraphs edited by two editors", () => {
  test("carries an edit in a paragraph's subform", async ({ editor }) => {
    const id = articleWithParts('Parts', [
      ['text', 'Opening'],
      ['quote', 'Said'],
    ]);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.parts).toHaveCount(2);
    await alice.part(1).fill('Said by Alice');
    await expect(bob.part(1)).toHaveValue('Said by Alice');
    await bob.save(id);
    await expect
      .poll(() => savedParts(id))
      .toEqual([
        ['text', 'Opening'],
        ['quote', 'Said by Alice'],
      ]);
  });

  test('adds a paragraph of its type to the other page, and saves it', async ({ editor }) => {
    const id = articleWithParts('More parts', [['text', 'Opening']]);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.page.locator('[name="field_parts_quote_add_more"]').click();
    await expect(alice.parts).toHaveCount(2);
    await expect(bob.parts).toHaveCount(2);
    await expect(bob.parts.nth(1).locator('[name$="[field_quote][0][value]"]')).toBeVisible();
    await alice.part(1).fill('Closing words');
    await expect(bob.part(1)).toHaveValue('Closing words');
    await bob.part(0).fill('Opening by Bob');
    await expect(alice.part(0)).toHaveValue('Opening by Bob');
    await alice.save(id);
    await expect
      .poll(() => savedParts(id))
      .toEqual([
        ['text', 'Opening by Bob'],
        ['quote', 'Closing words'],
      ]);
  });

  test('removes a paragraph on the other page, and saves without it', async ({ editor }) => {
    const id = articleWithParts('Fewer parts', [
      ['text', 'Opening'],
      ['quote', 'Said'],
    ]);
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    // Remove is among the paragraph's actions, behind its toggle.
    await bob.parts.nth(0).locator('.paragraphs-dropdown-toggle').click();
    await bob.parts.nth(0).locator('input[type="submit"][value="Remove"]').click();
    await expect(bob.parts).toHaveCount(1);
    await expect(alice.parts).toHaveCount(1);
    await expect(alice.part(0)).toHaveValue('Said');
    await alice.save(id);
    await expect.poll(() => savedParts(id)).toEqual([['quote', 'Said']]);
  });
});
