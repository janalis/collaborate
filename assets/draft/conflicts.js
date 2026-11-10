import { diff } from 'ckeditor5';
import { entrySelector, rowOf } from '../markup.js';

// Past this many words a side is shown whole: the diff grows with the product of both lengths.
const MAX_DIFF_TOKENS = 2000;
const BLOCKS = 'p, h1, h2, h3, h4, h5, h6, li, blockquote, pre, div, tr, br';

const control = (session, name) => {
  const found = session.form.elements.namedItem(session.formName(name));
  return found instanceof RadioNodeList ? found : found || null;
};

const first = (found) => (found instanceof RadioNodeList ? found[0] : found);

/** The rich editor a source textarea feeds: its model, not the textarea, holds the current text. */
const editorOf = (element) =>
  Array.from(document.querySelectorAll('.ck-editor__editable')).find(
    (editable) => editable.ckeditorInstance?.sourceElement === element
  )?.ckeditorInstance;

/** Rich text as read: one line per block, no markup. Markdown source has none and stays as it is. */
const richText = (html) => {
  if (typeof html !== 'string' || !/<[a-z]/i.test(html)) return String(html ?? '');
  const body = new DOMParser().parseFromString(html, 'text/html').body;
  for (const block of body.querySelectorAll(BLOCKS)) block.after('\n');
  return body.textContent.replace(/\n{3,}/g, '\n\n').trim();
};

/**
 * An entry's or a collection's own state (`path[@key]`, `path[__collection__]`) has no control: it is named
 * after its collection, and an entry after the first text its row shows.
 */
function collectionLabel(session, name) {
  const type = session.state?.fields?.[name]?.type;
  const match = /^(.*)\[(?:@([^\]]+)|__collection__)\]$/.exec(session.formName(name));
  if (!match || !['member', 'collection'].includes(type)) return null;
  const container = Array.from(session.form.querySelectorAll('[data-collaborate-collection]')).find(
    (element) => element.dataset.collaborateCollection === match[1]
  );
  const parent = container?.querySelector('label, legend')?.textContent.trim();
  if (!parent) return null;
  const row = match[2]
    ? Array.from(container.querySelectorAll(entrySelector())).find((node) => node.dataset.collaborateItem === match[2])
    : null;
  const text = Array.from(row?.querySelectorAll('input:not([type]), input[type="text"]') ?? [])
    .map((input) => input.value.trim())
    .find(Boolean);
  return text ? `${parent} › ${text}` : parent;
}

/** The field's label; a collection entry's names its collection first. */
export function fieldLabel(session, name) {
  const entry = collectionLabel(session, name);
  if (entry) return entry;
  const element = first(control(session, name));
  const group = rowOf(element);
  const own =
    element?.labels?.[0]?.textContent.trim() ||
    element?.getAttribute('aria-label')?.trim() ||
    group?.querySelector('label, legend')?.textContent.trim() ||
    '';
  const collection = element?.closest('[data-collaborate-collection]');
  const parent = collection?.querySelector('label, legend')?.textContent.trim();
  if (own && parent && parent !== own) return `${parent} › ${own}`;
  return own || parent || session.formName(name);
}

/** What the draft holds for a field now, read from its control. */
export function draftValue(session, name) {
  const type = session.state?.fields?.[name]?.type;
  if (type === 'member' || type === 'collection') return session.state.fields[name].value;
  const found = control(session, name);
  if (!found) return '';
  if (found instanceof RadioNodeList) return found.value;
  if (type === 'rich') return editorOf(found)?.getData() ?? found.value;
  if (found instanceof HTMLSelectElement) {
    const values = Array.from(found.selectedOptions, (option) => option.value);
    return found.multiple ? values : (values[0] ?? '');
  }
  if (found.type === 'checkbox') return found.checked;
  return found.value;
}

/** A value as the editor reads it: option labels for ids, Yes/No, text for markup, words for structure. */
export function describe(session, name, value) {
  const messages = session.configuration.messages;
  const type = session.state?.fields?.[name]?.type;
  // An entry's order is its collection's conflict, which names the entries in order.
  if (type === 'member') return value?.alive ? messages.entryPresent : messages.entryRemoved;
  if (type === 'collection') return messages.orderChanged;
  if (type === 'rich') return richText(value);
  const found = control(session, name);
  const element = first(found);
  if (found instanceof RadioNodeList)
    return (
      Array.from(found)
        .find((radio) => radio.value === String(value))
        ?.labels?.[0]?.textContent.trim() ?? ''
    );
  if (element instanceof HTMLSelectElement) {
    // A peer's pick may not be among this page's options: the event that chose it carries its label.
    const labels = (session.history?.get(name) || []).at(-1)?.labels || {};
    const label = (id) =>
      Array.from(element.options)
        .find((option) => option.value === String(id))
        ?.text.trim() ||
      labels[id] ||
      `#${id}`;
    return (Array.isArray(value) ? value : [value])
      .filter((id) => id !== '' && id !== null && id !== undefined)
      .map(label)
      .join(', ');
  }
  if (element?.type === 'checkbox') return value && value !== '0' && value !== 'false' ? messages.yes : messages.no;
  if (typeof value === 'string') return value;
  return JSON.stringify(value, null, 2);
}

/** Fills both sides with their words, marking what the draft removed and added. Text only, never markup. */
export function renderDiff(savedElement, draftElement, saved, draft) {
  const before = saved.split(/(\s+)/);
  const after = draft.split(/(\s+)/);
  savedElement.replaceChildren();
  draftElement.replaceChildren();
  if (saved === draft || before.length + after.length > MAX_DIFF_TOKENS) {
    savedElement.textContent = saved;
    draftElement.textContent = draft;
    return;
  }
  // Consecutive changed words, and the spaces between them, read as one mark.
  const mark = (element, tag, text) => {
    if (element.lastChild?.localName === tag) {
      element.lastChild.textContent += text;
      return;
    }
    const node = document.createElement(tag);
    node.textContent = text;
    element.append(node);
  };
  let i = 0;
  let j = 0;
  for (const change of diff(before, after)) {
    if (change === 'equal') {
      savedElement.append(before[i++]);
      draftElement.append(after[j++]);
    } else if (change === 'delete') mark(savedElement, 'del', before[i++]);
    else mark(draftElement, 'ins', after[j++]);
  }
}

export function renderConflicts(session, conflicts) {
  session.conflicts = conflicts;
  const identity = JSON.stringify(conflicts);
  const messages = session.configuration.messages;
  const refresh = (name, savedElement, draftElement) =>
    renderDiff(
      savedElement,
      draftElement,
      describe(session, name, conflicts[name]),
      describe(session, name, draftValue(session, name))
    );
  if (identity === session.conflictIdentity) {
    for (const element of session.conflictPanel.querySelectorAll('[data-draft-field]'))
      refresh(element.dataset.draftField, element.previousElementSibling.previousElementSibling, element);
    if (Object.keys(conflicts).length) session.setStatus('conflict');
    return;
  }
  session.conflictIdentity = identity;
  session.conflictPanel.replaceChildren();
  session.conflictPanel.hidden = Object.keys(conflicts).length === 0;
  if (session.conflictPanel.hidden) return;
  session.setStatus('conflict');
  const heading = document.createElement('h2');
  heading.className = 'collaborate-conflicts__title fs-6';
  heading.tabIndex = -1;
  heading.id = `collaborate-conflicts-${session.form.id || session.form.name || 'form'}`;
  heading.textContent = messages.conflictsTitle;
  session.conflictPanel.setAttribute('aria-labelledby', heading.id);
  session.conflictPanel.append(heading);
  for (const name of Object.keys(conflicts)) {
    const details = document.createElement('details');
    details.className = 'collaborate-conflicts__field';
    const summary = document.createElement('summary');
    summary.textContent = fieldLabel(session, name);
    details.append(summary);
    const saved = document.createElement('p');
    saved.className = 'collaborate-conflicts__side';
    saved.textContent = messages.savedVersion;
    const savedValue = document.createElement('pre');
    savedValue.className = 'collaborate-conflicts__value';
    savedValue.dataset.savedField = name;
    const draft = document.createElement('p');
    draft.className = 'collaborate-conflicts__side';
    draft.textContent = messages.draftVersion;
    const draftValueElement = document.createElement('pre');
    draftValueElement.className = 'collaborate-conflicts__value';
    draftValueElement.dataset.draftField = name;
    details.append(saved, savedValue, draft, draftValueElement);
    refresh(name, savedValue, draftValueElement);
    session.conflictPanel.append(details);
  }
  const actions = document.createElement('div');
  actions.className = 'd-flex flex-wrap gap-2 mt-2';
  for (const [keep, label] of [
    [true, 'keepDraft'],
    [false, 'useSaved'],
  ]) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.collaborateReconcile = keep ? 'keep' : 'saved';
    button.className = 'btn btn-sm btn-secondary';
    button.textContent = messages[label];
    button.addEventListener('click', () => session.reconcile(keep));
    actions.append(button);
  }
  session.conflictPanel.append(actions);
}
