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
    // Said once: every caller then goes on without the drafts.
    drafts.catch((error) => say('Collaborate: the shared draft could not load.', error));
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
      const { Plugin } = ckeditor.dll('./src/core.js');
      // Tells the session its editor is following the draft: the host's part of the contract.
      class CollaborateReady extends Plugin {
        static get pluginName() {
          return 'CollaborateReady';
        }

        init() {
          // The attach that created this editor tells the session, unless Drupal replaced it meanwhile.
          const heard = this.editor.config.get('collaborateReady');
          if (heard) this.editor.on('ready', () => heard());
        }
      }
      // Drupal finds an editor's plugins as `CKEditor5.<package>.<plugin>`.
      ckeditor.collaborate = { Collaborate: plugin.default, CollaborateReady };
    });
    plugins.catch((error) => {
      plugins = null;
      say('Collaborate: the shared editors could not load.', error);
    });
  }
  return plugins;
};

// Drupal attaches its editors once the page is parsed, through `Drupal.editors.ckeditor5`: an editor of a shared
// field is created once the draft is joined, on its content, with the bundle's plugin.
const editors = page.Drupal?.editors;
if (editors?.ckeditor5 && shared()) {
  const ckeditor5 = editors.ckeditor5;
  const attach = ckeditor5.attach;
  const detach = ckeditor5.detach;
  // The latest attach of each field of a form, until Drupal detaches it: one Drupal replaced meanwhile (a text
  // format switched, an AJAX rebuild) creates nothing, and an editor it created says nothing more to the draft.
  /** @type {WeakMap<HTMLFormElement, Map<string, object>>} */
  const latest = new WeakMap();
  /** @param {HTMLFormElement} form */
  const owners = (form) => {
    if (!latest.has(form)) latest.set(form, new Map());
    return /** @type {Map<string, object>} */ (latest.get(form));
  };

  /**
   * Attaches a shared field once: with the draft's editor, or with Drupal's own when anything before fails, the form
   * then no longer following its draft.
   *
   * @param {HTMLTextAreaElement} element
   * @param {any} format Drupal's editor settings for the field's text format
   * @param {HTMLFormElement} form
   * @param {object} mine this attach's token, its field's latest until Drupal attaches or detaches the field again
   */
  const attachShared = async (element, format, form, mine) => {
    const current = () => owners(form).get(element.name) === mine && element.isConnected;
    // Nothing attached the field after this attach, and its editor never comes: no editor is waited for. Said once.
    let given = false;
    const abandoned = () => {
      const owner = owners(form).get(element.name);
      if (given || (owner !== mine && owner !== undefined)) return false;
      owners(form).delete(element.name);
      given = true;
      return true;
    };
    /** @type {import('@janalis/collaborate/draft/session.js').DraftSession | null | undefined} */
    let session = null;
    /** @type {{ collaborate?: { field: string }, initialData?: string }} */
    let configuration = {};
    try {
      session = (await loadDrafts()).sessionFor(element);
      if (session && !session.stopped) {
        configuration = await session.editorConfiguration(element);
        // A field the draft does not follow needs nothing of the bundle's editors.
        if (configuration.collaborate) await loadPlugins();
      }
    } catch (error) {
      if (!said.has(/** @type {object} */ (error))) console.error(error);
      session?.editorFailed();
      configuration = {};
    }
    if (!current()) {
      // A field gone, or Drupal's editor taken off, with no attach after it: nothing waits for its editor. A newer
      // attach waits for its own.
      if (abandoned() && configuration.collaborate) session?.detachField(configuration.collaborate.field);
      return;
    }
    // Another field's editor may have failed meanwhile.
    if (!session || session.stopped || !configuration.collaborate) return attach.call(ckeditor5, element, format);
    const live = session;
    const field = configuration.collaborate.field;
    /** @param {() => void} settle what the draft hears of this attach's editor while the attach is current */
    const tell = (settle) => {
      if (current()) settle();
      else if (abandoned()) live.detachField(field);
    };
    if (!Array.isArray(format?.editorSettings?.plugins)) {
      // Settings the draft's editor cannot extend: Drupal's own editor.
      console.error(new TypeError("Collaborate: the text format's editor settings have no plugins to extend."));
      live.editorFailed();
      return attach.call(ckeditor5, element, format);
    }
    // Whether the editor this attach creates said it was ready: a failure after that leaves the draft as it is.
    let ready = false;
    const settings = {
      ...format,
      editorSettings: {
        ...format.editorSettings,
        plugins: [...format.editorSettings.plugins, 'collaborate.Collaborate', 'collaborate.CollaborateReady'],
        config: {
          ...format.editorSettings.config,
          collaborate: configuration.collaborate,
          collaborateReady: () => {
            ready = true;
            tell(() => live.editorReady(element.name));
          },
        },
      },
    };
    if (typeof configuration.initialData === 'string') element.value = configuration.initialData;
    // Drupal creates the editor without handing its promise back, and only logs a failure: the draft waits for the
    // editor, its form inert, unless it is told. Drupal core calls `create` before its attach returns.
    const classic = page.CKEditor5?.editorClassic?.ClassicEditor;
    const create = classic?.create;
    if (classic && create)
      classic.create = (...args) => {
        const created = create.apply(classic, args);
        created.catch(() => {
          if (!ready) tell(() => live.editorFailed());
        });
        return created;
      };
    try {
      attach.call(ckeditor5, element, settings);
    } catch (error) {
      // The editor the draft waits for never comes: the form is released, the field left as Drupal left it.
      live.editorFailed();
      throw error;
    } finally {
      if (classic && create) classic.create = create;
    }
  };

  /**
   * @param {HTMLTextAreaElement} element
   * @param {any} format Drupal's editor settings for the field's text format
   */
  ckeditor5.attach = (element, format) => {
    if (!element.form?.dataset.collaborate) return attach.call(ckeditor5, element, format);
    const mine = {};
    owners(element.form).set(element.name, mine);
    // Drupal's own failure, said as Drupal would.
    attachShared(element, format, element.form, mine).catch((error) => console.error(error));
    return true;
  };

  /**
   * @param {HTMLTextAreaElement} element
   * @param {any} format
   * @param {string} [trigger]
   */
  ckeditor5.detach = (element, format, trigger) => {
    // An attach still on its way creates no editor once Drupal took the field's off.
    if (trigger !== 'serialize' && element.form) owners(element.form).delete(element.name);
    return detach.call(ckeditor5, element, format, trigger);
  };
  loadPlugins().catch(() => {});
}

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
if (shared()) {
  loadDrafts().then(
    (session) => {
      sessions = session;
    },
    () => {}
  );
  document.addEventListener('input', touch, true);
  document.addEventListener('change', touch, true);
}

// Form API's AJAX ("Add another item", a widget's rebuild) replaces part of a shared form: its controls follow the
// draft again, and its collections are scanned for their entries.
if (page.Drupal) {
  page.Drupal.behaviors.collaborate = {
    /** @param {Document | Element} context the part of the page Drupal attached behaviors to */
    attach(context) {
      if (!(context instanceof Element)) return;
      const form = /** @type {HTMLFormElement | null} */ (
        context.closest('form[data-collaborate]') ?? context.querySelector('form[data-collaborate]')
      );
      if (!form) return;
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
