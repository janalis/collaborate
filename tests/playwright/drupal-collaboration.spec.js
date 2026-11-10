import { article, drush, expect, test } from './lib/drupal.js';

// The Drupal module on Claro: entity forms shared as drafts, the others in Navigation's top bar, listings
// refreshed by a save elsewhere, and the bell.
test.describe('an article edited by two editors', () => {
  test('shows who else is on the page, and the field they are in', async ({ editor }) => {
    const id = article('Presence');
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.page.locator('[data-collaborate-presence] [data-user-id]')).toHaveAttribute('title', 'bob');
    await bob.title.click();
    // The item the field is in carries the mark, named after its peer.
    await expect(alice.form.locator('[data-peer-focus-label="bob"]')).toHaveAttribute('data-peer-focus', /.+/);
  });

  test('carries title and rich-text edits both ways', async ({ editor }) => {
    const id = article('Shared');
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.title.click();
    await alice.page.keyboard.press('End');
    await alice.page.keyboard.type(' by Alice');
    await expect(bob.title).toHaveValue('Shared by Alice');
    await bob.body.click();
    await bob.page.keyboard.press('End');
    await bob.page.keyboard.type(' and Bob');
    await expect(alice.body).toContainText('Hello world and Bob');
    await expect(alice.bar).toContainText('Unsaved changes');
  });

  test('tells the other editor of a save, and refreshes the listing a third one has open', async ({ editor }) => {
    const after = `After the save ${Date.now()}`;
    const id = article('Before the save');
    const alice = await editor('alice');
    const bob = await editor('bob');
    const carol = await editor('carol');
    await alice.edit(id);
    await bob.edit(id);
    await carol.page.goto('/admin/content');
    await expect(carol.page.locator('body')).toHaveAttribute('data-collaborate-page', 'node:index');
    await carol.page.evaluate(() => {
      window.beforeTheSave = true;
    });
    await bob.title.fill(after);
    await expect(bob.bar).toContainText('Unsaved changes');
    await Promise.all([
      bob.page.waitForURL(`**/node/${id}`),
      bob.page.locator('[data-collaborate-bar] [data-collaborate-save]').click(),
    ]);
    await expect(alice.bar).toContainText('All changes saved by bob.');
    await expect(alice.title).toHaveValue(after);
    // The listing reloaded: the page's own state is gone, the new title is listed.
    await expect
      .poll(() => carol.page.evaluate(() => window.beforeTheSave ?? null).catch(() => 'reloading'))
      .toBeNull();
    await expect(carol.page.locator('body')).toContainText(after);
  });
});

test.describe('a new article', () => {
  test('is drafted together through its invitation link, and created once', async ({ editor }) => {
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.page.goto('/node/add/article');
    await expect(alice.form).toHaveAttribute('data-collaborate-state', 'saved');
    const invitation = JSON.parse((await alice.form.getAttribute('data-collaborate')) ?? '{}').invitation;
    expect(invitation).toMatch(/draft=[a-f0-9]{32}/);
    await bob.page.goto(invitation);
    await expect(bob.form).toHaveAttribute('data-collaborate-state', 'saved');
    const title = `Drafted together ${Date.now()}`;
    await alice.title.fill(title);
    await expect(bob.title).toHaveValue(title);
    await Promise.all([
      alice.page.waitForURL(/\/node\/\d+$/),
      alice.page.locator('[data-collaborate-bar] [data-collaborate-save]').click(),
    ]);
    await expect(bob.page.locator('[data-collaborate-bar]')).toContainText('created');
    expect(
      Number(
        drush(
          `echo count(\\Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', ${JSON.stringify(title)})->execute());`
        )
      )
    ).toBe(1);
  });
});

test.describe('notifications', () => {
  test('ring the bell of their reader, and list on their page', async ({ editor }) => {
    const alice = await editor('alice');
    await alice.page.goto('/admin/content');
    const bell = alice.page.locator('.collaborate-notifications');
    await expect(bell).toBeVisible();
    const id = drush(`echo user_load_by_name('alice')->id();`);
    drush(
      `\\Drupal::service('Janalis\\Collaborate\\Notification\\Notifier')->notify(${id}, 'saved', ['%name%' => 'bob', '%title%' => 'The bell'], '/admin/content');`
    );
    execRun();
    await expect(bell).toContainText(/\d/);
    await alice.page.goto('/admin/notifications/all');
    await expect(alice.page.locator('.collaborate-notifications-page')).toContainText('bob saved The bell.');
  });
});

/** Works through the queue the module sends notifications to, as cron does. */
function execRun() {
  drush(
    `\\Drupal::service('plugin.manager.queue_worker')->createInstance('collaborate'); $queue = \\Drupal::queue('collaborate'); $worker = \\Drupal::service('plugin.manager.queue_worker')->createInstance('collaborate'); while ($item = $queue->claimItem()) { $worker->processItem($item->data); $queue->deleteItem($item); }`
  );
}
