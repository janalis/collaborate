import { expect, expectState, test } from './lib/app.js';

// The state bar above a shared form: each state's colour, icon and text, its announcement, and what the
// page does in it. States reached through a Save, a Discard, a conflict or a new record have their own specs.
const journal = (action) => `**/admin/collaborate/${action}`;

test.describe('the state bar', () => {
  test('locks the form while it connects, then shows the saved draft', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const join = await alice.hold(journal('join'));
    await alice.page.goto(alice.routes.edit(id));
    await join.held;

    await expectState(alice, 'connecting', { variant: 'secondary', text: 'Connecting to the shared draft…' });
    await expect(alice.barElement.locator('.collaborate-bar__icon')).toHaveClass(/spinner-border/);
    await expect(alice.discardButton).toBeDisabled();
    // Every control is inert until the draft and its editors are ready: typing reaches nothing.
    await expect(alice.control('title').locator('xpath=ancestor-or-self::*[@inert]')).not.toHaveCount(0);
    await alice.control('title').click({ force: true });
    await alice.page.keyboard.type('ignored');
    await expect(alice.control('title')).not.toHaveValue(/ignored/);

    await join.release();
    await alice.joined();
    await expectState(alice, 'saved', {
      variant: 'success',
      icon: 'fa-circle-check',
      text: 'All changes saved.',
    });
    // Nothing to save or discard: the saved bar offers neither.
    await expect(alice.barSaveButton).toBeHidden();
    await expect(alice.discardButton).toBeHidden();
  });

  test('shows unsaved changes, and synchronizes without announcing it', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    const append = await alice.hold(journal('append'));

    await alice.control('title').fill('Unsaved title');
    await append.held;
    await expectState(alice, 'pending', { variant: 'info', text: 'Synchronizing changes…', announced: false });
    await expect(alice.barElement.locator('.collaborate-bar__icon')).toHaveClass(/spinner-border/);
    // The live region still says the last announced state.
    await expect(alice.announcer).toHaveText('All changes saved.');

    await append.release();
    await expectState(alice, 'draft', {
      variant: 'info',
      icon: 'fa-pen',
      text: 'Unsaved changes, kept automatically. Click Save to record them.',
    });
  });

  test('keeps edits through a network outage and sends them once it is over', async ({ article, editor }) => {
    test.setTimeout(90_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);

    await alice.context.setOffline(true);
    await alice.control('title').fill('Written offline');
    await expectState(alice, 'offline', {
      variant: 'warning',
      icon: 'fa-plug',
      text: 'Connection interrupted. Keep this page open to synchronize your changes.',
    });
    await expect(alice.discardButton).toBeEnabled();
    await expect(bob.control('title')).not.toHaveValue('Written offline');

    await alice.context.setOffline(false);
    await expect(bob.control('title')).toHaveValue('Written offline', { timeout: 30_000 });
    await expectState(alice, 'draft');
  });

  test('retries edits the journal failed to store', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await alice.page.route(journal('append'), (route) => route.fulfill({ status: 500, body: '' }));

    await alice.control('summary').fill('Stored at last');
    await expectState(alice, 'offline');
    await alice.page.unroute(journal('append'));

    await expect(bob.control('summary')).toHaveValue('Stored at last');
    await expectState(alice, 'draft');
  });

  test('follows the draft by replaying it while the hub is unreachable', async ({ article, editor }) => {
    test.setTimeout(90_000);
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    const reachHub = await cutHub(alice.page);
    await alice.edit(id);
    await bob.edit(id);
    await expectState(alice, 'offline');

    // Without its stream, Alice's page replays the draft every 5 seconds: nothing committed is lost.
    await bob.control('title').fill('Heard without the hub');
    await expect(alice.control('title')).toHaveValue('Heard without the hub', { timeout: 20_000 });

    await reachHub();
    await alice.control('summary').fill('Back on the hub');
    await expect(bob.control('summary')).toHaveValue('Back on the hub');
    await bob.control('summary').fill('Heard on the hub');
    await expect(alice.control('summary')).toHaveValue('Heard on the hub', { timeout: 40_000 });
  });

  test('reads the draft again after an action refused as made on an older state', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Refused once');
    await alice.synchronized();
    await alice.recordStates();
    await alice.answerOnce(journal('discard'), 409);

    await alice.discardButton.click();
    await alice.decide(true);

    await expect.poll(() => alice.states()).toContain('changed');
    await expectState(alice, 'draft');
    await expect(alice.control('title')).toHaveValue('Refused once');
  });

  test('shows a refusal, and offers no Discard from it', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.control('title').fill('Refused');
    await alice.synchronized();
    await alice.answerOnce(journal('discard'), 400);

    await alice.discardButton.click();
    await alice.decide(true);

    await expectState(alice, 'failed', {
      variant: 'danger',
      icon: 'fa-triangle-exclamation',
      text: 'The server refused this action. Copy unsaved content and reload the page.',
    });
    await expect(alice.discardButton).toBeDisabled();
  });

  test('stops when the editor signed out elsewhere, keeping the local content', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    const other = await alice.context.newPage();
    await other.goto('/logout');
    await other.close();

    await alice.control('title').fill('Typed after signing out');
    await expectState(alice, 'denied', {
      variant: 'danger',
      icon: 'fa-lock',
      text: 'Collaboration access expired. Your local content is retained.',
    });
    await expect(alice.control('title')).toHaveValue('Typed after signing out');
    await expect(alice.discardButton).toBeDisabled();
  });

  test('stops on an edit the server cannot accept', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    await alice.answerOnce(journal('append'), 400);

    await alice.control('title').fill('Refused edit');
    await expectState(alice, 'incompatible', {
      variant: 'danger',
      icon: 'fa-triangle-exclamation',
      text: 'This editor is incompatible with the shared draft. Reload the page.',
    });
    // The page stays readable, so that unsent content can be copied.
    await expect(alice.control('title')).toBeEditable();
    await expect(alice.form.locator('[inert]')).toHaveCount(0);
  });

  test('stops when the server now edits a field with another editor build', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.edit(id);
    // The form's fresh configuration names another CKEditor release for its rich-text field.
    await alice.page.route(
      (url) => url.pathname === new URL(alice.routes.edit(id), 'http://x').pathname,
      async (route) => {
        if (route.request().headers()['x-collaborate-refresh'] !== '1') return route.continue();
        const response = await route.fetch();
        const body = await response.json();
        const [scope, signature] = body.configuration.grant.split('.');
        const fields = JSON.parse(Buffer.from(scope, 'base64').toString());
        for (const field of Object.values(fields.fields))
          if (field.schema) field.schema = field.schema.replace(/^ckeditor5-[^:]+/, 'ckeditor5-99.0.0');
        body.configuration.grant = `${Buffer.from(JSON.stringify(fields)).toString('base64')}.${signature}`;
        await route.fulfill({ response, json: body });
      }
    );

    await alice.control('title').fill('Before the upgrade');
    await alice.synchronized();
    await alice.saveButton.click();
    await expectState(alice, 'incompatible', {
      text: 'This editor is incompatible with the shared draft. Reload the page.',
    });
    await expect(alice.page).toHaveURL(new RegExp(`${alice.routes.edit(id).replace('?', '\\?')}$`));
  });

  test('joins once a busy server lets it', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    // A Save holds the draft, or the rate limiter refuses: Join answers 429 or 503 and the page asks again.
    await alice.answerOnce(journal('join'), 429);
    await alice.answerOnce(journal('join'), 503);
    await alice.edit(id);
    await expectState(alice, 'saved');
  });

  test('falls back to ordinary editing when the draft cannot be joined', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    await alice.page.route(journal('join'), (route) => route.fulfill({ status: 500, body: '' }));
    await alice.page.goto(alice.routes.edit(id));

    await expectState(alice, 'unavailable', {
      variant: 'secondary',
      icon: 'fa-link-slash',
      text: 'The shared draft is unavailable. Changes you save here are not shared live.',
    });
    await expect(alice.form.locator('[inert]')).toHaveCount(0);
    await expect(alice.discardButton).toBeDisabled();
    await alice.control('title').fill('Saved the ordinary way');
    await alice.save();
    await alice.page.unroute(journal('join'));
    await alice.edit(id);
    await expect(alice.control('title')).toHaveValue('Saved the ordinary way');
  });

  test('falls back to ordinary editing when an editor fails, its save an outside change for others', async ({
    article,
    editor,
  }) => {
    const id = await article({ content: '<p>Before</p>' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await bob.edit(id);
    await alice.page.addInitScript(() => {
      window.collaborateBreakEditors = true;
    });
    await alice.page.goto(alice.routes.edit(id));

    await expectState(alice, 'unavailable');
    await expect(alice.form.locator('[inert]')).toHaveCount(0);
    // The rich-text field is its bare textarea, edited and saved as the form posts it.
    await expect(alice.control('content')).toHaveValue('<p>Before</p>');
    await alice.control('title').fill('Saved without the draft');
    await alice.save();

    // Bob's draft compares itself with the record saved outside it.
    await expect(bob.conflicts).toBeVisible();
    await expect(bob.conflicts.locator('summary')).toContainText(['Title']);
    await expectState(bob, 'conflict');
  });
});

/**
 * Cuts a page off its hub, whichever the application runs: the Mercure stream fails, the WebSocket closes as
 * soon as it opens. The returned function reconnects them to the real hub.
 */
async function cutHub(page) {
  let cut = true;
  await page.route('**/.well-known/mercure?**', (route) => (cut ? route.abort() : route.fallback()));
  await page.routeWebSocket(/\/collaborate-hub$/, (socket) => {
    if (cut) socket.close({ code: 1011, reason: 'cut' });
    else socket.connectToServer();
  });

  return () => {
    cut = false;
  };
}
