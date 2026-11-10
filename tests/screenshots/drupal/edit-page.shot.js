import { article, articleWithParts, drush, expect, test } from '../../playwright/lib/drupal.js';

// The Drupal module on Claro, as the Drupal guide and the drupal.org project page show it: an article edited by
// Alice and Bob, its rich text, its paragraphs, the bell, and the review of a save made elsewhere.

const BODY =
  '<p>Our spring collection arrives in stores on March 21, with three new colorways and a recycled-cotton line.</p>' +
  '<p>Pre-orders open a week before. Launch party at 6 pm.</p>';

/** Alice's id; what she was told before is forgotten, so her bell counts only what a scene tells her. */
function freshAlice() {
  const id = drush(`echo user_load_by_name('alice')->id();`);
  drush(`\\Drupal::database()->delete('collaborate_notification')->condition('recipient_id', ${id})->execute();`);
  return id;
}

/** Alice and Bob on the edit page of an article, each seeing the other. */
async function together(editor, id) {
  freshAlice();
  const alice = await editor('alice');
  const bob = await editor('bob');
  await alice.edit(id);
  await bob.edit(id);
  await expect(alice.page.locator('[data-collaborate-presence] [data-user-id]')).toHaveAttribute('title', 'bob');
  return { alice, bob };
}

/** The part of the page around `locators` (with a margin), for a screenshot's `clip`. */
async function around(page, locators, margin = 24) {
  const boxes = await Promise.all(locators.map((locator) => locator.boundingBox()));
  const viewport = page.viewportSize();
  const x = Math.max(0, Math.min(...boxes.map((box) => box.x)) - margin);
  const y = Math.max(0, Math.min(...boxes.map((box) => box.y)) - margin);
  const right = Math.min(viewport.width, Math.max(...boxes.map((box) => box.x + box.width)) + margin);
  const bottom = Math.min(viewport.height, Math.max(...boxes.map((box) => box.y + box.height)) + margin);
  return { x, y, width: right - x, height: bottom - y };
}

/** Alice's page with what tells the time fixed: the meta box's last save. */
async function timeless(page) {
  await page.addStyleTag({ content: '.entity-meta__last-saved { visibility: hidden !important; }' });
  await page.mouse.move(0, 0);
}

/** Works through the queue the module sends notifications to, as cron does. */
function deliver() {
  drush(
    `$queue = \\Drupal::queue('collaborate'); $worker = \\Drupal::service('plugin.manager.queue_worker')->createInstance('collaborate'); while ($item = $queue->claimItem()) { $worker->processItem($item->data); $queue->deleteItem($item); }`
  );
}

test('drupal', async ({ editor }) => {
  const { alice, bob } = await together(editor, article('Spring launch', BODY));

  // Bob adds to the title, his pointer resting on the revision log: Alice sees the draft change, his caret and his
  // pointer.
  await bob.title.click();
  await bob.page.keyboard.press('End');
  await bob.page.keyboard.type(' 2027');
  await expect(alice.title).toHaveValue('Spring launch 2027');
  // Bob's page, back at its top as Alice's is, where his pointer goes.
  await bob.page.evaluate(() => window.scrollTo(0, 0));
  const box = await bob.page.locator('#edit-revision-log-0-value').boundingBox();
  await bob.page.mouse.move(box.x + box.width * 0.6, box.y + box.height * 0.5 + 40);
  await bob.page.mouse.move(box.x + box.width * 0.6, box.y + box.height * 0.5, { steps: 5 });
  await expect(alice.bar).toContainText('Unsaved changes');
  await expect(alice.form.locator('[data-peer-focus-label="bob"]')).toHaveCount(1);
  await expect(alice.page.locator('.collaborate-caret:not([hidden])')).toHaveCount(1);
  await expect(alice.page.locator('[data-collaborate-layer] .collaborate-cursor').first()).toBeVisible();
  await timeless(alice.page);
  await expect(alice.page).toHaveScreenshot('drupal.png');
});

test('drupal rich text', async ({ editor }) => {
  const { alice, bob } = await together(editor, article('Spring launch', BODY));

  // Alice has the body in view, where Bob's caret is drawn.
  await alice.body.scrollIntoViewIfNeeded();
  // Bob types in CKEditor 5 and selects what he typed: Alice sees his caret and selection.
  await bob.body.click();
  await bob.page.keyboard.press('ControlOrMeta+End');
  await bob.page.keyboard.type(' Tickets at the door.');
  await expect(alice.body).toContainText('Tickets at the door.');
  for (let i = 0; i < 'at the door.'.length; i++) await bob.page.keyboard.press('Shift+ArrowLeft');
  await bob.page.mouse.move(5, 400);
  const body = alice.form.locator('[data-peer-focus-label="bob"]');
  await expect(body).toHaveCount(1);
  await timeless(alice.page);
  await expect(alice.page.locator('.collaborate-caret__selection > div').first()).toBeVisible();
  await expect(alice.page).toHaveScreenshot('drupal-rich-text.png', { clip: await around(alice.page, [body]) });
});

test('drupal paragraphs', async ({ editor }) => {
  const id = articleWithParts('Spring launch', [
    ['text', 'Three new colorways, and a recycled-cotton line.'],
    ['quote', 'The softest cotton we have ever made.'],
  ]);
  const { alice, bob } = await together(editor, id);
  // Paragraphs' table outgrows Claro's form at 1280 pixels: the pages of this scene are wider.
  for (const each of [alice, bob]) await each.page.setViewportSize({ width: 1600, height: 900 });

  // Bob adds a quote paragraph and writes in it: it appears on Alice's page, with his mark.
  await bob.page.locator('[name="field_parts_quote_add_more"]').click();
  await expect(alice.parts).toHaveCount(3);
  await bob.part(2).fill('Worth the wait.');
  await expect(alice.part(2)).toHaveValue('Worth the wait.');
  const parts = alice.form.locator('[data-collaborate-collection="field_parts"]');
  await expect(alice.form.locator('[data-peer-focus-label="bob"]')).toHaveCount(1);
  await timeless(alice.page);
  await parts.scrollIntoViewIfNeeded();
  await expect(alice.page).toHaveScreenshot('drupal-paragraphs.png', { clip: await around(alice.page, [parts]) });
});

test('drupal notifications', async ({ editor }) => {
  const id = freshAlice();
  const alice = await editor('alice');
  const notify = (title) =>
    drush(
      `\\Drupal::service('Janalis\\Collaborate\\Notification\\Notifier')->notify(${id}, 'saved', ['%name%' => 'bob', '%title%' => ${JSON.stringify(title)}], '/admin/content');`
    );
  notify('Spring launch');
  notify('Summer sale');
  notify('Store opening hours');
  deliver();
  await alice.page.goto('/admin/content');
  const bell = alice.page.locator('.collaborate-notifications');
  await expect(bell.locator('.collaborate-notifications__count')).toHaveText('3');
  await alice.page.addStyleTag({
    content: '.toast, .collaborate-notifications__push-denied { display: none !important; }',
  });
  await bell.locator('.collaborate-notifications__toggle').click();
  const menu = bell.locator('.collaborate-notifications__menu');
  await expect(menu.locator('.collaborate-notification')).toHaveCount(3);
  await alice.page.mouse.move(0, 0);
  await menu.locator('.collaborate-notification__date').evaluateAll((dates) => {
    for (const date of dates) date.textContent = 'March 3, 2027 at 9:30 AM';
  });
  const box = await menu.boundingBox();
  await expect(alice.page).toHaveScreenshot('drupal-notifications.png', {
    clip: { x: 0, y: 0, width: alice.page.viewportSize().width, height: box.y + box.height + 24 },
  });
});

test('drupal conflict', async ({ browser, editor }) => {
  const id = article('Spring launch', BODY);
  freshAlice();
  const alice = await editor('alice');
  await alice.edit(id);
  await alice.title.fill('Spring launch 2027');
  await expect(alice.bar).toContainText('Unsaved changes');

  // Carol saves the article elsewhere, from a browser without JavaScript, outside the draft: Alice's draft is
  // reviewed against what was saved.
  const context = await browser.newContext({ javaScriptEnabled: false });
  try {
    const carol = await context.newPage();
    await carol.goto('/user/login');
    await carol.fill('#edit-name', 'carol');
    await carol.fill('#edit-pass', 'carol');
    await carol.click('#edit-submit');
    await carol.goto(`/node/${id}/edit`);
    await carol.fill('#edit-body-0-value', BODY.replace('6 pm', '7 pm'));
    await Promise.all([carol.waitForURL(`**/node/${id}`), carol.click('#edit-submit')]);
  } finally {
    await context.close();
  }

  const review = alice.page.locator('[data-collaborate-conflicts]');
  await expect(review).toBeVisible();
  await review.locator('details').evaluateAll((all) => {
    for (const each of all) each.open = true;
  });
  await timeless(alice.page);
  await expect(alice.page).toHaveScreenshot('drupal-conflict.png', {
    clip: await around(alice.page, [alice.page.locator('h1'), alice.page.locator('[data-collaborate-bar]'), review]),
  });
});
