// `ckeditor5` for the bundle's modules under Drupal: the names they import, from the CKEditor 5 build Drupal ships
// (its DLL, `CKEditor5.dll`), so that the shared editors and the module's plugin speak to the editors Drupal creates.
// The DLL is loaded with every shared form (the module attaches `core/ckeditor5`).
const dll = globalThis.CKEditor5?.dll;
const load = (name) => (typeof dll === 'function' ? dll(`./src/${name}.js`) : {});
const core = load('core');
const engine = load('engine');
const utils = load('utils');

export const { Plugin } = core;
export const { MergeOperation, Model, ModelLivePosition, MoveOperation, NoOperation, transformOperationSets } = engine;
export const { diff } = utils;
