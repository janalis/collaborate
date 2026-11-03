// The host's CKEditor wiring, as the bundle's contract asks: each editor is created with
// `session.editorConfiguration(field)` and the `Collaborate` plugin, then reports `editorReady(name)` or
// `editorFailed()`. Rows a peer adds to a collection get their editors through `addRowInitializer()`.
//
// A textarea marked `data-markdown` stores Markdown and offers a source view: radios named
// `<textarea id>_view` choose the mode (the source one's value is `markdown`), the source is edited in the
// editor's own source element, and the plugin's `attachSource()` keeps it and the model in step.

import Collaborate from '@janalis/collaborate/ckeditor5/collaborate.js';
import { addRowInitializer, sessionFor } from '@janalis/collaborate/easyadmin.js';
import { Bold, ClassicEditor, Essentials, Italic, Markdown, Paragraph } from 'ckeditor5';

/** The two mode radios placed before a Markdown field's textarea. */
function modeSwitch(textarea) {
  const group = document.createElement('div');
  group.className = 'btn-group btn-group-sm mb-2';
  group.setAttribute('role', 'group');
  group.setAttribute('aria-label', 'Editing mode');
  for (const [value, label] of [
    ['wysiwyg', 'Rendered'],
    ['markdown', 'Markdown source'],
  ]) {
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.className = 'btn-check';
    radio.name = `${textarea.id}_view`;
    radio.id = `${textarea.id}_view_${value}`;
    radio.value = value;
    radio.checked = value === 'wysiwyg';
    radio.autocomplete = 'off';
    const text = document.createElement('label');
    text.className = 'btn btn-outline-secondary';
    text.htmlFor = radio.id;
    text.textContent = label;
    group.append(radio, text);
  }
  textarea.before(group);
  return Array.from(group.querySelectorAll('input'));
}

/** Shows the rendered editor or its source, as the checked radio says (a peer's switch checks it too). */
function bindMode(editor, textarea, radios) {
  const markdown = () => radios.some((radio) => radio.checked && radio.value === 'markdown');
  const show = () => {
    const source = markdown();
    if (source) textarea.value = editor.getData();
    editor.ui.element.hidden = source;
    textarea.style.display = source ? 'block' : 'none';
    textarea.classList.add('form-control');
  };
  for (const radio of radios) radio.addEventListener('change', show);
  // A peer's edit reaches the model: the source shown follows it, unless this page is typing in it.
  editor.model.document.on('change:data', () => {
    if (markdown() && document.activeElement !== textarea) textarea.value = editor.getData();
  });
  editor.plugins.get('Collaborate').attachSource();
}

export async function createEditor(textarea) {
  if (textarea.dataset.editor) return null;
  textarea.dataset.editor = 'starting';
  const markdown = textarea.dataset.markdown === 'true';
  const radios = markdown ? modeSwitch(textarea) : [];
  const session = sessionFor(textarea);
  const shared = session ? await session.editorConfiguration(textarea) : {};
  try {
    // A scenario's way to break the editor: `window.collaborateBreakEditors` set before the page loads.
    if (window.collaborateBreakEditors) throw new Error('This editor failed to start.');
    const editor = await ClassicEditor.create(textarea, {
      licenseKey: 'GPL',
      plugins: [Essentials, Paragraph, Bold, Italic, Collaborate, ...(markdown ? [Markdown] : [])],
      toolbar: ['bold', 'italic', '|', 'undo', 'redo'],
      ...shared,
    });
    if (markdown) bindMode(editor, textarea, radios);
    textarea.dataset.editor = 'ready';
    session?.editorReady(textarea.name);
    return editor;
  } catch (error) {
    textarea.dataset.editor = 'failed';
    session?.editorFailed();
    throw error;
  }
}

export function startEditors(root = document) {
  for (const textarea of root.querySelectorAll('textarea[data-rich-text]')) createEditor(textarea).catch(console.error);
}

/** Moves a collection entry above the one before it: the bundle observes the rows moving. */
export function moveUp(item) {
  let previous = item.previousElementSibling;
  while (previous && !previous.matches('[data-collaborate-entry], .field-collection-item'))
    previous = previous.previousElementSibling;
  if (previous) previous.before(item);
}

addRowInitializer((row) => startEditors(row));
