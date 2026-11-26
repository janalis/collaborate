import { expect, test } from '@playwright/test';
import { signedIn } from './lib/host.js';

test('headless source and structural controls converge across two editors', async ({ browser, baseURL }) => {
  const path = `/admin/text/example-${Date.now()}`;
  const alice = await signedIn(browser, baseURL, 'alice', path);
  const bob = await signedIn(browser, baseURL, 'bob', path);
  try {
    for (const page of [alice.page, bob.page]) await expect(page.getByRole('status')).toHaveText('Saved');
    await alice.page.getByLabel('Source', { exact: true }).fill('Hello shared world.');
    await expect(bob.page.getByLabel('Source', { exact: true })).toHaveValue('Hello shared world.');
    await bob.page.getByLabel('Source', { exact: true }).evaluate((input) => {
      input.focus();
      input.setSelectionRange(6, 12);
    });
    await bob.page.getByRole('button', { name: 'Wrap selected text' }).click();
    for (const page of [alice.page, bob.page]) {
      await expect(page.getByLabel('Source', { exact: true })).toHaveValue('Hello [shared] world.');
      await expect(page.getByLabel('Text preview')).toHaveText('Hello [shared] world.');
    }
    await bob.page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(alice.page.getByLabel('Source', { exact: true })).toHaveValue('Hello shared world.');
    await alice.page.getByLabel('Source', { exact: true }).fill('Hello  world.');
    await expect(bob.page.getByLabel('Source', { exact: true })).toHaveValue('Hello  world.');
    await bob.page.getByLabel('Source', { exact: true }).fill('Hello  world. Peer.');
    await expect(alice.page.getByLabel('Source', { exact: true })).toHaveValue('Hello  world. Peer.');
    await alice.page.getByRole('button', { name: 'Undo', exact: true }).click();
    for (const page of [alice.page, bob.page]) {
      await expect(page.getByLabel('Source', { exact: true })).toHaveValue('Hello shared world. Peer.');
    }
    await bob.page.reload();
    await expect(bob.page.getByLabel('Source', { exact: true })).toHaveValue('Hello shared world. Peer.');
    await expect(bob.page.getByRole('status')).toHaveText('Saved');
  } finally {
    await alice.context.close();
    await bob.context.close();
  }
});
