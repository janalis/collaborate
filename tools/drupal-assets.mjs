// Builds what the Drupal module ships of the bundle's front end, for sites without Node: its stylesheet
// (drupal/css/collaborate.css: the layer in Claro's palette, the draft bar, the notifications, the Drupal bridge's
// partial) and an ES
// module copy of @microsoft/fetch-event-source (drupal/js/vendor/), whose relative imports carry their `.js`
// extension, as browsers need. Run from the repository root after `npm ci`: `node tools/drupal-assets.mjs`.
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const drupal = join(root, 'drupal');

const library = join(root, 'node_modules/@microsoft/fetch-event-source/lib/esm');
const vendor = join(drupal, 'js/vendor/fetch-event-source');
mkdirSync(vendor, { recursive: true });
for (const file of readdirSync(library).filter((name) => name.endsWith('.js'))) {
  const source = readFileSync(join(library, file), 'utf8')
    .replace(/(from\s+['"]\.\/[\w-]+)(['"])/g, '$1.js$2')
    .replace(/^\/\/# sourceMappingURL=.*$/m, '');
  writeFileSync(join(vendor, file), source);
}
writeFileSync(
  join(vendor, 'LICENSE'),
  readFileSync(join(root, 'node_modules/@microsoft/fetch-event-source/LICENSE'), 'utf8')
);

// The colours of the bundle's `colors` default the module configures, as Bootstrap 5.3 defines them.
const palette = {
  blue: '#0d6efd',
  indigo: '#6610f2',
  purple: '#6f42c1',
  pink: '#d63384',
  red: '#dc3545',
  orange: '#fd7e14',
  yellow: '#ffc107',
  green: '#198754',
  teal: '#20c997',
  cyan: '#0dcaf0',
  black: '#000',
  white: '#fff',
  gray: '#6c757d',
  'gray-dark': '#343a40',
};
const colors = Object.entries(palette)
  .map(([name, value]) => `'${name}': ${value}`)
  .join(', ');
const { css } = sass.compileString(
  `@use 'layer' with ($colors: (${colors}));\n@use 'draft';\n@use 'notifications';\n@use 'drupal';\n`,
  { loadPaths: [join(root, 'assets/scss')], style: 'compressed', silenceDeprecations: ['import'] }
);
mkdirSync(join(drupal, 'css'), { recursive: true });
writeFileSync(
  join(drupal, 'css/collaborate.css'),
  `/* Built by tools/drupal-assets.mjs from janalis/collaborate's assets/scss: do not edit. */\n${css}\n`
);
