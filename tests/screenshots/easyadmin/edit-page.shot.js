import { expect, expectState, test } from '../../playwright/lib/app.js';

// The edit page of EasyAdmin shared by two editors, as the README and the guide show it.

const spring = {
  title: 'Spring catalogue',
  summary: 'Twelve new titles for the spring season, from first novels to a long-awaited sequel.',
  content: '<p>Our spring catalogue brings <strong>twelve new titles</strong>, three of them debuts.</p>',
};

/** Alice and Bob on the edit page of a new article, each seeing the other. */
async function together(article, editor, data = spring) {
  const id = await article(data);
  const alice = await editor('alice');
  const bob = await editor('bob');
  await alice.edit(id);
  await bob.edit(id);
  await expect(alice.presence.locator('.collaborate-presence__user[title="Bob"]')).toBeVisible();
  return { id, alice, bob };
}

/** The part of the page around `locators` (with a margin), for a screenshot's `clip`. */
async function around(page, locators, margin = 16) {
  const boxes = await Promise.all(locators.map((locator) => locator.boundingBox()));
  const viewport = page.viewportSize();
  const x = Math.max(0, Math.min(...boxes.map((box) => box.x)) - margin);
  const y = Math.max(0, Math.min(...boxes.map((box) => box.y)) - margin);
  const right = Math.min(viewport.width, Math.max(...boxes.map((box) => box.x + box.width)) + margin);
  const bottom = Math.min(viewport.height, Math.max(...boxes.map((box) => box.y + box.height)) + margin);
  return { x, y, width: right - x, height: bottom - y };
}

/** Moves Bob's pointer to a point of `target` (fractions of its box), in steps, so Alice's page draws it. */
async function point(bob, target, x = 0.5, y = 0.5) {
  const box = await target.boundingBox();
  await bob.page.mouse.move(box.x + box.width * x - 40, box.y + box.height * y - 10);
  await bob.page.mouse.move(box.x + box.width * x, box.y + box.height * y, { steps: 5 });
}

test('hero', async ({ article, editor }) => {
  const { alice, bob } = await together(article, editor);

  // Bob adds to the summary and selects its last word; his pointer rests on the title.
  const summary = bob.control('summary');
  await summary.click();
  await summary.press('ControlOrMeta+End');
  await summary.pressSequentially(' Out in March.');
  await expect(alice.control('summary')).toHaveValue(/Out in March\.$/);
  for (let i = 0; i < 'March.'.length; i++) await summary.press('Shift+ArrowLeft');
  await point(bob, bob.control('title'), 0.7);

  await expectState(alice, 'draft');
  await expect(alice.form.locator('[data-peer-focus-label="Bob"]')).toHaveCount(1);
  await expect(alice.layer.locator('.collaborate-cursor').first()).toBeVisible();
  await expect(alice.page).toHaveScreenshot('hero.png');
});

test('presence and cursors', async ({ article, editor }) => {
  const { alice, bob } = await together(article, editor);

  await bob.caretRich('end');
  for (let i = 0; i < 'debuts.'.length; i++) await bob.page.keyboard.press('Shift+ArrowLeft');
  await point(bob, bob.control('summary'), 0.6, 0.4);

  await expect(alice.form.locator('[data-peer-focus-label="Bob"]')).toHaveCount(1);
  await expect(alice.layer.locator('.collaborate-cursor').first()).toBeVisible();
  await expect(alice.page).toHaveScreenshot('presence-cursors.png');
});

test('rich text', async ({ article, editor }) => {
  const { alice, bob } = await together(article, editor);

  await bob.typeRich(' The first ships on 3 March.');
  await expect(alice.richText).toContainText('ships on 3 March.');
  for (let i = 0; i < '3 March.'.length; i++) await bob.page.keyboard.press('Shift+ArrowLeft');
  // Bob's pointer rests outside the picture.
  await bob.page.mouse.move(5, 400);

  await expect(alice.layer.locator('.collaborate-caret').first()).toBeAttached();
  const content = alice.form.locator('[data-peer-focus-label="Bob"]');
  await expect(content).toHaveCount(1);
  await expect(alice.page).toHaveScreenshot('rich-text.png', { clip: await around(alice.page, [content], 24) });
});

test('shared draft', async ({ article, editor }) => {
  const { alice, bob } = await together(article, editor);

  await bob.control('title').fill('Spring catalogue 2027');
  await expect(alice.control('title')).toHaveValue('Spring catalogue 2027');
  await expectState(alice, 'draft');
  await bob.control('title').blur();
  await expect(alice.form.locator('[data-peer-focus-label="Bob"]')).toHaveCount(0);

  await expect(alice.page).toHaveScreenshot('shared-draft.png', {
    clip: await around(alice.page, [alice.presence, alice.barElement, alice.control('title')], 24),
  });
});

test('conflict review', async ({ article, editor, outsideSave }) => {
  const id = await article({ ...spring, status: 'draft' });
  const alice = await editor('alice');
  await alice.edit(id);
  await alice.control('title').fill('Spring catalogue 2027');
  await alice.synchronized();

  await outsideSave(id, async (carol) => {
    await carol.control('summary').fill(spring.summary.replace('Twelve', 'Ten'));
    await carol.control('status').selectOption('review');
  });

  await expectState(alice, 'conflict');
  await expect(alice.conflicts).toBeVisible();
  // The summary shows the words that changed; the other differences stay folded.
  const details = alice.conflicts.locator('details');
  await expect(details).toHaveCount(2);
  await details.evaluateAll((all) => {
    for (const each of all) each.open = each.querySelector('summary').textContent.includes('Summary');
  });
  await alice.page.mouse.move(0, 0);
  await expect(alice.page).toHaveScreenshot('conflict-review.png', {
    clip: await around(alice.page, [alice.page.locator('h1'), alice.barElement, alice.conflicts], 24),
  });
});
