// The host's CKEditor wiring, as the bundle's contract asks: each editor is created with
// `session.editorConfiguration(field)` and the `Collaborate` plugin, then reports `editorReady(name)` or
// `editorFailed()`. Rows a peer adds to a collection get their editors through `addRowInitializer()`.

import Collaborate from '@janalis/collaborate/ckeditor5/collaborate.js';
import { addRowInitializer, sessionFor } from '@janalis/collaborate/easyadmin.js';
import { Bold, ClassicEditor, Essentials, Italic, Paragraph } from 'ckeditor5';

export async function createEditor(textarea) {
  if (textarea.dataset.editor) return null;
  textarea.dataset.editor = 'starting';
  const session = sessionFor(textarea);
  const shared = session ? await session.editorConfiguration(textarea) : {};
  try {
    const editor = await ClassicEditor.create(textarea, {
      licenseKey: 'GPL',
      plugins: [Essentials, Paragraph, Bold, Italic, Collaborate],
      toolbar: ['bold', 'italic', '|', 'undo', 'redo'],
      ...shared,
    });
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

addRowInitializer((row) => startEditors(row));
