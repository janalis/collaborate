import { expect, expectState, test } from '../../playwright/lib/app.js';

// The edit page of the plain application (Symfony's own form theme, no EasyAdmin) shared by two editors.

test('plain', async ({ article, editor }) => {
  const id = await article({
    title: 'Spring catalogue',
    summary: 'Twelve new titles for the spring season, from first novels to a long-awaited sequel.',
    content: '<p>Our spring catalogue brings <strong>twelve new titles</strong>, three of them debuts.</p>',
  });
  const alice = await editor('alice');
  const bob = await editor('bob');
  await alice.edit(id);
  await bob.edit(id);
  await expect(alice.presence.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();

  // Bob's pointer rests on the summary while he types in the title.
  const box = await bob.control('summary').boundingBox();
  await bob.page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.3);
  await bob.page.mouse.move(box.x + box.width * 0.6, box.y + box.height * 0.5, { steps: 5 });
  await expect(alice.layer.locator('.collaborate-cursor').first()).toBeVisible();
  const title = bob.control('title');
  await title.focus();
  await title.evaluate((input) => input.setSelectionRange(input.value.length, input.value.length));
  await title.pressSequentially(' 2027');
  await expect(alice.control('title')).toHaveValue('Spring catalogue 2027');

  await expectState(alice, 'draft');
  await expect(alice.form.locator('[data-peer-focus-label="Bob"]')).toHaveCount(1);
  await expect(alice.layer.locator('.collaborate-cursor').first()).toBeVisible();
  await expect(alice.page).toHaveScreenshot('plain.png');
});
