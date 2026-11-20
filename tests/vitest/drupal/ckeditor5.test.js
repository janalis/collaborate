afterEach(() => {
  vi.resetModules();
  delete globalThis.CKEditor5;
});

test('the bundle’s CKEditor names come from the build Drupal ships', async () => {
  const dll = vi.fn(
    (path) =>
      ({
        './src/core.js': { Plugin: 'Plugin' },
        './src/engine.js': { Model: 'Model', NoOperation: 'NoOperation', transformOperationSets: 'transform' },
        './src/utils.js': { diff: 'diff' },
      })[path]
  );
  globalThis.CKEditor5 = { dll };
  const ckeditor = await import('../../../drupal/js/ckeditor5.js');
  expect([ckeditor.Plugin, ckeditor.Model, ckeditor.transformOperationSets, ckeditor.diff]).toEqual([
    'Plugin',
    'Model',
    'transform',
    'diff',
  ]);
});

test('without the build, every name is undefined rather than an error', async () => {
  const ckeditor = await import('../../../drupal/js/ckeditor5.js');
  expect([ckeditor.Plugin, ckeditor.Model, ckeditor.diff]).toEqual([undefined, undefined, undefined]);
});
