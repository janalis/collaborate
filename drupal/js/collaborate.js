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

// Failures a loader said already, which nothing says again: a page has one or two.
/** @type {Set<unknown>} */
const said = new Set();
/**
 * @param {string} message
 * @param {unknown} error
 */
const say = (message, error) => {
  said.add(error);
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
          const configuration = this.editor.config.get('collaborate');
          if (!configuration) return;
          this.editor.on('ready', () => configuration.getSession().editorReady(this.editor.sourceElement.name));
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
  // The latest attach of each field: one Drupal replaced meanwhile (a text format switched, an AJAX rebuild)
  // creates nothing.
  /** @type {WeakMap<HTMLTextAreaElement, object>} */
  const latest = new WeakMap();

  /**
   * Attaches a shared field once: with the draft's editor, or with Drupal's own when anything before fails, the form
   * then no longer following its draft.
   *
   * @param {HTMLTextAreaElement} element
   * @param {any} format Drupal's editor settings for the field's text format
   * @param {() => boolean} current whether this attach is still the field's latest
   */
  const attachShared = async (element, format, current) => {
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
      if (!said.has(error)) console.error(error);
      session?.editorFailed();
      configuration = {};
    }
    if (!current()) return;
    // Another field's editor may have failed meanwhile.
    if (!session || session.stopped || !configuration.collaborate) return attach.call(ckeditor5, element, format);
    const live = session;
    if (typeof configuration.initialData === 'string') element.value = configuration.initialData;
    const waiter = live.editorWaiters.get(configuration.collaborate.field);
    const settings = format.editorSettings;
    // Drupal creates the editor without handing its promise back, and only logs a failure: the draft waits for the
    // editor, its form inert, unless it is told. Drupal core calls `create` before its attach returns; a failure
    // once this editor said it was ready leaves the draft as it is.
    const classic = page.CKEditor5?.editorClassic?.ClassicEditor;
    const create = classic?.create;
    if (classic && create)
      classic.create = (...args) => {
        const created = create.apply(classic, args);
        created.catch(() => {
          if (!waiter?.ready) live.editorFailed();
        });
        return created;
      };
    try {
      attach.call(ckeditor5, element, {
        ...format,
        editorSettings: {
          ...settings,
          plugins: [...settings.plugins, 'collaborate.Collaborate', 'collaborate.CollaborateReady'],
          config: { ...settings.config, collaborate: configuration.collaborate },
        },
      });
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
    const token = {};
    latest.set(element, token);
    // Drupal's own failure, said as Drupal would.
    attachShared(element, format, () => latest.get(element) === token && element.isConnected).catch((error) =>
      console.error(error)
    );
    return true;
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
