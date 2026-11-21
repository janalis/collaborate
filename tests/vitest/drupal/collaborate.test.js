// The module's page script on a shared entity form: Drupal's editors created on the draft, its AJAX rebuilds
// bound again, a typed empty row made an item, and every failure to load leaving the form to Drupal. The bundle's
// modules are replaced: their own tests run them.

/** A session of the draft, as the script reads it. */
function fakeSession(overrides = {}) {
  const session = {
    initialized: true,
    stopped: false,
    editorConfiguration: vi.fn(async () => ({})),
    // As the session does: it stops following the draft.
    editorFailed: vi.fn(() => {
      session.stopped = true;
    }),
    initializeControls: vi.fn(),
    scanCollections: vi.fn(),
    // The editors the draft waits for, by field.
    editorWaiters: new Map([['body', { ready: false }]]),
    ...overrides,
  };
  return session;
}

/** A session whose draft follows the field in an editor. */
const followed = (configuration = {}) =>
  fakeSession({ editorConfiguration: vi.fn(async () => ({ collaborate: { field: 'body' }, ...configuration })) });

let errors;
let attach;

/**
 * The page script, evaluated on the page each test sets.
 *
 * @param {{ session?: object | null, drafts?: boolean, plugin?: boolean, drupal?: boolean }} [options]
 */
async function load({ session = fakeSession(), drafts = true, plugin = true, drupal = true } = {}) {
  for (const path of ['bridges/drupal.js', 'cursors/cursors.js', 'updates.js'])
    vi.doMock(`@janalis/collaborate/${path}`, () => ({}));
  vi.doMock('@janalis/collaborate/draft/session.js', () => {
    if (!drafts) throw new Error('No drafts.');
    return { sessionFor: vi.fn(() => session) };
  });
  vi.doMock('@janalis/collaborate/ckeditor5/collaborate.js', () => {
    if (!plugin) throw new Error('No plugin.');
    return { default: 'CollaboratePlugin' };
  });
  attach = vi.fn();
  if (drupal) globalThis.Drupal = { behaviors: {}, editors: { ckeditor5: { attach } } };
  await import('../../../drupal/js/collaborate.js');
  return session;
}

/** Drupal's CKEditor 5 build: its `Plugin` base, what the module's plugin extends. */
function build() {
  class Plugin {
    constructor(editor) {
      this.editor = editor;
    }
  }
  globalThis.CKEditor5 = { dll: vi.fn(() => ({ Plugin })) };
  return globalThis.CKEditor5;
}

const format = { editorSettings: { plugins: ['basic'], config: { toolbar: [] } } };

beforeEach(() => {
  document.body.innerHTML =
    '<form data-collaborate="{}"><textarea name="body"></textarea><table><tr data-collaborate-pending><td>' +
    '<input name="notes[t0][value]"><input type="checkbox" name="notes[t0][on]"><input type="file" name="f">' +
    '<select multiple name="notes[t0][tags]"><option value="a">A</option><option value="b">B</option></select>' +
    '</td></tr></table></form><textarea name="alone"></textarea>';
  errors = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  vi.resetModules();
  vi.restoreAllMocks();
  delete globalThis.Drupal;
  delete globalThis.CKEditor5;
});

const body = () => /** @type {HTMLTextAreaElement} */ (document.querySelector('[name="body"]'));

test("a shared field's editor follows the draft, created on its content with the bundle's plugin", async () => {
  const ckeditor = build();
  const getSession = () => ({ editorReady });
  const editorReady = vi.fn();
  const session = await load({
    session: fakeSession({
      editorConfiguration: vi.fn(async () => ({ collaborate: { getSession }, initialData: '<p>Shared</p>' })),
    }),
  });

  expect(globalThis.Drupal.editors.ckeditor5.attach(body(), format)).toBe(true);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledOnce());
  expect(body().value).toBe('<p>Shared</p>');
  expect(attach.mock.calls[0][1].editorSettings).toEqual({
    plugins: ['basic', 'collaborate.Collaborate', 'collaborate.CollaborateReady'],
    config: { toolbar: [], collaborate: { getSession }, collaborateReady: expect.any(Function) },
  });
  expect(session.editorFailed).not.toHaveBeenCalled();

  // Drupal finds the plugins on its build; the second tells the session once its editor is ready.
  expect(ckeditor.collaborate.Collaborate).toBe('CollaboratePlugin');
  const { CollaborateReady } = ckeditor.collaborate;
  expect(CollaborateReady.pluginName).toBe('CollaborateReady');
  const on = vi.fn((_event, ready) => ready());
  const heard = vi.fn();
  /** @param {Record<string, unknown>} config */
  const editor = (config) => ({ config: { get: (key) => config[key] }, on, sourceElement: body() });
  new CollaborateReady(editor({ collaborate: { getSession }, collaborateReady: heard })).init();
  expect(editorReady).toHaveBeenCalledWith('body');
  expect(heard).toHaveBeenCalledOnce();
  // An editor this script did not attach has no one else to tell.
  new CollaborateReady(editor({ collaborate: { getSession } })).init();
  expect(editorReady).toHaveBeenCalledTimes(2);
  new CollaborateReady(editor({})).init();
  expect(on).toHaveBeenCalledTimes(2);
});

test("without Drupal's editor build, the field takes Drupal's own editor and the form stops following its draft", async () => {
  const session = await load({ session: followed() });

  globalThis.Drupal.editors.ckeditor5.attach(body(), format);

  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(session.editorFailed).toHaveBeenCalledOnce();
  // Said once, though the field tried again after the page's first load.
  expect(errors.mock.calls.map(([message]) => message)).toEqual(['Collaborate: the shared editors could not load.']);
});

test("Drupal's editor build arriving after the page loaded serves the next shared field", async () => {
  const session = await load({ session: followed() });
  await vi.waitFor(() => expect(errors).toHaveBeenCalledOnce());
  const ckeditor = build();

  globalThis.Drupal.editors.ckeditor5.attach(body(), format);

  await vi.waitFor(() => expect(attach).toHaveBeenCalledOnce());
  expect(attach.mock.calls[0][1].editorSettings.plugins).toContain('collaborate.Collaborate');
  expect(ckeditor.collaborate.Collaborate).toBe('CollaboratePlugin');
  expect(session.editorFailed).not.toHaveBeenCalled();
});

test('settings the draft’s editor cannot extend give the field Drupal’s editor, the form released', async () => {
  build();
  const session = await load({ session: followed() });
  const unusual = { editorSettings: {} };
  globalThis.Drupal.editors.ckeditor5.attach(body(), unusual);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), unusual));
  expect(session.editorFailed).toHaveBeenCalledOnce();
  expect(errors).toHaveBeenCalledWith(expect.any(TypeError));
});

test('a plugin that does not load leaves a form whose draft follows no editor following it', async () => {
  build();
  const session = await load({ plugin: false });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(session.editorFailed).not.toHaveBeenCalled();

  const input = /** @type {HTMLInputElement} */ (document.querySelector('[name="notes[t0][value]"]'));
  input.dispatchEvent(new Event('input', { bubbles: true }));
  expect(session.scanCollections).toHaveBeenCalledOnce();

  // An editor the draft follows needs it: the form stops following its draft, and its rows with it.
  const editor = await (async () => {
    vi.resetModules();
    return load({ plugin: false, session: followed() });
  })();
  // Its rows follow the draft until the editor fails.
  await vi.waitFor(() => {
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect(editor.scanCollections).toHaveBeenCalledOnce();
  });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(editor.editorFailed).toHaveBeenCalledOnce();
  input.dispatchEvent(new Event('input', { bubbles: true }));
  expect(editor.scanCollections).toHaveBeenCalledOnce();
});

test('without the drafts, every field takes Drupal’s own editor, and the failure is said once', async () => {
  await load({ drafts: false });

  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  globalThis.Drupal.behaviors.collaborate.attach(document.querySelector('form'));

  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(
    errors.mock.calls.filter(([message]) => message === 'Collaborate: the shared draft could not load.')
  ).toHaveLength(1);
});

test('a field the draft does not follow takes Drupal’s editor at once, or once its configuration fails', async () => {
  build();
  const session = await load({
    session: fakeSession({ editorConfiguration: vi.fn(async () => Promise.reject(new Error('No grant.'))) }),
  });
  const alone = /** @type {HTMLTextAreaElement} */ (document.querySelector('[name="alone"]'));
  globalThis.Drupal.editors.ckeditor5.attach(alone, format);
  expect(attach).toHaveBeenCalledWith(alone, format);

  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(session.editorFailed).toHaveBeenCalledOnce();
});

test("an editor Drupal fails to create once attached releases the form, and Drupal's class is left as it was", async () => {
  build();
  /** @type {Promise<unknown>[]} */
  const creations = [];
  let failure = new Error('A plugin failed.');
  const create = vi.fn(async () => {
    if (failure) throw failure;
  });
  globalThis.CKEditor5.editorClassic = { ClassicEditor: { create } };
  // Drupal's attach calls `create` before it returns, and logs a failure.
  const drupal = () => {
    const created = globalThis.CKEditor5.editorClassic.ClassicEditor.create('element', {});
    creations.push(created);
    created.catch(() => {});
  };
  /** Its creation settled: the module's handler, chained first, has run. */
  const settled = () => creations.at(-1).catch(() => {});

  const session = await load({ session: followed() });
  attach.mockImplementation(drupal);
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(creations).toHaveLength(1));
  await settled();
  expect(session.editorFailed).toHaveBeenCalledOnce();
  expect(create).toHaveBeenCalledWith('element', {});
  expect(globalThis.CKEditor5.editorClassic.ClassicEditor.create).toBe(create);

  // A failure once its editor said it was ready leaves the draft as it is.
  vi.resetModules();
  const ready = await load({ session: followed() });
  attach.mockImplementation((_element, settings) => {
    settings.editorSettings.config.collaborateReady();
    drupal();
  });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(creations).toHaveLength(2));
  await settled();
  expect(ready.editorFailed).not.toHaveBeenCalled();

  // And so does a creation that succeeds.
  failure = null;
  vi.resetModules();
  const created = await load({ session: followed() });
  attach.mockImplementation(drupal);
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(creations).toHaveLength(3));
  await settled();
  expect(created.editorFailed).not.toHaveBeenCalled();
  expect(globalThis.CKEditor5.editorClassic.ClassicEditor.create).toBe(create);
});

test('a draft that stopped, before or while its configuration was read, gives the field Drupal’s editor', async () => {
  build();
  const stopped = await load({ session: followed() });
  stopped.stopped = true;
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(stopped.editorConfiguration).not.toHaveBeenCalled();

  vi.resetModules();
  const meanwhile = await load({
    session: fakeSession({
      editorConfiguration: vi.fn(async () => {
        meanwhile.stopped = true;
        return { collaborate: {}, initialData: '<p>Shared</p>' };
      }),
    }),
  });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(body().value).toBe('');
});

test('a field whose session fails to start takes Drupal’s editor, the failure said', async () => {
  build();
  const session = await load();
  const { sessionFor } = await import('@janalis/collaborate/draft/session.js');
  sessionFor.mockImplementationOnce(() => {
    throw new SyntaxError('Malformed configuration.');
  });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
  expect(errors).toHaveBeenCalledWith(new SyntaxError('Malformed configuration.'));

  // A plain attach that throws is said too.
  attach.mockImplementation(() => {
    throw new Error('No editor.');
  });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(errors).toHaveBeenCalledWith(new Error('No editor.')));
  expect(session.editorFailed).not.toHaveBeenCalled();
  // And after a session that failed to start.
  sessionFor.mockImplementationOnce(() => {
    throw new SyntaxError('Malformed again.');
  });
  errors.mockClear();
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(errors).toHaveBeenCalledTimes(2));
  expect(errors.mock.calls).toEqual([[new SyntaxError('Malformed again.')], [new Error('No editor.')]]);
});

test('a form without a session gives its field Drupal’s editor', async () => {
  build();
  await load({ session: null });
  globalThis.Drupal.editors.ckeditor5.attach(body(), format);
  await vi.waitFor(() => expect(attach).toHaveBeenCalledWith(body(), format));
});

test("Drupal's own failure is said, the field attached once and the form released", async () => {
  build();
  const session = await load({
    session: fakeSession({ editorConfiguration: vi.fn(async () => ({ collaborate: {} })) }),
  });
  attach.mockImplementation(() => {
    throw new Error('Malformed settings.');
  });

  globalThis.Drupal.editors.ckeditor5.attach(body(), format);

  await vi.waitFor(() => expect(errors).toHaveBeenCalledWith(new Error('Malformed settings.')));
  expect(attach).toHaveBeenCalledOnce();
  expect(session.editorFailed).toHaveBeenCalledOnce();
});

test("typing in an empty row makes it an item, what was typed kept, a file input's file left alone", async () => {
  const session = await load();
  await vi.waitFor(() => {
    document.querySelector('[name="notes[t0][value]"]').dispatchEvent(new Event('input', { bubbles: true }));
    expect(session.scanCollections).toHaveBeenCalled();
  });
  session.scanCollections.mockClear();
  const row = /** @type {HTMLElement} */ (document.querySelector('tr'));
  const text = /** @type {HTMLInputElement} */ (row.querySelector('[name="notes[t0][value]"]'));
  const box = /** @type {HTMLInputElement} */ (row.querySelector('[type="checkbox"]'));
  const select = /** @type {HTMLSelectElement} */ (row.querySelector('select'));
  // The draft's controls replace what the row shows as they bind.
  session.scanCollections.mockImplementation(() => {
    text.value = '';
    box.checked = false;
    for (const option of select.options) option.selected = false;
  });
  text.value = 'Typed';
  box.checked = true;
  select.options[1].selected = true;

  box.dispatchEvent(new Event('change', { bubbles: true }));

  expect(row.dataset.collaborateTouched).toBe('');
  expect([text.value, box.checked, select.options[0].selected, select.options[1].selected]).toEqual([
    'Typed',
    true,
    false,
    true,
  ]);
});

test('a row outside a pending one, or a session that stopped or never joined, makes no item', async () => {
  const session = await load();
  const scan = session.scanCollections;
  const input = document.querySelector('[name="notes[t0][value]"]');
  await vi.waitFor(() => {
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect(scan).toHaveBeenCalledOnce();
  });
  document.querySelector('[name="alone"]').dispatchEvent(new Event('input', { bubbles: true }));
  document.dispatchEvent(new Event('input'));
  session.stopped = true;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  session.stopped = false;
  session.initialized = false;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  session.initialized = true;
  session.scanCollections = undefined;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  expect(scan).toHaveBeenCalledOnce();
});

test("Drupal's AJAX rebuild binds the form's controls again, unless its draft stopped", async () => {
  const session = await load();
  const rows = vi.fn();
  document.addEventListener('collaborate:rows-changed', rows);
  const { attach: behavior } = globalThis.Drupal.behaviors.collaborate;

  behavior(document);
  behavior(document.querySelector('[name="alone"]'));
  behavior(document.querySelector('tr'));
  await vi.waitFor(() => expect(rows).toHaveBeenCalledOnce());
  expect(session.initializeControls).toHaveBeenCalledOnce();

  // A draft that stopped, then one not joined yet: answered in order, so once the live rebuild after them is
  // announced, theirs were not.
  const { sessionFor } = await import('@janalis/collaborate/draft/session.js');
  const stopped = fakeSession({ stopped: true });
  const unjoined = fakeSession({ initialized: false });
  sessionFor.mockReturnValueOnce(stopped).mockReturnValueOnce(unjoined);
  behavior(document.body);
  behavior(document.body);
  behavior(document.body);
  await vi.waitFor(() => expect(rows).toHaveBeenCalledTimes(2));
  expect(session.initializeControls).toHaveBeenCalledTimes(2);
  expect(stopped.initializeControls).not.toHaveBeenCalled();
  expect(unjoined.initializeControls).not.toHaveBeenCalled();
  document.removeEventListener('collaborate:rows-changed', rows);
});

test('a page without a shared form or without Drupal loads no draft', async () => {
  document.body.innerHTML = '<div data-collaborate-notifications></div>';
  for (const path of ['bell.js', 'page.js', 'topics.js'])
    vi.doMock(`@janalis/collaborate/notifications/${path}`, () => ({}));
  const session = await load();
  expect(globalThis.Drupal.editors.ckeditor5.attach).toBe(attach);
  globalThis.Drupal.behaviors.collaborate.attach(document.body);

  vi.resetModules();
  delete globalThis.Drupal;
  await load({ drupal: false });
  expect(globalThis.Drupal).toBeUndefined();
  expect(session.initializeControls).not.toHaveBeenCalled();
});
