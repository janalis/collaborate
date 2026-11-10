import { existsSync } from 'node:fs';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

// The three test applications run in one FrankenPHP server (tests/Application/Caddyfile), each on its own
// port with its own Mercure hub (the plain one again on the bundle's WebSocket hub, `plain-ws`), and the plain one again in its `e2e_limits` environment, whose drafts
// reach their limits within a scenario, and in one environment per other stock form theme it is tested with. `make e2e` prepares their databases and assets first.
const ports = {
  plain: process.env.PLAIN_PORT || '8710',
  easyadmin: process.env.EASYADMIN_PORT || '8720',
  limits: process.env.LIMITS_PORT || '8730',
  host: process.env.HOST_PORT || '8740',
  // The plain application again, rendered by another stock form theme each (PlainKernel::FORM_THEMES).
  'plain-div': process.env.PLAIN_DIV_PORT || '8750',
  'plain-table': process.env.PLAIN_TABLE_PORT || '8760',
  'plain-tailwind': process.env.PLAIN_TAILWIND_PORT || '8770',
  // The plain application without Mercure: the bundle's WebSocket hub, behind the application's origin.
  'plain-ws': process.env.PLAIN_WS_PORT || '8780',
  // The Drupal test site (tests/Application/Drupal, `make drupal-install`), with its own Mercure hub.
  drupal: process.env.DRUPAL_PORT || '8790',
};
// Its scenarios run once the site is built: `make e2e` needs no Drupal.
const drupal = existsSync('tests/Application/Drupal/web/sites/default/settings.php');
const drupalPhpPort = process.env.DRUPAL_PHP_PORT || '8791';
// The unix sockets of the WebSocket hub (`collaborate:hub:serve`), which FrankenPHP proxies.
const hubRun = process.env.COLLABORATE_HUB_RUN || path.resolve('tests/Application/var/plain/run');
const plainIgnore = [/easyadmin-.*\.spec\.js/, /limits\.spec\.js/, /host-.*\.spec\.js/, /drupal-.*\.spec\.js/];

export default defineConfig({
  testDir: 'tests/playwright',
  // Scenarios share a server and a database per application: each creates its own article, and they
  // run one at a time so that one scenario's presence never shows on another's page.
  workers: 1,
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: { ...devices['Desktop Chrome'], trace: 'retain-on-failure' },
  projects: [
    // `easyadmin-*.spec.js` reads EasyAdmin's markup; `limits.spec.js` needs the small limits.
    { name: 'plain', testIgnore: plainIgnore, use: { baseURL: `http://127.0.0.1:${ports.plain}` } },
    ...['plain-div', 'plain-table', 'plain-tailwind'].map((name) => ({
      name,
      testIgnore: plainIgnore,
      use: { baseURL: `http://127.0.0.1:${ports[name]}` },
    })),
    {
      name: 'easyadmin',
      testIgnore: [/limits\.spec\.js/, /host-.*\.spec\.js/, /drupal-.*\.spec\.js/],
      use: { baseURL: `http://127.0.0.1:${ports.easyadmin}` },
    },
    { name: 'limits', testMatch: /limits\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.limits}` } },
    // The host application: a library of records, every cursor option on, no drafts.
    { name: 'host', testMatch: /host-.*\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.host}` } },
    // The scenarios that cross the hub most, over the WebSocket transport.
    {
      name: 'plain-ws',
      testMatch: [
        /\/collaboration\.spec\.js$/,
        /\/cursors\.spec\.js$/,
        /\/presence\.spec\.js$/,
        /\/draft-states\.spec\.js$/,
      ],
      use: { baseURL: `http://127.0.0.1:${ports['plain-ws']}` },
    },
    // The Drupal module on Claro, with Navigation's top bar.
    ...(drupal
      ? [{ name: 'drupal', testMatch: /drupal-.*\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.drupal}` } }]
      : []),
  ],
  webServer: [
    {
      command: `${process.env.FRANKENPHP || 'frankenphp'} run --config tests/Application/Caddyfile`,
      url: `http://127.0.0.1:${ports.limits}/login`,
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'pipe',
      env: {
        PLAIN_PORT: ports.plain,
        EASYADMIN_PORT: ports.easyadmin,
        LIMITS_PORT: ports.limits,
        HOST_PORT: ports.host,
        PLAIN_DIV_PORT: ports['plain-div'],
        PLAIN_TABLE_PORT: ports['plain-table'],
        PLAIN_TAILWIND_PORT: ports['plain-tailwind'],
        PLAIN_WS_PORT: ports['plain-ws'],
        COLLABORATE_HUB_RUN: hubRun,
      },
    },
    {
      command: `${process.env.PHP || 'php'} tests/Application/bin/console.php collaborate:hub:serve`,
      // Through FrankenPHP: the hub's private side answers its health check on a unix socket.
      url: `http://127.0.0.1:${ports['plain-ws']}/_hub/health`,
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'pipe',
      env: { COLLABORATE_APP: 'plain', APP_ENV: 'e2e_ws', COLLABORATE_HUB_RUN: hubRun },
    },
    ...(drupal
      ? [
          {
            // The site's PHP, behind the origin FrankenPHP serves with its Mercure hub.
            // From the web root, which Drupal resolves its templates from.
            command: `${process.env.PHP || 'php'} -S 127.0.0.1:${drupalPhpPort} .ht.router.php`,
            cwd: 'tests/Application/Drupal/web',
            url: `http://127.0.0.1:${drupalPhpPort}/user/login`,
            reuseExistingServer: !process.env.CI,
            stdout: 'ignore',
            stderr: 'ignore',
            env: { PHP_CLI_SERVER_WORKERS: '8', DRUPAL_PORT: ports.drupal },
          },
          {
            command: `${process.env.FRANKENPHP || 'frankenphp'} run --config tests/Application/Drupal/Caddyfile`,
            url: `http://127.0.0.1:${ports.drupal}/user/login`,
            reuseExistingServer: !process.env.CI,
            stdout: 'ignore',
            stderr: 'pipe',
            env: { DRUPAL_PORT: ports.drupal, DRUPAL_PHP_PORT: drupalPhpPort },
          },
        ]
      : []),
  ],
});
