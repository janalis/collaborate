import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { compile } from 'sass';
import { CURSOR_COLORS } from '../../../assets/cursors/geometry.js';

// The colours live in three places: the bundle's `colors` default, which the layer's configuration carries, the
// pointers' fallback for a layer without one, and the stylesheet that draws them. An event in a colour the page
// does not know is dropped, and one the stylesheet does not draw is invisible.

// Vitest runs from the root of the repository.
const path = (file) => resolve(file);

test('the pointers fall back on the colours the bundle configures', () => {
  const bundle = readFileSync(path('src/CollaborateBundle.php'), 'utf8');
  const list = /const array COLORS = \[([^\]]*)\]/.exec(bundle)?.[1] ?? '';
  expect([...list.matchAll(/'([^']+)'/g)].map(([, name]) => name)).toEqual(CURSOR_COLORS);
});

test('the Bootstrap 5 entry of the styles draws every one of them', () => {
  const { css } = compile(path('assets/scss/_cursors.scss'), {
    loadPaths: [path('node_modules')],
    quietDeps: true,
    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls'],
  });
  const drawn = [...css.matchAll(/\[data-collaborate-layer\] \[data-color=([\w-]+)\]/g)].map(([, name]) => name);
  expect(drawn).toEqual(CURSOR_COLORS);
});
