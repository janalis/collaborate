import { expect, test } from './lib/app.js';

// Shared pointers, carets and field marks between two editors of one page.
const cursorOf = (editor, userId) => editor.layer.locator(`.collaborate-cursor[data-user-id="${userId}"]`);
const caretOf = (editor, userId) => editor.layer.locator(`.collaborate-caret[data-user-id="${userId}"]`);
const BOB = 16;

/** Moves the mouse onto `locator`, at a fraction of its box. */
async function hover(page, locator, fx = 0.5, fy = 0.5) {
  const box = await locator.boundingBox();
  await page.mouse.move(box.x + box.width * fx - 5, box.y + box.height * fy - 2);
  await page.mouse.move(box.x + box.width * fx, box.y + box.height * fy, { steps: 3 });
}

/** The drawn pointer's position in the viewport (its translate3d). */
const position = (cursor) =>
  cursor.evaluate((element) => {
    const [, x, y] = /translate3d\(([-\d.]+)px, ([-\d.]+)px/.exec(element.style.transform) ?? [];
    return { x: Number(x), y: Number(y) };
  });

test.describe('shared pointers', () => {
  test("draw a peer's pointer with their name and colour, in the shape they see", async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();

    await hover(bob.page, bob.control('title'));
    const cursor = cursorOf(alice, BOB);
    await expect(cursor).toBeVisible();
    await expect(cursor.locator('.collaborate-cursor__label')).toHaveText('Bob');
    await expect(cursor).toHaveAttribute('data-color', /.+/);
    // An I-beam over a text field, a hand over a button.
    await expect(cursor).toHaveAttribute('data-shape', 'text');
    // The bar's Save, shown once the draft holds an unsaved change.
    await bob.control('summary').fill('Unsaved');
    await expect(bob.barSaveButton).toBeVisible();
    await hover(bob.page, bob.barSaveButton);
    await expect(cursor).toHaveAttribute('data-shape', 'pointer');
  });

  test('anchor the pointer to the element under it, whatever the window size', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice', { viewport: { width: 1400, height: 900 } });
    const bob = await editor('bob', { viewport: { width: 900, height: 700 } });
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();

    // Bob points at the right end of the title field: on Alice's wider page, so does his pointer.
    await hover(bob.page, bob.control('title'), 0.9, 0.5);
    const cursor = cursorOf(alice, BOB);
    await expect(cursor).toBeVisible();
    const box = await alice.control('title').boundingBox();
    expect(box.width).toBeGreaterThan((await bob.control('title').boundingBox()).width);
    await expect
      .poll(async () => {
        const { x, y } = await position(cursor);
        return Math.abs(x - (box.x + box.width * 0.9)) < 6 && Math.abs(y - (box.y + box.height * 0.5)) < 6;
      })
      .toBe(true);

    // Scrolled out of Alice's view, the target hides the pointer.
    await alice.page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await expect(alice.control('title')).not.toBeInViewport();
    await expect(cursor).toBeHidden();
  });

  test('show a touch as a circle that fades once contact ends', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob', { hasTouch: true });
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();

    const box = await bob.control('summary').boundingBox();
    await bob.page.touchscreen.tap(box.x + 20, box.y + 10);
    const cursor = cursorOf(alice, BOB);
    await expect(cursor).toHaveAttribute('data-style', 'touch');
    await expect(cursor).toHaveCount(0);
  });
});

test.describe('shared carets', () => {
  test('show a caret and its selection in a text field, with a name flag that fades', async ({ article, editor }) => {
    const id = await article({ summary: 'Select these words' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();

    await bob.control('summary').focus();
    await bob.control('summary').evaluate((field) => field.setSelectionRange(7, 18));
    const caret = caretOf(alice, BOB);
    await expect(caret).toBeVisible();
    await expect(caret.locator('.collaborate-caret__label')).toHaveText('Bob');
    await expect(caret.locator('.collaborate-caret__selection > div')).not.toHaveCount(0);
    await expect(caret).toHaveClass(/collaborate-caret--labelled/);
    // The flag fades two seconds after the caret last moved; the caret stays.
    await expect(caret).not.toHaveClass(/collaborate-caret--labelled/, { timeout: 5000 });
    await expect(caret).toBeVisible();
    // The caret stays while the pointer is elsewhere, and leaves with the focus.
    await hover(bob.page, bob.control('title'));
    await expect(caret).toBeVisible();
    await bob.control('title').evaluate((field) => field.blur());
    await bob.page.locator('h1').click();
    await expect(caret).toHaveCount(0);
  });

  test('show a caret in a rich-text field', async ({ article, editor }) => {
    const id = await article({ content: '<p>First</p><p>Second</p>' });
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();

    await bob.caretRich('end');
    const caret = caretOf(alice, BOB);
    await expect(caret).toBeVisible();
    // The bar stands on the second paragraph of Alice's editor.
    const bar = await caret.locator('.collaborate-caret__bar').boundingBox();
    const second = await alice.richText.locator('p').nth(1).boundingBox();
    expect(bar.y + bar.height / 2).toBeGreaterThan(second.y);
    expect(bar.y + bar.height / 2).toBeLessThan(second.y + second.height);
  });
});

test.describe('field marks', () => {
  test("outline the field each peer is in, a choice, a checkbox, radios and a rich editor's included", async ({
    article,
    editor,
  }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    await expect(alice.presence.locator('[title="Bob"]')).toBeVisible();
    const marked = alice.form.locator('[data-peer-focus-label="Bob"]');
    const marks = async (control) => {
      // The field's group carries the mark (or the control itself, outside any group).
      await expect(marked).toHaveCount(1);
      await expect(marked).toHaveAttribute('data-peer-focus', /.+/);
      await expect
        .poll(() =>
          marked.evaluate(
            (mark, name) => mark.matches(`[name="${name}"]`) || mark.querySelector(`[name="${name}"]`) !== null,
            control
          )
        )
        .toBe(true);
    };

    await bob.control('status').focus();
    await marks(alice.name('status'));
    await bob.control('featured').focus();
    await marks(alice.name('featured'));
    await bob.page.locator(`[name="${bob.name('audience')}"][value="members"]`).focus();
    await marks(alice.name('audience'));
    await bob.richText.click();
    await marks(alice.name('content'));
    if (bob.easyAdmin) {
      // The autocomplete's search input sits beside the select it fills.
      await bob.form.locator(`[name="${bob.name('category', 'autocomplete')}"] ~ .ts-wrapper .ts-control`).click();
      await marks(alice.name('category', 'autocomplete'));
    } else {
      await bob.category.focus();
      await marks(alice.name('category'));
    }
    // A password is never named.
    await bob.control('previewPassword').focus();
    await expect(marked).toHaveCount(0);
    await expect(caretOf(alice, BOB)).toHaveCount(0);
  });

  test('share one outline between several peers in one field, naming them all', async ({ article, editor }) => {
    const id = await article();
    const alice = await editor('alice');
    const bob = await editor('bob');
    const carol = await editor('carol');
    await alice.edit(id);
    await bob.edit(id);
    await carol.edit(id);
    await expect(alice.presence.locator('.collaborate-presence__user')).toHaveCount(2);

    await bob.control('title').focus();
    await carol.control('title').focus();
    const shared = alice.form.locator('[data-peer-focus-label*="Bob"][data-peer-focus-label*="Carol"]');
    await expect(shared).toHaveCount(1);
    await expect(shared).toHaveAttribute('data-peer-focus-label', /^(Bob, Carol|Carol, Bob)$/);
    await carol.control('summary').focus();
    await expect(alice.form.locator('[data-peer-focus-label="Bob"]')).toHaveCount(1);
    await expect(alice.form.locator('[data-peer-focus-label="Carol"]')).toHaveCount(1);
  });
});
