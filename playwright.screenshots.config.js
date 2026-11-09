import { defineConfig } from '@playwright/test';
import e2e from './playwright.config.js';

// The screenshots of the documentation and the README (docs/public/screenshots/), taken in the test
// applications that the browser scenarios run in, with the same servers. `make screenshots` rewrites only
// the images that changed beyond the tolerance below; the screenshots workflow runs it on main and opens
// a pull request with what changed. CI's renderer (Ubuntu's Chromium and fonts) is the reference one.
//
// The projects keep the names of the scenarios' (`easyadmin`, `plain`, `host`), which the fixtures of
// tests/playwright/lib/app.js read their routes by.
const shots = ['easyadmin', 'plain', 'host'];

export default defineConfig({
  ...e2e,
  testDir: 'tests/screenshots',
  testMatch: /\.shot\.js$/,
  retries: 0,
  // The test applications' server; the WebSocket hub serves only the scenarios of `plain-ws`.
  webServer: e2e.webServer.slice(0, 1),
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
