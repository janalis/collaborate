// @ts-check
import { Model, ModelLivePosition } from 'ckeditor5';
import { bridge } from '../bridge.js';
import { OperationBuffer } from '../ckeditor5/operation-buffer.js';
import { textChange } from '../ckeditor5/text-change.js';

// The journal's label length (`config.labelLength`), in code points: a split surrogate pair is not valid JSON for PHP.
const LABEL_LENGTH = 255;

/** @typedef {import('./session.js').DraftSession} DraftSession */
/** @typedef {import('./session.js').DraftEvent} DraftEvent */

/**
 * A value field's control other than a radio or checkbox group: an input or a select, read through whichever
 * of these properties its tag has; `tomselect` is the TomSelect instance an autocomplete attaches.
 *
 * @typedef {HTMLInputElement & Pick<HTMLSelectElement, 'options' | 'selectedOptions' | 'add'> & { tomselect?: any }}
 *   SingleControl
 */

/** @typedef {{ id: string, kind: string, value: any }} PendingValue */

/**
 * @typedef {object} ValueAdapter
 * @property {() => object | null} next
 * @property {(event: DraftEvent & { version: number }) => void} receive a value event names the version it was made on
 * @property {(value: unknown, extra?: object) => void} send
 * @property {(value: any) => void} [adopt]
 */

/**
 * @param {DraftSession} session
 * @param {HTMLInputElement | HTMLTextAreaElement} control
 * @param {string} name
 * @param {import('./session.js').DraftField} state
 */
export function initializeText(session, control, name, state) {
  const model = new Model();
  const root = model.document.createRoot();
  model.schema.register('paragraph', { inheritAllFrom: '$block' });
  model.change((writer) => {
    const paragraph = writer.createElement('paragraph');
    writer.append(paragraph, root);
    if (state.initial) writer.insertText(state.initial, paragraph, 0);
  });
  const paragraph = /** @type {any} */ (root.getChild(0));
  const value = () => Array.from(paragraph.getChildren(), (node) => node.data).join('');
  const buffer = new OperationBuffer(model, () => session.schedule());
  const receive = buffer.receive.bind(buffer);
  buffer.receive = (event) => {
    const focused = document.activeElement === control;
    const start = control.selectionStart;
    const end = control.selectionEnd;
    const from =
      focused && start !== null ? ModelLivePosition.fromPosition(model.createPositionAt(paragraph, start)) : null;
    const to = focused && end !== null ? ModelLivePosition.fromPosition(model.createPositionAt(paragraph, end)) : null;
    try {
      receive(event);
      // Tagify dispatches change while it reloads the value; that is not a local edit.
      session.withApplying(() => {
        control.value = value();
        /** @type {any} */ (control).__tagify?.loadOriginalValues(control.value);
        if (from && to) control.setSelectionRange(from.offset, to.offset);
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
      });
    } finally {
      from?.detach();
      to?.detach();
    }
  };
  session.register(name, buffer);
  control.value = value();
  const input = () => {
    if (session.applying) return;
    const before = value();
    const after = control.value;
    if (before === after) return;
    const { prefix, end, inserted } = textChange(before, after);
    model.change((writer) => {
      if (end > prefix)
        writer.remove(
          writer.createRange(writer.createPositionAt(paragraph, prefix), writer.createPositionAt(paragraph, end))
        );
      if (inserted) writer.insertText(inserted, paragraph, prefix);
    });
  };
  buffer.adopt = (text) => {
    control.value = text;
    input();
  };
  control.addEventListener('input', input);
  control.addEventListener('change', input);
  // A slug widget (EasyAdmin's) writes its field, without an event, when its target is typed in or its lock
  // clicked: that write is this page's edit. A peer's edit of the target is applied here with an input
  // event too; the peer shares the slug its own widget computed, so this one must not rewrite the field.
  const { slug } = bridge();
  const owner = slug && control.matches(slug) ? control.form : null;
  const follow = () => {
    if (!session.applying) input();
    else if (control.value !== value()) control.value = value();
  };
  owner?.addEventListener('input', follow);
  owner?.addEventListener('click', follow);
  session.addRemover(() => {
    control.removeEventListener('input', input);
    control.removeEventListener('change', input);
    owner?.removeEventListener('input', follow);
    owner?.removeEventListener('click', follow);
    buffer.destroy();
    model.destroy();
  });
}

/**
 * A value field's model: one version, at most one unsent value, which replaces whatever a peer sent.
 * `show(event, pending)` renders a received value; `drops(event)` names a received value that cancels the unsent one.
 *
 * @param {DraftSession} session
 * @param {(event: DraftEvent, pending: PendingValue | null | undefined) => void} show
 * @param {(event: DraftEvent) => boolean} [drops]
 * @returns {ValueAdapter}
 */
export function valueAdapter(session, show, drops = () => false) {
  let version = 0;
  /** @type {PendingValue | null | undefined} */
  let pending;
  return {
    next: () => (pending ? { ...pending, version } : null),
    receive(event) {
      if (event.version < version) return;
      version = event.version + 1;
      if (pending?.id === event.id || drops(event)) pending = null;
      show(event, pending);
    },
    /**
     * @param {unknown} value
     * @param {object} [extra]
     */
    send(value, extra = {}) {
      pending = { id: crypto.randomUUID(), kind: 'value', value, ...extra };
      session.schedule();
    },
  };
}

/**
 * @param {DraftSession} session
 * @param {RadioNodeList | SingleControl} control
 * @param {string} name
 * @param {import('./session.js').DraftField} state
 */
export function initializeValue(session, control, name, state) {
  /** @type {string | undefined} */
  let observed;
  const controls = control instanceof RadioNodeList ? Array.from(control) : [control];
  const singleMultipleChoice =
    /** @type {SingleControl} */ (control).type === 'checkbox' && Array.isArray(state.initial);
  const read = () => {
    if (control instanceof RadioNodeList) {
      if (controls.every((element) => element.type === 'checkbox'))
        return controls.filter((element) => element.checked).map((element) => element.value);
      return control.value;
    }
    if (singleMultipleChoice) return control.checked ? [control.value] : [];
    if (control.type === 'checkbox') return control.checked;
    if (control.multiple) return Array.from(control.selectedOptions, (option) => option.value);
    return control.value;
  };
  // A choice names its ids: a peer's autocomplete renders only the options it already selected.
  const labels = () => {
    if (/** @type {SingleControl} */ (control).tagName !== 'SELECT') return {};
    const selected = Array.from(/** @type {SingleControl} */ (control).selectedOptions)
      .filter((option) => option.value)
      .map((option) => [
        option.value,
        Array.from(option.textContent)
          .slice(0, session.configuration?.config?.labelLength ?? LABEL_LENGTH)
          .join(''),
      ]);
    return selected.length ? { labels: Object.fromEntries(selected) } : {};
  };
  /**
   * @param {any} value
   * @param {Record<string, string>} [names]
   */
  const render = (value, names = {}) => {
    if (control instanceof RadioNodeList) {
      if (controls.every((element) => element.type === 'checkbox')) {
        for (const element of controls) element.checked = value.includes(element.value);
      } else control.value = value;
      observed = JSON.stringify(read());
      return;
    }
    if (control.tagName === 'SELECT') {
      const values = Array.isArray(value) ? value : [value];
      const { valueField = 'value', labelField = 'text' } = control.tomselect?.settings || {};
      for (const id of values) {
        if (id && !Array.from(control.options).some((option) => option.value === String(id))) {
          const label = names[id] ?? String(id);
          control.add(new Option(label, String(id)));
          control.tomselect?.addOption({ [valueField]: String(id), [labelField]: label });
        }
      }
    }
    if (control.tomselect) control.tomselect.setValue(value, true);
    else if (singleMultipleChoice) control.checked = value.includes(control.value);
    else if (control.type === 'checkbox') control.checked = Boolean(value);
    else if (control.multiple) {
      for (const option of control.options) option.selected = value.includes(option.value);
    } else control.value = value ?? '';
    observed = JSON.stringify(read());
  };
  // A received value fires no input or change: EasyAdmin's widgets would take it for the user's edit. Code
  // that derives one field from others listens for `collaborate:applied` instead.
  const adapter = valueAdapter(session, (event, pending) => {
    if (pending) return;
    render(event.value, event.labels);
    controls[0].dispatchEvent(new Event('collaborate:applied', { bubbles: true }));
  });
  render(state.initial);
  session.register(name, adapter);
  /** @param {unknown} value */
  adapter.adopt = (value) => {
    render(value);
    adapter.send(value, labels());
  };
  const changed = () => {
    if (session.applying) return;
    const value = read();
    const identity = JSON.stringify(value);
    if (identity === observed) return;
    observed = identity;
    adapter.send(value, labels());
  };
  for (const element of controls) {
    element.addEventListener('input', changed);
    element.addEventListener('change', changed);
    session.addRemover(() => {
      element.removeEventListener('input', changed);
      element.removeEventListener('change', changed);
    });
  }
}
