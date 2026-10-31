import { MergeOperation, Model, MoveOperation } from 'ckeditor5';
import { OperationBuffer } from '../../../assets/ckeditor5/operation-buffer.js';

function peer(initialize = () => {}) {
  const model = new Model();
  model.document.createRoot();
  model.schema.register('paragraph', { inheritAllFrom: '$block' });
  model.schema.extend('$text', { allowAttributes: ['bold'] });
  model.change((writer) => {
    const paragraph = writer.createElement('paragraph');
    writer.append(paragraph, model.document.getRoot());
    writer.insertText('abcd', paragraph, 0);
  });
  initialize(model);
  return { model, buffer: new OperationBuffer(model) };
}

const paragraph = (p) => p.model.document.getRoot().getChild(0);
const text = (p) => Array.from(paragraph(p).getChildren(), (node) => node.data).join('');
const insert = (p, value, offset) => p.model.change((writer) => writer.insertText(value, paragraph(p), offset));
const remove = (p, start, end) =>
  p.model.change((writer) =>
    writer.remove(
      writer.createRange(writer.createPositionAt(paragraph(p), start), writer.createPositionAt(paragraph(p), end))
    )
  );

describe('Collaborate real CKEditor operation protocol', () => {
  test('reports pending local operations without marking them sent', () => {
    const a = peer();
    try {
      expect(a.buffer.hasPending()).toBe(false);
      insert(a, 'X', 0);
      expect(a.buffer.hasPending()).toBe(true);
      expect(a.buffer.pending[0].sent).toBe(false);
      const batch = a.buffer.next();
      a.buffer.receive({ ...batch, revision: 1 });
      expect(a.buffer.hasPending()).toBe(false);
    } finally {
      a.buffer.destroy();
    }
  });

  test('keeps reused native batches in document order and acknowledges every contiguous group', () => {
    const a = peer();
    const b = peer();
    try {
      const version = a.buffer.version;
      const batch = a.model.createBatch();
      a.model.enqueueChange(batch, (writer) => writer.insertText('X', paragraph(a), 'end'));
      insert(a, 'Y', paragraph(a).maxOffset);
      a.model.enqueueChange(batch, (writer) => writer.insertText('Z', paragraph(a), 'end'));
      expect(a.buffer.pending.map((group) => group.operations.map((operation) => operation.baseVersion))).toEqual([
        [version],
        [version + 1],
        [version + 2],
      ]);
      for (let index = 0; index < 3; index++) {
        const event = JSON.parse(JSON.stringify(a.buffer.next()));
        expect(event.version).toBe(version + index);
        expect(event.operations.map((operation) => operation.baseVersion)).toEqual([event.version]);
        a.buffer.receive(event);
        b.buffer.receive(event);
        b.buffer.receive(event);
      }
      expect(text(a)).toBe('abcdXYZ');
      expect(text(b)).toBe(text(a));
      expect(a.buffer.pending).toHaveLength(0);
      expect(b.buffer.version).toBe(a.model.document.version);
    } finally {
      for (const p of [a, b]) {
        p.buffer.destroy();
        p.model.destroy();
      }
    }
  });

  test('isolates merges from other operations in the same native undo batch', () => {
    const a = peer((model) =>
      model.change((writer) => {
        writer.appendElement('paragraph', model.document.getRoot());
      })
    );
    a.model.change((writer) => {
      writer.insertText('X', paragraph(a), 0);
      writer.merge(writer.createPositionAt(a.model.document.getRoot(), 1));
      writer.insertText('Y', paragraph(a), 0);
    });
    expect(
      a.buffer.pending.map((group) => group.operations.map((operation) => operation.toJSON().__className))
    ).toEqual([['InsertOperation'], ['MergeOperation'], ['InsertOperation']]);
    a.buffer.destroy();
    a.model.destroy();
  });

  test.each([
    [0, 1],
    [1, 0],
  ])('keeps matching merges and later undo aligned when their graveyard positions differ: %s, %s', (remote, local) => {
    const initialize = (model) =>
      model.change((writer) => {
        const first = model.document.getRoot().getChild(0);
        writer.insertText('X', first, 0);
        writer.remove(writer.createRange(writer.createPositionAt(first, 0), writer.createPositionAt(first, 1)));
        const second = writer.createElement('paragraph');
        writer.insertText('ef', second, 0);
        writer.append(second, model.document.getRoot());
      });
    const a = peer(initialize);
    const b = peer(initialize);
    const merge = (p, offset) =>
      p.model.change((writer) => {
        const root = p.model.document.getRoot();
        const operation = new MergeOperation(
          writer.createPositionAt(root.getChild(1), 0),
          2,
          writer.createPositionAt(root.getChild(0), 'end'),
          writer.createPositionAt(p.model.document.graveyard, offset),
          p.model.document.version
        );
        writer.batch.addOperation(operation);
        p.model.applyOperation(operation);
      });
    merge(a, remote);
    merge(b, local);
    const accepted = JSON.parse(JSON.stringify(a.buffer.next()));
    a.buffer.receive(accepted);
    b.buffer.receive(accepted);
    const rebased = JSON.parse(JSON.stringify(b.buffer.next()));
    a.buffer.receive(rebased);
    b.buffer.receive(rebased);
    expect(Array.from(b.model.document.graveyard.getChildren(), (node) => node.toJSON())).toEqual(
      Array.from(a.model.document.graveyard.getChildren(), (node) => node.toJSON())
    );
    a.model.change((writer) => {
      const operation = new MoveOperation(
        writer.createPositionAt(a.model.document.graveyard, 1 - remote),
        1,
        writer.createPositionAt(paragraph(a), 'end'),
        a.model.document.version
      );
      writer.batch.addOperation(operation);
      a.model.applyOperation(operation);
    });
    const restored = JSON.parse(JSON.stringify(a.buffer.next()));
    a.buffer.receive(restored);
    b.buffer.receive(restored);
    expect(text(a)).toBe('abcdefX');
    expect(text(b)).toBe(text(a));
    expect(a.model.document.version).toBe(b.model.document.version);
    for (const p of [a, b]) {
      p.buffer.destroy();
      p.model.destroy();
    }
  });

  test('replays an accepted insert and ignores duplicate delivery', () => {
    const a = peer();
    const b = peer();
    insert(a, 'X', 2);
    const event = a.buffer.next();
    a.buffer.receive(event);
    b.buffer.receive(event);
    b.buffer.receive(event);
    expect(text(a)).toBe('abXcd');
    expect(text(b)).toBe(text(a));
    expect(a.buffer.pending).toHaveLength(0);
    expect(b.buffer.pending).toHaveLength(0);
  });

  test.each(['insert', 'delete', 'format'])('converges after concurrent %s and insertion', (kind) => {
    const a = peer();
    const b = peer();
    insert(a, 'X', 2);
    if (kind === 'insert') insert(b, 'Y', 2);
    if (kind === 'delete') remove(b, 1, 3);
    if (kind === 'format')
      b.model.change((writer) => writer.setAttribute('bold', true, writer.createRangeIn(paragraph(b))));
    const accepted = a.buffer.next();
    a.buffer.receive(accepted);
    b.buffer.receive(accepted);
    const rebased = b.buffer.next();
    b.buffer.receive(rebased);
    a.buffer.receive(rebased);
    expect(text(a)).toBe(text(b));
    expect(a.model.document.version).toBe(b.model.document.version);
    expect(Array.from(paragraph(a).getChildren(), (node) => node.toJSON())).toEqual(
      Array.from(paragraph(b).getChildren(), (node) => node.toJSON())
    );
  });

  test('preserves later local batches while an earlier request is in flight', () => {
    const a = peer();
    const b = peer();
    insert(a, 'X', 0);
    const first = a.buffer.next();
    insert(a, 'Z', 0);
    insert(b, 'Y', 0);
    const winner = b.buffer.next();
    b.buffer.receive(winner);
    a.buffer.receive(winner);
    expect(a.buffer.next().version).toBeGreaterThan(first.version);
    for (let i = 0; i < 2; i++) {
      const event = a.buffer.next();
      a.buffer.receive(event);
      b.buffer.receive(event);
    }
    expect(text(a)).toBe(text(b));
    expect(a.model.document.version).toBe(b.model.document.version);
  });

  test('rejects gaps and mismatched acknowledgements; detaches capture on destroy', () => {
    const a = peer();
    insert(a, 'X', 0);
    const event = a.buffer.next();
    expect(() => a.buffer.receive({ ...event, version: event.version + 1 })).toThrow('gap');
    expect(() => a.buffer.receive({ ...event, operations: [] })).toThrow('mismatch');
    a.buffer.receive(event);
    a.buffer.destroy();
    insert(a, 'Y', 0);
    expect(a.buffer.next()).toBeNull();
  });

  test('journals post-fixer repairs produced by concurrent remote operations', () => {
    const a = peer();
    const b = peer();
    for (const p of [a, b]) {
      p.model.schema.extend('paragraph', { allowAttributes: ['merged'] });
      p.model.document.registerPostFixer((writer) => {
        if (text(p).includes('X') && text(p).includes('Y') && !paragraph(p).hasAttribute('merged')) {
          writer.setAttribute('merged', true, paragraph(p));
          return true;
        }
        return false;
      });
    }
    insert(a, 'X', 1);
    insert(b, 'Y', 1);
    const first = a.buffer.next();
    a.buffer.receive(first);
    b.buffer.receive(first);
    const second = b.buffer.next();
    b.buffer.receive(second);
    a.buffer.receive(second);
    for (let count = 0; count < 10 && (a.buffer.pending.length || b.buffer.pending.length); count++) {
      const event = a.buffer.next() || b.buffer.next();
      a.buffer.receive(event);
      b.buffer.receive(event);
    }
    expect(a.buffer.pending).toHaveLength(0);
    expect(b.buffer.pending).toHaveLength(0);
    expect(a.model.document.version).toBe(b.model.document.version);
    for (const p of [a, b]) {
      expect(p.model.document.version).toBe(
        p.buffer.version + p.buffer.pending.reduce((count, batch) => count + batch.operations.length, 0)
      );
    }
  });

  test('sends an insert made inside a range a peer removed as a graveyard insert, and both peers converge', () => {
    const a = peer();
    const b = peer();
    try {
      remove(a, 1, 3);
      insert(b, 'X', 2);
      const removal = JSON.parse(JSON.stringify(a.buffer.next()));
      a.buffer.receive(removal);
      b.buffer.receive(removal);
      // The plain-text journal accepts this position: the text is unchanged, the graveyard grows.
      const rebased = JSON.parse(JSON.stringify(b.buffer.next()));
      expect(rebased.operations).toHaveLength(1);
      expect(rebased.operations[0].__className).toBe('InsertOperation');
      expect(rebased.operations[0].position.root).toBe('$graveyard');
      expect(rebased.operations[0].position.path).toHaveLength(1);
      a.buffer.receive(rebased);
      b.buffer.receive(rebased);
      expect(text(a)).toBe('ad');
      expect(text(b)).toBe('ad');
      expect(b.buffer.hasPending()).toBe(false);
    } finally {
      for (const p of [a, b]) {
        p.buffer.destroy();
        p.model.destroy();
      }
    }
  });
});
