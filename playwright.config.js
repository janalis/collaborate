import { defineConfig, devices } from '@playwright/test';

// Both test applications run in one FrankenPHP server (tests/Application/Caddyfile), each on its own
// port with its own Mercure hub. `make e2e` prepares their databases and assets first.
const ports = { plain: process.env.PLAIN_PORT || '8710', easyadmin: process.env.EASYADMIN_PORT || '8720' };

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
    { name: 'plain', testIgnore: /easyadmin\.spec\.js/, use: { baseURL: `http://127.0.0.1:${ports.plain}` } },
    { name: 'easyadmin', use: { baseURL: `http://127.0.0.1:${ports.easyadmin}` } },
  ],
  webServer: {
    command: `${process.env.FRANKENPHP || 'frankenphp'} run --config tests/Application/Caddyfile`,
    url: `http://127.0.0.1:${ports.easyadmin}/login`,
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
    stderr: 'pipe',
    env: { PLAIN_PORT: ports.plain, EASYADMIN_PORT: ports.easyadmin },
  },
});
