// Builds what the test applications' pages load and git does not carry, into tests/Application/var/assets
// (mapped as the `build/` asset namespace): the stylesheets, compiled with Dart Sass from the bundle's
// partials and Bootstrap's, and an ES module copy of @microsoft/fetch-event-source whose relative imports
// carry their `.js` extension, as browsers need. Run from the repository root after `npm ci`.
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const output = join(root, 'tests/Application/var/assets');

const library = join(root, 'node_modules/@microsoft/fetch-event-source/lib/esm');
mkdirSync(join(output, 'fetch-event-source'), { recursive: true });
for (const file of readdirSync(library).filter((name) => name.endsWith('.js'))) {
  const source = readFileSync(join(library, file), 'utf8')
    .replace(/(from\s+['"]\.\/[\w-]+)(['"])/g, '$1.js$2')
    .replace(/^\/\/# sourceMappingURL=.*$/m, '');
  writeFileSync(join(output, 'fetch-event-source', file), source);
}

for (const sheet of ['plain', 'collaborate']) {
  const { css } = sass.compile(join(root, `tests/Application/Shared/assets/styles/${sheet}.scss`), {
    loadPaths: [join(root, 'node_modules'), join(root, 'assets/scss')],
    quietDeps: true,
    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
  });
  writeFileSync(join(output, `${sheet}.css`), css);
}
console.log(`Test application assets written to ${output}`);
