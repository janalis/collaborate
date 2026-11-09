import { expect, test } from '@playwright/test';
import { layer, notify, signedIn } from '../../playwright/lib/host.js';

// The bell of the host library (tests/Application/Host), open on what Alice was told: `make screenshots`
// prepares the databases afresh, so these three are all she has.

const ALICE = 15;

test('notifications', async ({ browser, baseURL, request }) => {
  expect((await request.post('/e2e/folders/music/revoke', { data: { revoked: false } })).ok()).toBeTruthy();
  const alice = await signedIn(browser, baseURL, 'alice');
  try {
    const page = alice.page;
    await expect(layer(page)).toHaveAttribute('data-connected', 'true');
    // The toasts of the notifications would cover the page. A context of Playwright is incognito, where Chrome
    // has no Push API: the menu would say this browser blocks notifications, which a reader's would not.
    await page.addStyleTag({
      content: '.toast, .collaborate-notifications__push-denied { display: none !important; }',
    });
    await notify(request, ALICE, 'Kind of Blue');
    await notify(request, ALICE, 'A Love Supreme');
    await notify(request, ALICE, 'The library closes at 6 pm on Friday.', 'announcement');
    await expect(page.locator('.collaborate-notifications__count')).toHaveText('3');

    await page.locator('.collaborate-notifications__toggle').click();
    const menu = page.locator('.collaborate-notifications__menu');
    await expect(menu.locator('.collaborate-notification')).toHaveCount(3);
    await page.mouse.move(0, 0);
    // The dates of the notifications are when the screenshot was taken: one date for every run.
    await menu.locator('.collaborate-notification__date').evaluateAll((dates) => {
      for (const date of dates) date.textContent = 'March 3, 2027 at 9:30 AM';
    });

    const box = await menu.boundingBox();
    await expect(page).toHaveScreenshot('notifications.png', {
      clip: { x: 0, y: 0, width: page.viewportSize().width, height: box.y + box.height + 24 },
    });
  } finally {
    await alice.context.close();
  }
});
