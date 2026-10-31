import { defineConfig } from 'vitest/config';

// Stylesheets imported from JS (CKEditor's) become <link> tags in the browser; under test they resolve
// to an empty module.
const styleStub = {
  name: 'style-stub',
  enforce: 'pre',
  resolveId: (id) => (/\.(css|scss)$/.test(id) ? '\0style-stub' : null),
  load: (id) => ('\0style-stub' === id ? 'export default {};' : null),
};

export default defineConfig({
  plugins: [styleStub],
  test: {
    environment: 'jsdom',
    // The page URL jsdom starts with; tests compare absolute URLs against it.
    environmentOptions: { jsdom: { url: 'http://localhost/' } },
    // describe / it / expect / vi are ambient in the test files.
    globals: true,
    // tests/vitest mirrors assets. The host's import map provides the bare specifiers (`ckeditor5`,
    // `@microsoft/fetch-event-source`, `bootstrap/js/src/modal.js`); tests resolve them from node_modules.
    include: ['tests/vitest/**/*.test.js'],
    coverage: {
      provider: 'v8',
      include: ['assets/**/*.js'],
      reportsDirectory: 'var/coverage-vitest',
      reporter: ['html', 'text'],
    },
  },
});
