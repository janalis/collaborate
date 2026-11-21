// @ts-check
import { Plugin } from 'ckeditor5';
import { OperationBuffer } from './operation-buffer.js';
import { applySource, hasPendingUpload } from './source.js';
import { rebaseText, textChange } from './text-change.js';

/** CKEditor model-operation collaboration over an application-supplied transport. */
export default class Collaborate extends Plugin {
  static get pluginName() {
    return 'Collaborate';
  }

  init() {
    /**
     * What `session.editorConfiguration(field)` hands the editor: the session's factory, the shared field, and what
     * tells the session this editor is ready.
     */
    const configuration = /** @type {{ getSession: () => any, field: string, ready?: () => boolean } | undefined} */ (
      this.editor.config.get('collaborate')
    );
    if (!configuration) return;
    // CKEditor deep-clones configuration objects, including Map state. A factory
    // keeps every editor attached to the form's actual transport instance.
    const session = configuration.getSession();
    this.session = session;
    this.field = configuration.field;
    this.editor.on('ready', () => {
      // An editor the draft no longer waits for (a later one replaced it, its field left) follows nothing.
      if (configuration.ready?.() === false) return;
      this.buffer = new OperationBuffer(this.editor.model, () => session.schedule());
      const receive = this.buffer.receive.bind(this.buffer);
      this.buffer.receive = (event) => {
        // Unapplied source typing would only be compared as text below, and lost to an overlapping edit:
        // applied first, it is merged with the remote operations.
        session.settleSource?.(configuration.field);
        const field = /** @type {HTMLTextAreaElement | undefined} */ (/** @type {any} */ (this.editor).sourceElement);
        const focused = field && document.activeElement === field;
        const before = focused ? field.value : '';
        const from = focused ? field.selectionStart : 0;
        const to = focused ? field.selectionEnd : 0;
        const data = focused ? this.editor.getData() : '';
        // The mirrored source is not a local source edit.
        session.withApplying(() => receive(event));
        // Typed source the processor would re-serialize (`**bo` → `\*\*bo`) stays as typed.
        if (focused) field.value = rebaseText(before, data, this.editor.getData());
        if (focused && before !== field.value) {
          const change = textChange(before, field.value);
          /** @param {number} offset */
          const map = (offset) =>
            offset <= change.prefix
              ? offset
              : offset >= change.end
                ? offset + field.value.length - before.length
                : change.prefix + change.inserted.length;
          field.setSelectionRange(map(from), map(to));
        }
      };
      this.buffer.adopt = (source) => this.applySource(source);
      this.unregister = session.register(configuration.field, this.buffer);
    });
  }

  attachSource() {
    this.removeMode = this.session?.registerMode?.(
      this.field,
      this.editor,
      /** @param {string} source */ (source) => this.applySource(source)
    );
  }

  /** @param {string} source */
  applySource(source) {
    if (hasPendingUpload(/** @type {import('ckeditor5').ModelElement} */ (this.editor.model.document.getRoot())))
      return;
    this.sourceEditing = true;
    try {
      applySource(this.editor, source);
    } finally {
      this.sourceEditing = false;
    }
  }

  destroy() {
    this.unregister?.();
    this.buffer?.destroy();
    this.removeMode?.();
    return super.destroy();
  }
}
