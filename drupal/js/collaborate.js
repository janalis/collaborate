// The module's page script: Drupal's bridge profile, presence and cursors, refreshed listings and the bell on
// every page of a collaborator; on a shared entity form, its draft, with Drupal's CKEditor 5 editors created
// on the draft's content and the bundle's `Collaborate` plugin.
//
// Every module captures `window.fetch` when it is evaluated: none of Drupal's scripts wraps it.
import '@janalis/collaborate/bridges/drupal.js';
import '@janalis/collaborate/cursors/cursors.js';
import '@janalis/collaborate/updates.js';

const shared = () => document.querySelector('form[data-collaborate]');

if (document.querySelector('[data-collaborate-notifications]')) {
  import('@janalis/collaborate/notifications/bell.js');
  import('@janalis/collaborate/notifications/page.js');
  import('@janalis/collaborate/notifications/topics.js');
}

// Loaded with the first shared form: its CKEditor plugin needs Drupal's editor build on the page.
let drafts = null;
const loadDrafts = () => {
  drafts ??= Promise.all([
    import('@janalis/collaborate/draft/session.js'),
    import('@janalis/collaborate/ckeditor5/collaborate.js'),
  ]).then(([session, plugin]) => {
    const { Plugin } = globalThis.CKEditor5.dll('./src/core.js');
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
    globalThis.CKEditor5.collaborate = { Collaborate: plugin.default, CollaborateReady };
    return session;
  });
  return drafts;
};

// Drupal attaches its editors once the page is parsed, through `Drupal.editors.ckeditor5`: an editor of a shared
// field is created once the draft is joined, on its content, with the bundle's plugin.
const editors = globalThis.Drupal?.editors;
if (editors?.ckeditor5 && shared()) {
  const attach = editors.ckeditor5.attach;
  editors.ckeditor5.attach = function (element, format) {
    if (!element.form?.dataset.collaborate) return attach.call(this, element, format);
    loadDrafts()
      .then(async ({ sessionFor }) => {
        const session = sessionFor(element);
        const configuration = session ? await session.editorConfiguration(element) : {};
        if (!configuration.collaborate) return attach.call(this, element, format);
        if (typeof configuration.initialData === 'string') element.value = configuration.initialData;
        const settings = format.editorSettings;
        attach.call(this, element, {
          ...format,
          editorSettings: {
            ...settings,
            plugins: [...settings.plugins, 'collaborate.Collaborate', 'collaborate.CollaborateReady'],
            config: { ...settings.config, collaborate: configuration.collaborate },
          },
        });
      })
      .catch(() => drafts?.then(({ sessionFor }) => sessionFor(element)?.editorFailed()));
    return true;
  };
}

if (shared()) loadDrafts();

// Form API's AJAX ("Add another item", a widget's rebuild) replaces part of a shared form: its controls follow the
// draft again, and its collections are scanned for their entries.
if (globalThis.Drupal) {
  globalThis.Drupal.behaviors.collaborate = {
    attach(context) {
      if (context === document) return;
      const form = context.closest?.('form[data-collaborate]') ?? context.querySelector?.('form[data-collaborate]');
      if (!form) return;
      loadDrafts().then(({ sessionFor }) => {
        const field = Array.from(form.elements).find((control) => control.name && control.type !== 'hidden');
        const session = field ? sessionFor(field) : null;
        if (session?.initialized) session.initializeControls();
        document.dispatchEvent(new Event('collaborate:rows-changed'));
      });
    },
  };
}
