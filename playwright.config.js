import { defineConfig, devices } from '@playwright/test';

// The three test applications run in one FrankenPHP server (tests/Application/Caddyfile), each on its own
// port with its own Mercure hub, and the plain one again in its `e2e_limits` environment, whose drafts
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
};
const plainIgnore = [/easyadmin-.*\.spec\.js/, /limits\.spec\.js/, /host-.*\.spec\.js/];

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
    env: {
      PLAIN_PORT: ports.plain,
      EASYADMIN_PORT: ports.easyadmin,
      LIMITS_PORT: ports.limits,
      HOST_PORT: ports.host,
      PLAIN_DIV_PORT: ports['plain-div'],
      PLAIN_TABLE_PORT: ports['plain-table'],
      PLAIN_TAILWIND_PORT: ports['plain-tailwind'],
    },
  },
});
