import { expect, expectState, test } from './lib/app.js';

// The plain application in its `e2e_limits` environment: updates of 4 KiB at most, journals of 64 KiB, 30
// field states, and a draft compacted on the next Join once its journal passes 1 KiB and everything is saved.
const words = (count, seed) => Array.from({ length: count }, (_, index) => `${seed}${index.toString(36)}`).join(' ');

test.describe('the limits of a shared draft', () => {
  test('refuse an update too large, and the draft shows full until discarded', async ({ article, editor }) => {
    const id = await article({ summary: 'Short' });
    const alice = await editor('alice');
    await alice.edit(id);

    await alice.control('summary').fill('x'.repeat(5000));
    await expectState(alice, 'full', {
      variant: 'danger',
      icon: 'fa-database',
      text: 'The shared draft limit was reached. Copy unsent content before discarding this draft.',
    });
    // Synchronization and Save pause; the content stays on the page.
    await expect(alice.control('summary')).toHaveValue('x'.repeat(5000));
    const url = alice.page.url();
    await alice.saveButton.click();
    await expectState(alice, 'full');
    expect(alice.page.url()).toBe(url);

    await expect(alice.discardButton).toBeEnabled();
    await alice.discardButton.click();
    await alice.decide(true);
    await expect(alice.notice).toHaveText('You discarded the shared draft.');
    await expect(alice.control('summary')).toHaveValue('Short');
  });

  test('stop a journal grown past its size', async ({ article, editor }) => {
    test.setTimeout(120_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    // Each rewrite of the summary journals some 3 KiB: past 64 KiB, the draft is full.
    for (let round = 0; round < 40; round++) {
      const appended = alice.page.waitForResponse((response) => response.url().endsWith('/collaborate/append'));
      await alice.control('summary').fill(words(350, `r${round}w`));
      const status = (await appended).status();
      if (status === 413) break;
      await alice.synchronized();
    }
    await expectState(alice, 'full');
    await expect(alice.control('summary')).toHaveValue(/^r\d+w0 /);
    // The other page is refused once its edit would not fit any more.
    await bob.control('summary').fill(words(350, 'late'));
    await expectState(bob, 'full');
  });

  test('refuse new collection entries past the field states it may hold', async ({ article, editor }) => {
    test.setTimeout(120_000);
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);

    for (let added = 0; added < 15; added++) {
      const row = await alice.addTag();
      await row.locator('input[name$="[name]"]').fill(`tag ${added}`);
      await expect(alice.form).toHaveAttribute('data-collaborate-state', /^(draft|full)$/);
      if ((await alice.form.getAttribute('data-collaborate-state')) === 'full') break;
      await alice.synchronized();
    }
    await expectState(alice, 'full', { icon: 'fa-database' });
  });

  test('compact a saved draft on the next Join, reloading the pages that showed it', async ({ article, editor }) => {
    test.setTimeout(90_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('summary').fill(words(300, 'c'));
    await expect(bob.control('summary')).toHaveValue(words(300, 'c'));
    await alice.synchronized();
    await bob.exposeSession();
    const generation = await bob.page.evaluate(() => window.collaborateSession().generation);

    // Alice's Save reloads her page, whose Join finds the journal past 1 KiB with every field saved: the
    // journal empties and the generation moves on. Bob's page has heard of the save first, so it shows
    // exactly the saved draft, and reloads once it hears of the new generation (its next replay, within 30 s).
    const join = await alice.hold('**/admin/collaborate/join');
    const rejoined = bob.page.waitForRequest((request) => request.url().endsWith('/collaborate/join'), {
      timeout: 60_000,
    });
    await alice.saveButton.click();
    await join.held;
    await expect(bob.bar).toHaveText('All changes saved by Alice.');
    await join.release();
    await alice.joined();
    await rejoined;
    await bob.joined();
    await bob.exposeSession();
    expect(await bob.page.evaluate(() => window.collaborateSession().generation)).not.toBe(generation);
    await expect(bob.control('summary')).toHaveValue(words(300, 'c'));
    await bob.control('title').fill('After compaction');
    await expect(alice.control('title')).toHaveValue('After compaction');
  });

  test('reload a page that sent every edit when its draft is compacted before it hears of the save', async ({
    article,
    editor,
  }) => {
    test.setTimeout(90_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('summary').fill(words(300, 'r'));
    await expect(bob.control('summary')).toHaveValue(words(300, 'r'));
    await alice.synchronized();
    await bob.exposeSession();
    const generation = await bob.page.evaluate(() => window.collaborateSession().generation);

    // Bob's page reads nothing until Alice's reloaded page has compacted the draft: it never hears of the save,
    // and by itself does not look saved. Its first replay finds the generation replaced, and it reloads.
    const replays = await bob.hold('**/admin/collaborate/replay');
    const rejoined = bob.page.waitForRequest((request) => request.url().endsWith('/collaborate/join'), {
      timeout: 60_000,
    });
    await alice.saveButton.click();
    await alice.joined();
    await replays.held;
    await expect(bob.bar).not.toHaveText('All changes saved by Alice.');
    await replays.release();
    await rejoined;
    await bob.joined();
    await bob.exposeSession();
    expect(await bob.page.evaluate(() => window.collaborateSession().generation)).not.toBe(generation);
    await expect(bob.control('summary')).toHaveValue(words(300, 'r'));
    await bob.control('title').fill('After compaction');
    await expect(alice.control('title')).toHaveValue('After compaction');
  });

  test('keep the unsent edits of a page when its draft is compacted', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.control('summary').fill(words(300, 'k'));
    await expect(bob.control('summary')).toHaveValue(words(300, 'k'));
    await alice.synchronized();
    // Bob's edit cannot reach the journal: it stays unsent on his page.
    await bob.page.route('**/admin/collaborate/append', (route) => route.fulfill({ status: 500, body: '' }));
    await bob.control('title').fill('Unsent by Bob');
    await expectState(bob, 'offline');

    await alice.saveAndStay();

    await expectState(bob, 'discarded', {
      text: 'The shared draft was reset. Your local content is retained; copy unsaved changes before reloading.',
    });
    await expect(bob.control('title')).toHaveValue('Unsent by Bob');
  });
});
