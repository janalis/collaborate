// The module's page script: Drupal's bridge profile, presence and cursors, refreshed listings and the bell on
// every page of a collaborator; on a shared entity form, its draft, with Drupal's CKEditor 5 editors created
// on the draft's content and the bundle's `Collaborate` plugin.
//
// Every module captures `window.fetch` when it is evaluated: none of Drupal's scripts wraps it.
import '@janalis/collaborate/bridges/drupal.js';
import '@janalis/collaborate/cursors/cursors.js';
import '@janalis/collaborate/updates.js';

const page = /** @type {import('../../typings/drupal.js').DrupalGlobals} */ (globalThis);

const shared = () => document.querySelector('form[data-collaborate]');

if (document.querySelector('[data-collaborate-notifications]')) {
  import('@janalis/collaborate/notifications/bell.js');
  import('@janalis/collaborate/notifications/page.js');
  import('@janalis/collaborate/notifications/topics.js');
}

// What a loader said already, which nothing says again: each failure once, a field's retry failing alike said
// with the first.
/** @type {WeakSet<object>} */
const said = new WeakSet();
/** @type {Set<string>} */
const failures = new Set();
/**
 * @param {string} message
 * @param {unknown} error
 */
const say = (message, error) => {
  if (error instanceof Error) said.add(error);
  const failure = `${message} ${error instanceof Error ? error.message : ''}`;
  if (failures.has(failure)) return;
  failures.add(failure);
  console.error(message, error);
};

// The draft's sessions, loaded with the first shared form: following one needs nothing more.
/** @type {Promise<typeof import('@janalis/collaborate/draft/session.js')> | null} */
let drafts = null;
const loadDrafts = () => {
  if (!drafts) {
    drafts = import('@janalis/collaborate/draft/session.js');
    // Said once: every caller then goes on without the drafts. Loaded, they serve the typed rows of every shared form.
    drafts.then(
      (module) => {
        sessions = module;
        listen();
      },
      (error) => say('Collaborate: the shared draft could not load.', error)
    );
  }
  return drafts;
};

// The bundle's CKEditor plugin, registered once on Drupal's editor build, which it needs on the page. A failure is
// not kept: Drupal's build may come later, with a dialog's libraries.
/** @type {Promise<void> | null} */
let plugins = null;
const loadPlugins = () => {
  if (!plugins) {
    plugins = import('@janalis/collaborate/ckeditor5/collaborate.js').then((plugin) => {
      const ckeditor = page.CKEditor5;
      if (!ckeditor?.dll) throw new Error("Collaborate: Drupal's CKEditor 5 build is not on the page.");
      // Drupal finds an editor's plugins as `CKEditor5.<package>.<plugin>`.
      ckeditor.collaborate = { Collaborate: plugin.default };
    });
    plugins.catch((error) => {
      plugins = null;
      say('Collaborate: the shared editors could not load.', error);
    });
  }
  return plugins;
};

/**
 * Stops a draft that follows a field Drupal hides behind another editor than CKEditor 5: what is typed there would
 * not be shared, and a peer's edits would be lost. (CKEditor 5 on a field followed as text is the session's to stop.)
 *
 * @param {import('@janalis/collaborate/draft/session.js').DraftSession} session
 * @param {HTMLTextAreaElement} element
 */
const stopFor = (session, element) =>
  session.follows(element).then(
    (followed) => {
      if (followed) session.editorFailed();
    },
    (error) => console.error(error)
  );

// Drupal attaches its editors once the page is parsed, through `Drupal.editors.ckeditor5`: an editor of a shared
// field is created once the draft is joined, on its content, with the bundle's plugin, which tells the draft once
// it is ready. The draft's handle of each editor settles that editor's wait only: one Drupal replaced, or whose
// field left, settles nothing of the next.
// Drupal's editors and its dispatcher, watched once each, whenever they come: on the page at first, or later with an
// AJAX response's libraries (the module's behavior runs before the behaviors those bring).
// One the module cannot hook (a frozen object) is Drupal's alone, and the others are hooked still.
/** @type {WeakSet<object>} */
const hooked = new WeakSet();
const watchCkeditor5 = () => {
  const ckeditor5 = page.Drupal?.editors?.ckeditor5;
  // On every page: a shared form may come later (a dialog's), and each field's attach asks whether its form is shared.
  if (!ckeditor5 || hooked.has(ckeditor5)) return;
  const { attach, detach } = ckeditor5;
  /** @typedef {import('@janalis/collaborate/draft/session.js').EditorHandle} EditorHandle */
  /**
   * @typedef {{ handle?: EditorHandle, given?: boolean, created?: boolean }} Attach an attach, the draft's handle of
   *   its editor, and whether Drupal began creating it
   */
  // Each field's latest attach, until Drupal detaches it: one Drupal replaced meanwhile (a text format switched)
  // creates nothing, and one detached gives its editor's wait back.
  /** @type {WeakMap<HTMLTextAreaElement, Attach>} */
  const attaches = new WeakMap();

  // The creation the attach under way awaits, and Drupal's editor class watched for it, once: Drupal core creates
  // the editor before its attach returns, and only logs a failure.
  /** @type {{ element: Element, heard: (created: Promise<unknown>) => void } | null} */
  let awaited = null;
  /** @type {WeakSet<Function>} */
  const watchers = new WeakSet();
  const watch = () => {
    const classic = page.CKEditor5?.editorClassic?.ClassicEditor;
    if (!classic || watchers.has(classic.create)) return;
    const create = classic.create;
    // A subclass inheriting `create` creates its own kind; a call without a class makes Drupal's class of now.
    /** @this {unknown} */
    const watcher = function (/** @type {unknown[]} */ ...args) {
      const created = create.apply(this ?? page.CKEditor5?.editorClassic?.ClassicEditor ?? classic, args);
      const pending = awaited;
      if (pending && pending.element === args[0]) {
        awaited = null;
        pending.heard(created);
      }
      return created;
    };
    watchers.add(watcher);
    classic.create = watcher;
  };

  /**
   * Gives an attach's editor wait back, once, after Drupal is done (it detaches a field before it puts its rebuild
   * in its place): the session lets it go if the field left the form, and otherwise waits a while for the field's
   * next editor. A field whose format now has no CKEditor 5 cannot follow the draft: the form falls back to ordinary
   * editing.
   *
   * @param {HTMLTextAreaElement} element
   * @param {Attach} ended
   */
  const giveBack = (element, ended) => {
    const handle = ended.handle;
    if (!handle || ended.given) return;
    ended.given = true;
    setTimeout(() => {
      // The field as the form has it now: this one, or its rebuild.
      const field = element.isConnected ? element : handle.getSession().form?.elements.namedItem(element.name);
      const formats = page.drupalSettings?.editor?.formats;
      const active = field instanceof HTMLTextAreaElement && field.getAttribute('data-editor-active-text-format');
      if (formats && active && formats[active]?.editor !== 'ckeditor5') handle.failed();
      else handle.release();
    });
  };

  /**
   * Attaches a shared field once: with the draft's editor, or with Drupal's own when anything before fails, the form
   * then no longer following its draft.
   *
   * @param {HTMLTextAreaElement} element
   * @param {any} format Drupal's editor settings for the field's text format
   * @param {Attach} mine this attach, its field's latest until Drupal attaches or detaches it again
   */
  const attachShared = async (element, format, mine) => {
    const current = () => attaches.get(element) === mine && element.isConnected;
    /** @type {import('@janalis/collaborate/draft/session.js').DraftSession | null | undefined} */
    let session = null;
    /** @type {{ collaborate?: EditorHandle, initialData?: string }} */
    let configuration = {};
    try {
      session = (await loadDrafts()).sessionFor(element);
      if (session && !session.stopped) {
        configuration = await session.editorConfiguration(element);
        mine.handle = configuration.collaborate;
        // A field the draft does not follow needs nothing of the bundle's editors.
        if (configuration.collaborate) await loadPlugins();
      }
    } catch (error) {
      if (!said.has(/** @type {object} */ (error))) console.error(error);
      // A field replaced or gone meanwhile stops nothing.
      if (current()) session?.editorFailed();
      configuration = {};
    }
    // Replaced, detached or gone meanwhile: nothing waits for an editor this attach never creates.
    if (!current()) return giveBack(element, mine);
    const handle = configuration.collaborate;
    // Another field's editor may have failed meanwhile.
    if (!session || session.stopped || !handle) return attach.call(ckeditor5, element, format);
    if (!Array.isArray(format?.editorSettings?.plugins)) {
      // Settings the draft's editor cannot extend: Drupal's own editor.
      console.error(new TypeError("Collaborate: the text format's editor settings have no plugins to extend."));
      session.editorFailed();
      return attach.call(ckeditor5, element, format);
    }
    const settings = {
      ...format,
      editorSettings: {
        ...format.editorSettings,
        plugins: [...format.editorSettings.plugins, 'collaborate.Collaborate'],
        config: {
          ...format.editorSettings.config,
          // An editor ready once its attach was replaced, or its field gone, follows nothing.
          collaborate: {
            ...handle,
            current: () => {
              if (current()) return handle.current();
              giveBack(element, mine);
              return false;
            },
          },
        },
      },
    };
    if (typeof configuration.initialData === 'string') element.value = configuration.initialData;
    // Drupal creates the editor without handing its promise back, and only logs a failure: the draft waits for the
    // editor, its form inert, unless it is told. An editor Drupal does not keep (it failed, even once it followed the
    // draft) stops it; a field replaced or gone meanwhile only gives its editor's wait back.
    watch();
    awaited = {
      element,
      heard: (created) => {
        mine.created = true;
        Promise.resolve(created).catch(() => (current() ? handle.failed() : giveBack(element, mine)));
      },
    };
    try {
      attach.call(ckeditor5, element, settings);
    } catch (error) {
      // The editor the draft waits for never comes, unless Drupal began creating it: the form is released, the field
      // left as Drupal left it.
      if (!mine.created) handle.failed();
      throw error;
    } finally {
      awaited = null;
    }
  };

  /**
   * @param {HTMLTextAreaElement} element
   * @param {any} format Drupal's editor settings for the field's text format
   */
  ckeditor5.attach = (element, format) => {
    if (!element.form?.dataset.collaborate) return attach.call(ckeditor5, element, format);
    const mine = {};
    attaches.set(element, mine);
    // Drupal's own failure, said as Drupal would.
    attachShared(element, format, mine).catch((error) => console.error(error));
    return true;
  };
  hooked.add(ckeditor5);

  /**
   * @param {HTMLTextAreaElement} element
   * @param {any} format
   * @param {string} [trigger] `serialize` when Drupal only reads the editor back into its field
   */
  ckeditor5.detach = (element, format, trigger) => {
    if (trigger !== 'serialize') {
      const ended = attaches.get(element);
      attaches.delete(element);
      if (ended) giveBack(element, ended);
    }
    return detach.call(ckeditor5, element, format, trigger);
  };
  if (shared()) loadPlugins().catch(() => {});
};

// Drupal's other editors (CodeMirror, a contrib one) hide a field behind one the draft cannot follow: what is typed
// there would not be shared, and a peer's edits would be lost, so the draft stops for a field it follows.
/** @param {HTMLTextAreaElement} element a field another editor than CKEditor 5 takes */
const otherEditor = (element) => {
  if (!element.form?.dataset.collaborate) return;
  loadDrafts()
    .then(({ sessionFor }) => {
      const session = sessionFor(element);
      if (session && !session.stopped) stopFor(session, element);
    })
    // The drafts' own failure is said once already.
    .catch((error) => {
      if (!said.has(/** @type {object} */ (error))) console.error(error);
    });
};
const watchOthers = () => {
  const drupal = page.Drupal;
  if (!drupal) return;
  const editorAttach = drupal.editorAttach;
  if (typeof editorAttach === 'function' && !hooked.has(editorAttach)) {
    // Drupal attaches every editor through it: one whose library came later (a dialog's) is watched before it is
    // attached, Drupal's editor behavior running before the module's.
    /**
     * @param {HTMLTextAreaElement} element
     * @param {any} format
     */
    const watcher = (element, format) => {
      const editor = /** @type {Record<string, object | undefined>} */ (drupal.editors ?? {})[String(format?.editor)];
      if (!editor || !hooked.has(editor)) watchEditors();
      return editorAttach.call(drupal, element, format);
    };
    hooked.add(watcher);
    drupal.editorAttach = watcher;
  }
  // Each editor tells the draft, through the dispatcher or called directly.
  for (const [name, editor] of Object.entries(drupal.editors ?? {})) {
    if (name === 'ckeditor5' || typeof editor?.attach !== 'function' || hooked.has(editor)) continue;
    const attachOther = editor.attach;
    try {
      /**
       * @param {HTMLTextAreaElement} element
       * @param {any} format
       */
      editor.attach = (element, format) => {
        otherEditor(element);
        return attachOther.call(editor, element, format);
      };
      hooked.add(editor);
    } catch (error) {
      console.error(error);
    }
  }
};
const watchEditors = () => {
  for (const watch of [watchCkeditor5, watchOthers]) {
    try {
      watch();
    } catch (error) {
      console.error(error);
    }
  }
};
watchEditors();

// Typing in a multi-value field's empty row makes it an item: the row takes a key of its own and follows the
// draft from then on. The draft's controls replace what the row shows as they bind: what was typed is put back
// before the event reaches its control, whose binding sends it.
/** @type {typeof import('@janalis/collaborate/draft/session.js') | null} */
let sessions = null;
/** @param {Event} event */
const touch = (event) => {
  const row = /** @type {HTMLElement | null} */ (
    event.target instanceof Element ? event.target.closest('[data-collaborate-pending]') : null
  );
  const form = /** @type {HTMLFormElement | null | undefined} */ (row?.closest('form[data-collaborate]'));
  const session = form && sessions ? sessions.sessionFor({ form }) : null;
  if (!row || !session?.initialized || session.stopped || !session.scanCollections) return;
  // Each control read by what its type has: a box its check, a multiple select its options, any other its value.
  // A file input keeps its file: a script may only empty it.
  const restores = Array.from(
    /** @type {NodeListOf<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>} */ (
      row.querySelectorAll('input:not([type="file"]), select, textarea')
    ),
    (control) => {
      if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) {
        const checked = control.checked;
        return () => {
          control.checked = checked;
        };
      }
      if (control instanceof HTMLSelectElement && control.multiple) {
        const chosen = Array.from(control.selectedOptions, (option) => option.value);
        return () => {
          for (const option of control.options) option.selected = chosen.includes(option.value);
        };
      }
      const value = control.value;
      return () => {
        control.value = value;
      };
    }
  );
  row.dataset.collaborateTouched = '';
  session.scanCollections();
  for (const restore of restores) restore();
};
// Listened to once the drafts are loaded (once) for a shared form, on the page at first or come later (a dialog's).
const listen = () => {
  document.addEventListener('input', touch, true);
  document.addEventListener('change', touch, true);
};
if (shared()) loadDrafts().catch(() => {});

// Form API's AJAX ("Add another item", a widget's rebuild) replaces part of a shared form: its controls follow the
// draft again, and its collections are scanned for their entries.
if (page.Drupal) {
  page.Drupal.behaviors.collaborate = {
    /** @param {Document | Element} context the part of the page Drupal attached behaviors to */
    attach(context) {
      watchEditors();
      if (!(context instanceof Element)) return;
      const form = /** @type {HTMLFormElement | null} */ (
        context.closest('form[data-collaborate]') ?? context.querySelector('form[data-collaborate]')
      );
      if (!form) return;
      // Its editors need the bundle's plugin, a form come in a dialog's as much as one on the page at first.
      if (page.Drupal?.editors?.ckeditor5) loadPlugins().catch(() => {});
      loadDrafts().then(
        ({ sessionFor }) => {
          const session = sessionFor({ form });
          if (!session?.initialized || session.stopped) return;
          session.initializeControls();
          document.dispatchEvent(new Event('collaborate:rows-changed'));
        },
        () => {}
      );
    },
  };
}
