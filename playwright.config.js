import { defineConfig, devices } from '@playwright/test';

// The three test applications run in one FrankenPHP server (tests/Application/Caddyfile), each on its own
// port with its own Mercure hub, and the plain one a second time in its `e2e_limits` environment, whose
// drafts reach their limits within a scenario. `make e2e` prepares their databases and assets first.
const ports = {
  plain: process.env.PLAIN_PORT || '8710',
  easyadmin: process.env.EASYADMIN_PORT || '8720',
  limits: process.env.LIMITS_PORT || '8730',
  host: process.env.HOST_PORT || '8740',
};

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
    {
      name: 'plain',
      testIgnore: [/easyadmin-.*\.spec\.js/, /limits\.spec\.js/, /host-.*\.spec\.js/],
      use: { baseURL: `http://127.0.0.1:${ports.plain}` },
    },
    {
      name: 'easyadmin',
      testIgnore: [/limits\.spec\.js/, /host-.*\.spec\.js/],
      use: { baseURL: `http://127.0.0.1:${ports.easyadmin}` },
    },
    { name: 'limits', testMatch: /limits\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.limits}` } },
    // The host application: a library of records, every cursor option on, no drafts.
    { name: 'host', testMatch: /host-.*\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.host}` } },
  ],
  webServer: {
    command: `${process.env.FRANKENPHP || 'frankenphp'} run --config tests/Application/Caddyfile`,
    url: `http://127.0.0.1:${ports.limits}/login`,
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
    stderr: 'pipe',
    env: { PLAIN_PORT: ports.plain, EASYADMIN_PORT: ports.easyadmin, LIMITS_PORT: ports.limits, HOST_PORT: ports.host },
  },
});
