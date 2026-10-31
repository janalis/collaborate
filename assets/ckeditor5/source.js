const attributes = (node) => Object.fromEntries(node.getAttributes());
const sameAttributes = (a, b) => JSON.stringify(attributes(a)) === JSON.stringify(attributes(b));
const textOf = (node) => Array.from(node.getChildren(), (child) => (child.is('$text') ? child.data : '')).join('');

export function hasPendingUpload(root) {
  for (const child of root.getChildren()) {
    if (
      child.is('element') &&
      ((child.hasAttribute('uploadId') && child.getAttribute('uploadStatus') !== 'complete') || hasPendingUpload(child))
    )
      return true;
  }
  return false;
}

/** Translate a source edit to granular model operations, never a document-wide setData(). */
export function applySource(editor, source) {
  const desired = editor.data.parse(source);
  editor.model.change((writer) =>
    reconcile(writer, editor.model.document.getRoot(), Array.from(desired.getChildren()))
  );
}

function reconcile(writer, parent, desired) {
  let offset = 0;
  for (let index = 0; index < desired.length; ) {
    const next = desired[index];
    let current = parent.getChild(parent.offsetToIndex(offset));
    if (next.is('$text')) {
      const wanted = [];
      while (index < desired.length && desired[index].is('$text')) wanted.push(desired[index++]);
      const existing = [];
      let childIndex = parent.offsetToIndex(offset);
      while (parent.getChild(childIndex)?.is('$text')) existing.push(parent.getChild(childIndex++));
      const before = existing.map((node) => node.data).join('');
      const after = wanted.map((node) => node.data).join('');
      const { prefix, end, inserted } = textChange(before, after);
      if (end > prefix)
        writer.remove(
          writer.createRange(
            writer.createPositionAt(parent, offset + prefix),
            writer.createPositionAt(parent, offset + end)
          )
        );
      if (inserted) writer.insertText(inserted, parent, offset + prefix);
      const names = new Set([...existing, ...wanted].flatMap((node) => Array.from(node.getAttributeKeys())));
      for (const run of wanted) {
        const range = writer.createRange(
          writer.createPositionAt(parent, offset),
          writer.createPositionAt(parent, offset + run.offsetSize)
        );
        for (const name of names) {
          if (run.hasAttribute(name)) writer.setAttribute(name, run.getAttribute(name), range);
          else writer.removeAttribute(name, range);
        }
        offset += run.offsetSize;
      }
      continue;
    }
    // Preserve node identity for the common source split/merge cases. Native OT
    // can then carry concurrent edits across the structural change.
    if (
      current?.is('element') &&
      current.name === next.name &&
      desired[index + 1]?.name === next.name &&
      sameAttributes(current, next) &&
      textOf(current) === textOf(next) + textOf(desired[index + 1]) &&
      textOf(current).length === current.maxOffset
    ) {
      writer.split(writer.createPositionAt(current, textOf(next).length));
    }
    const following = parent.getChild(parent.offsetToIndex(offset) + 1);
    if (
      current?.is('element') &&
      following?.is('element') &&
      current.name === next.name &&
      following.name === next.name &&
      sameAttributes(current, next) &&
      sameAttributes(following, next) &&
      textOf(next) === textOf(current) + textOf(following) &&
      textOf(current).length === current.maxOffset &&
      textOf(following).length === following.maxOffset
    ) {
      writer.merge(writer.createPositionBefore(following));
    }
    current = parent.getChild(parent.offsetToIndex(offset));
    if (current?.is('element')) {
      if (current.name !== next.name) writer.rename(current, next.name);
      for (const name of current.getAttributeKeys()) {
        if (!next.hasAttribute(name) && !['uploadId', 'uploadStatus'].includes(name))
          writer.removeAttribute(name, current);
      }
      for (const [name, value] of next.getAttributes()) writer.setAttribute(name, value, current);
      reconcile(writer, current, Array.from(next.getChildren()));
    } else {
      if (current?.is('$text')) writer.remove(current);
      writer.insert(next, parent, offset);
    }
    ++offset;
    ++index;
  }
  if (offset < parent.maxOffset)
    writer.remove(writer.createRange(writer.createPositionAt(parent, offset), writer.createPositionAt(parent, 'end')));
}

import { textChange } from './text-change.js';
