import { existsSync } from 'node:fs';
import { defineConfig } from '@playwright/test';
import e2e from './playwright.config.js';

// The screenshots of the documentation and the README (docs/public/screenshots/), taken in the test
// applications that the browser scenarios run in, with the same servers. `make screenshots` rewrites only
// the images that changed beyond the tolerance below; the screenshots workflow runs it on main and opens
// a pull request with what changed. CI's renderer (Ubuntu's Chromium and fonts) is the reference one.
//
// The projects keep the names of the scenarios' (`easyadmin`, `plain`, `host`, `drupal`), which the fixtures of
// tests/playwright/lib/app.js read their routes by. Drupal's are taken once its test site is built
// (`make drupal-install`), on its own servers.
const drupal = existsSync('tests/Application/Drupal/web/sites/default/settings.php');
const shots = ['easyadmin', 'plain', 'host', ...(drupal ? ['drupal'] : [])];

export default defineConfig({
  ...e2e,
  testDir: 'tests/screenshots',
  testMatch: /\.shot\.js$/,
  retries: 0,
  // The test applications' server, and the Drupal site's; the WebSocket hub serves only the scenarios of
  // `plain-ws`.
  webServer: [e2e.webServer[0], ...(drupal ? e2e.webServer.slice(-2) : [])],
  snapshotPathTemplate: 'docs/public/screenshots/{arg}{ext}',
  expect: {
    ...e2e.expect,
    // Anti-aliasing noise is not a change worth a pull request.
    toHaveScreenshot: { animations: 'disabled', caret: 'hide', scale: 'device', maxDiffPixelRatio: 0.01 },
  },
  use: {
    ...e2e.use,
    viewport: { width: 1280, height: 800 },
    deviceScaleFactor: 2,
    colorScheme: 'light',
    locale: 'en-US',
    timezoneId: 'UTC',
  },
  projects: e2e.projects
    .filter((project) => shots.includes(project.name))
    .map(({ name, use }) => ({ name, testMatch: new RegExp(`/${name}/.*\\.shot\\.js$`), use })),
});
