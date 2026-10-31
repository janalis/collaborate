import {
  BoldEditing,
  Editor,
  HeadingEditing,
  ImageBlockEditing,
  ImageInlineEditing,
  LinkEditing,
  Markdown,
  Paragraph,
  TableEditing,
  UndoEditing,
} from 'ckeditor5';
import Collaborate from '../../../assets/ckeditor5/collaborate.js';
import { applySource, hasPendingUpload } from '../../../assets/ckeditor5/source.js';

const editors = [];

async function peer(initial = '<p>abcd</p>', extraPlugins = []) {
  let buffer;
  const session = {
    adapters: new Map(),
    schedule: vi.fn(),
    register: vi.fn(function (field, value) {
      this.adapters.set(field, value);
      buffer = value;
      return vi.fn();
    }),
  };
  const editor = new Editor({
    licenseKey: 'GPL',
    plugins: [
      Paragraph,
      BoldEditing,
      UndoEditing,
      TableEditing,
      LinkEditing,
      ImageBlockEditing,
      ImageInlineEditing,
      HeadingEditing,
      Collaborate,
      ...extraPlugins,
    ],
    collaborate: { getSession: () => session, field: 'body' },
  });
  editors.push(editor);
  editor.model.document.createRoot();
  await editor.initPlugins();
  await editor.data.init(initial);
  editor.fire('ready');
  return { editor, buffer, session };
}

const insert = (peer, value, offset) =>
  peer.editor.model.change((writer) =>
    writer.insertText(value, peer.editor.model.document.getRoot().getChild(0), offset)
  );
function accept(sender, peers) {
  const batch = sender.buffer.next();
  for (const peer of peers) peer.buffer.receive(batch);
}

afterEach(async () => {
  await Promise.all(editors.splice(0).map((editor) => editor.destroy()));
});

test('uses the requested public plugin name and stays inactive without configuration', async () => {
  expect(Collaborate.pluginName).toBe('Collaborate');
  const editor = new Editor({ licenseKey: 'GPL', plugins: [Paragraph, Collaborate] });
  editors.push(editor);
  editor.model.document.createRoot();
  await editor.initPlugins();
  await editor.data.init('<p>local</p>');
  editor.fire('ready');
  expect(editor.plugins.get('Collaborate').buffer).toBeUndefined();
  expect(editor.getData()).toBe('<p>local</p>');
});

test('merges two simultaneous writers, undoes only local content and synchronizes redo', async () => {
  const a = await peer();
  const b = await peer();
  expect(a.session.adapters.get('body')).toBe(a.buffer);
  insert(a, 'X', 2);
  insert(b, 'Y', 2);
  accept(a, [a, b]);
  accept(b, [a, b]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  a.editor.execute('undo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abYcd</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  a.editor.execute('redo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  expect(a.editor.getData()).toContain('X');
  b.editor.execute('undo');
  accept(b, [a, b]);
  expect(a.editor.getData()).toBe('<p>abXcd</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
});

test('retains native undo and redo when another batch interrupts a reused batch', async () => {
  const a = await peer();
  const b = await peer();
  const model = a.editor.model;
  const batch = model.createBatch();
  const append = (writer, value) => writer.insertText(value, model.document.getRoot().getChild(0), 'end');
  model.enqueueChange(batch, (writer) => append(writer, 'X'));
  model.change((writer) => append(writer, 'Y'));
  model.enqueueChange(batch, (writer) => append(writer, 'Z'));
  expect(a.buffer.pending).toHaveLength(3);
  accept(a, [a, b]);
  accept(a, [a, b]);
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abcdXYZ</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  a.editor.execute('undo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abcdXZ</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  a.editor.execute('undo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abcd</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  a.editor.execute('redo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abcdXZ</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  a.editor.execute('redo');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('<p>abcdXYZ</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  expect(a.buffer.pending).toHaveLength(0);
  expect(b.buffer.pending).toHaveLength(0);
});

test('preserves removed nodes when concurrent undo merges acquire no-op padding during rebase', async () => {
  const a = await peer();
  const b = await peer();
  const split = (p, offset) =>
    p.editor.model.change((writer) =>
      writer.split(writer.createPositionAt(p.editor.model.document.getRoot().getChild(0), offset))
    );
  split(a, 4);
  split(b, 1);
  a.editor.execute('undo');
  b.editor.execute('undo');
  a.editor.model.change((writer) => {
    const paragraph = a.editor.model.document.getRoot().getChild(0);
    writer.remove(writer.createRange(writer.createPositionAt(paragraph, 0), writer.createPositionAt(paragraph, 1)));
  });
  split(a, 0);
  a.editor.execute('undo');
  a.editor.execute('undo');
  for (let round = 0; round < 20 && (a.buffer.pending.length || b.buffer.pending.length); round++) {
    if (a.buffer.next()) accept(a, [a, b]);
    if (b.buffer.next()) accept(b, [a, b]);
  }
  expect(a.buffer.pending).toHaveLength(0);
  expect(b.buffer.pending).toHaveLength(0);
  expect(a.editor.getData()).toBe('<p>abcd</p>');
  expect(b.editor.getData()).toBe(a.editor.getData());
  expect(Array.from(b.editor.model.document.graveyard.getChildren(), (node) => node.toJSON())).toEqual(
    Array.from(a.editor.model.document.graveyard.getChildren(), (node) => node.toJSON())
  );
});

test('merges a structural split with concurrent text and replays to a late joiner', async () => {
  const a = await peer();
  const b = await peer();
  a.editor.model.change((writer) =>
    writer.split(writer.createPositionAt(a.editor.model.document.getRoot().getChild(0), 2))
  );
  insert(b, 'Y', 3);
  const first = a.buffer.next();
  a.buffer.receive(first);
  b.buffer.receive(first);
  const second = b.buffer.next();
  b.buffer.receive(second);
  a.buffer.receive(second);
  expect(a.editor.getData()).toBe('<p>ab</p><p>cYd</p>');
  expect(a.editor.getData()).toBe(b.editor.getData());
  const late = await peer();
  late.buffer.receive(JSON.parse(JSON.stringify(first)));
  late.buffer.receive(JSON.parse(JSON.stringify(second)));
  expect(late.editor.getData()).toBe(a.editor.getData());
});

test('converges with three writers and concurrent formatting', async () => {
  const a = await peer();
  const b = await peer();
  const c = await peer();
  insert(a, 'X', 1);
  insert(b, 'Y', 1);
  c.editor.model.change((writer) =>
    writer.setAttribute('bold', true, writer.createRangeIn(c.editor.model.document.getRoot().getChild(0)))
  );
  accept(a, [a, b, c]);
  accept(b, [a, b, c]);
  accept(c, [a, b, c]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  expect(a.editor.getData()).toBe(c.editor.getData());
  expect(a.buffer.pending).toHaveLength(0);
  expect(b.buffer.pending).toHaveLength(0);
  expect(c.buffer.pending).toHaveLength(0);
});

test('source formatting preserves concurrently inserted text instead of replacing the document', async () => {
  const a = await peer('abcd', [Markdown]);
  const b = await peer('abcd', [Markdown]);
  applySource(a.editor, '**abcd**');
  insert(b, 'Y', 2);
  accept(a, [a, b]);
  accept(b, [a, b]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  expect(a.editor.getData()).toContain('Y');
  expect(a.editor.getData()).toContain('**');
});

test('source edits split and merge paragraphs while preserving concurrent content', async () => {
  const a = await peer('abcd', [Markdown]);
  const b = await peer('abcd', [Markdown]);
  applySource(a.editor, 'ab\n\ncd');
  insert(b, 'Y', 3);
  accept(a, [a, b]);
  accept(b, [a, b]);
  expect(a.editor.getData()).toBe('ab\n\ncYd');
  expect(a.editor.getData()).toBe(b.editor.getData());
  applySource(a.editor, 'abcYd');
  accept(a, [a, b]);
  expect(a.editor.getData()).toBe('abcYd');
  expect(a.editor.getData()).toBe(b.editor.getData());
});

test('replays table and image structure alongside concurrent text', async () => {
  const a = await peer();
  const b = await peer();
  a.editor.model.change((writer) => writer.setSelection(a.editor.model.document.getRoot(), 'end'));
  a.editor.execute('insertTable', { rows: 2, columns: 2 });
  insert(b, 'peer', 2);
  accept(a, [a, b]);
  accept(b, [a, b]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  expect(a.editor.getData()).toContain('<table>');
  a.editor.model.change((writer) => {
    const image = writer.createElement('imageBlock', { src: '/uploads/shared.png' });
    writer.append(image, a.editor.model.document.getRoot());
  });
  while (a.buffer.next()) accept(a, [a, b]);
  expect(a.editor.getData()).toBe(b.editor.getData());
  expect(b.editor.getData()).toContain('/uploads/shared.png');
});

test('keeps transient image uploads intact while source editing is requested', async () => {
  const a = await peer();
  a.editor.model.schema.extend('imageBlock', { allowAttributes: ['uploadId', 'uploadStatus'] });
  a.editor.model.change((writer) => {
    writer.append(
      writer.createElement('imageBlock', { src: '/preview.png', uploadId: 'inflight', uploadStatus: 'uploading' }),
      a.editor.model.document.getRoot()
    );
  });
  expect(hasPendingUpload(a.editor.model.document.getRoot())).toBe(true);
  const before = a.editor.getData();
  a.editor.plugins.get('Collaborate').applySource('replacement');
  expect(a.editor.getData()).toBe(before);
  a.editor.model.change((writer) => {
    const image = a.editor.model.document.getRoot().getChild(1);
    writer.removeAttribute('uploadId', image);
    writer.removeAttribute('uploadStatus', image);
  });
  expect(hasPendingUpload(a.editor.model.document.getRoot())).toBe(false);
});

test('adopts a saved record value as granular model operations for its peers', async () => {
  const first = await peer('<p>abcd</p>');
  const second = await peer('<p>abcd</p>');
  first.buffer.adopt('<p>saved</p>');
  expect(first.editor.getData()).toBe('<p>saved</p>');
  accept(first, [second]);
  expect(second.editor.getData()).toBe('<p>saved</p>');
});

test('source edits replace text, remove formatting and extra blocks, rename headings and reconcile image attributes', async () => {
  const a = await peer('<p><strong>abc</strong></p><p>extra</p>', [HeadingEditing, ImageInlineEditing]);
  const plugin = a.editor.plugins.get('Collaborate');
  plugin.applySource('<h2>axc</h2>');
  expect(a.editor.getData()).toBe('<h2>axc</h2>');
  plugin.applySource('<p><img src="/one.png" alt="before"></p>');
  expect(a.editor.getData()).toContain('/one.png');
  plugin.applySource('<p><img src="/two.png"></p>');
  expect(a.editor.getData()).toContain('/two.png');
  expect(a.editor.getData()).not.toContain('before');
  const remove = vi.fn();
  a.session.registerMode = vi.fn(() => remove);
  plugin.attachSource();
  expect(a.session.registerMode).toHaveBeenCalledOnce();
  a.session.registerMode.mock.calls[0][2]('<p>delegated source</p>');
  expect(a.editor.getData()).toBe('<p>delegated source</p>');
});

test('maps a focused source selection through a remote model insertion', async () => {
  const a = await peer();
  const b = await peer();
  const field = document.createElement('textarea');
  document.body.append(field);
  a.editor.sourceElement = field;
  field.value = a.editor.getData();
  a.editor.model.document.on('change:data', () => {
    field.value = a.editor.getData();
  });
  field.focus();
  field.setSelectionRange(4, 7);
  insert(b, 'X', 2);
  accept(b, [a, b]);
  expect(field.value).toBe('<p>abXcd</p>');
  expect(field.selectionStart).toBe(4);
  expect(field.selectionEnd).toBe(8);
  field.remove();
});

test('keeps typed Markdown source while a remote edit lands elsewhere and flags the mirror as applied', async () => {
  const a = await peer('<p>abcd</p><p>tail</p>', [Markdown]);
  const b = await peer('<p>abcd</p><p>tail</p>', [Markdown]);
  const plugin = a.editor.plugins.get('Collaborate');
  const field = document.createElement('textarea');
  document.body.append(field);
  a.editor.sourceElement = field;
  const applying = [];
  a.editor.model.document.on('change:data', () => {
    if (plugin.sourceEditing) return;
    applying.push(a.session.applying);
    field.value = a.editor.getData();
  });
  field.value = a.editor.getData();
  field.focus();
  field.value = `${field.value}\n\n**bo`;
  plugin.applySource(field.value);
  accept(a, [a, b]);
  field.setSelectionRange(field.value.length, field.value.length);
  insert(b, 'X', 2);
  accept(b, [a, b]);
  expect(field.value).toBe('abXcd\n\ntail\n\n**bo');
  expect(field.selectionStart).toBe(field.value.length);
  expect(applying).toEqual([true]);
  expect(a.session.applying).toBe(false);
  field.value = `${field.value}ld**`;
  plugin.applySource(field.value);
  expect(a.editor.getData()).toBe('abXcd\n\ntail\n\n**bold**');
  field.remove();
});

test('settles unapplied source typing before a remote edit lands, so an overlapping edit merges instead of replacing it', async () => {
  const a = await peer('<p>abcd</p>', [Markdown]);
  const b = await peer('<p>abcd</p>', [Markdown]);
  const plugin = a.editor.plugins.get('Collaborate');
  const field = document.createElement('textarea');
  document.body.append(field);
  a.editor.sourceElement = field;
  a.editor.model.document.on('change:data', () => {
    if (!plugin.sourceEditing) field.value = a.editor.getData();
  });
  field.value = a.editor.getData();
  field.focus();
  // Typing still waits for its pause: the session's settle applies it on request.
  field.value = 'aYYd';
  let pending = true;
  a.session.settleSource = vi.fn((name) => {
    expect(name).toBe('body');
    if (pending) plugin.applySource(field.value);
    pending = false;
  });
  insert(b, 'X', 2);
  accept(b, [a, b]);
  expect(a.session.settleSource).toHaveBeenCalledOnce();
  expect(field.value).toBe('aYYd');
  expect(a.editor.getData()).toBe('aYYd');
  expect(a.buffer.hasPending()).toBe(true);
  field.remove();
});

test('moves a focused source selection inside text a remote edit replaced to the end of the replacement', async () => {
  const a = await peer();
  const b = await peer();
  const field = document.createElement('textarea');
  document.body.append(field);
  a.editor.sourceElement = field;
  field.value = a.editor.getData();
  a.editor.model.document.on('change:data', () => {
    field.value = a.editor.getData();
  });
  field.focus();
  // `<p>abcd</p>`: the selection covers `c`, which the remote edit replaces with `XY`.
  field.setSelectionRange(5, 6);
  b.editor.model.change((writer) => {
    const paragraph = b.editor.model.document.getRoot().getChild(0);
    writer.remove(writer.createRange(writer.createPositionAt(paragraph, 1), writer.createPositionAt(paragraph, 3)));
    writer.insertText('XY', paragraph, 1);
  });
  while (b.buffer.hasPending()) accept(b, [a, b]);
  expect(field.value).toBe('<p>aXYd</p>');
  expect(field.selectionStart).toBe(6);
  expect(field.selectionEnd).toBe(6);
  field.remove();
});

test('replaces a paragraph holding an inline image when source splits it, rather than splitting its text', async () => {
  const a = await peer('<p>ab<img src="/inline.png">cd</p>');
  const b = await peer('<p>ab<img src="/inline.png">cd</p>');
  const plugin = a.editor.plugins.get('Collaborate');
  plugin.applySource('<p>ab</p><p><img src="/inline.png">cd</p>');
  expect(a.editor.getData()).toBe('<p>ab</p><p><img src="/inline.png">cd</p>');
  while (a.buffer.hasPending()) accept(a, [a, b]);
  expect(b.editor.getData()).toBe(a.editor.getData());
});
