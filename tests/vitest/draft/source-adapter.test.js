import { SourceAdapter } from '../../../assets/draft/source-adapter.js';

const adapters = [];
const create = (text = 'abcd', schedule) => {
  const adapter = new SourceAdapter(text, schedule);
  adapters.push(adapter);
  return adapter;
};
afterEach(() => {
  for (const adapter of adapters.splice(0)) adapter.destroy();
});

function deliver(sender, recipient) {
  const event = JSON.parse(JSON.stringify(sender.next()));
  sender.receive(event);
  recipient.receive(event);
}

test('applies distant edits atomically while preserving intervening source', () => {
  const schedule = vi.fn();
  const adapter = create('<p>abcd</p>', schedule);
  const listener = vi.fn();
  const unsubscribe = adapter.subscribe(listener);
  const revision = adapter.revision;
  adapter.transact(
    [
      { start: 0, end: 3, text: '<seg>' },
      { start: 7, end: 11, text: '</seg>' },
    ],
    revision
  );
  expect(adapter.value).toBe('<seg>abcd</seg>');
  expect(adapter.pending).toHaveLength(1);
  expect(listener).toHaveBeenCalledOnce();
  expect(schedule).toHaveBeenCalledOnce();
  unsubscribe();
  adapter.transact([{ start: 5, end: 5, text: 'X' }]);
  expect(listener).toHaveBeenCalledOnce();
  expect(() => adapter.transact([], revision)).toThrow('stale');
});

test('concurrent transactions converge without replacing intervening text', () => {
  const a = create('<p>abcd</p>');
  const b = create('<p>abcd</p>');
  a.transact([
    { start: 0, end: 3, text: '<seg>' },
    { start: 7, end: 11, text: '</seg>' },
  ]);
  b.transact([{ start: 5, end: 5, text: '😀' }]);
  deliver(a, b);
  deliver(b, a);
  expect(a.value).toBe('<seg>ab😀cd</seg>');
  expect(b.value).toBe(a.value);
  expect(a.hasPending()).toBe(false);
  expect(b.hasPending()).toBe(false);
});

test('tracks ranges through remote edits and releases them independently', () => {
  const a = create();
  const b = create();
  const range = b.track(1, 3);
  a.transact([{ start: 0, end: 0, text: 'XX' }]);
  deliver(a, b);
  expect([range.start, range.end]).toEqual([3, 5]);
  range.detach();
  expect(b.ranges.size).toBe(0);
});

test.each([
  [{ start: -1, end: 0, text: '' }],
  [{ start: 1, end: 0, text: '' }],
  [{ start: 0, end: 20, text: '' }],
  [{ start: 0.5, end: 1, text: '' }],
  [{ start: 0, end: 1, text: '\uD800' }],
  [{ start: 2, end: 2, text: 'split' }],
  [
    { start: 0, end: 3, text: '' },
    { start: 1, end: 1, text: 'overlap' },
  ],
  [
    { start: 1, end: 1, text: 'a' },
    { start: 1, end: 1, text: 'b' },
  ],
])('rejects invalid transactions without partial mutation: %j', (...splices) => {
  const adapter = create('a😀bc');
  expect(() => adapter.transact(splices)).toThrow(RangeError);
  expect(adapter.value).toBe('a😀bc');
  expect(adapter.hasPending()).toBe(false);
});

test('no-op transactions do not create journal events', () => {
  const adapter = create();
  adapter.transact([{ start: 0, end: 4, text: 'abcd' }]);
  adapter.transact([]);
  expect(adapter.hasPending()).toBe(false);
});

test('validates tracked ranges and disposes safely', () => {
  const adapter = create('a😀bc');
  for (const [start, end] of [
    [-1, 1],
    [2, 2],
    [0, 20],
    [3, 1],
    [0.1, 1],
  ]) {
    expect(() => adapter.track(start, end)).toThrow(RangeError);
  }
  adapter.track(1, 3);
  adapter.destroy();
  adapter.destroy();
  expect(adapter.ranges.size).toBe(0);
  expect(() => adapter.transact([])).toThrow('disposed');
  expect(() => adapter.subscribe(() => {})).toThrow('disposed');
  expect(() => adapter.track(0, 0)).toThrow('disposed');
});

test('undo and redo keep a remote insertion inside locally inserted text', () => {
  const a = create('ab');
  const b = create('ab');
  expect(a.canUndo).toBe(false);
  a.transact([{ start: 1, end: 1, text: 'XYZ' }]);
  deliver(a, b);
  b.transact([{ start: 2, end: 2, text: 'remote' }]);
  deliver(b, a);
  expect(a.value).toBe('aXremoteYZb');
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('aremoteb');
  expect(b.value).toBe(a.value);
  expect(a.canRedo).toBe(true);
  a.redo();
  deliver(a, b);
  expect(a.value).toBe('aXremoteYZb');
  expect(b.value).toBe(a.value);
});

test('undo restores a deletion without deleting a later remote edit', () => {
  const a = create();
  const b = create();
  a.transact([{ start: 1, end: 3, text: '' }]);
  deliver(a, b);
  b.transact([{ start: 0, end: 0, text: 'remote' }]);
  deliver(b, a);
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('remoteabcd');
  expect(b.value).toBe(a.value);
});

test('multi-splice undo is one transaction and remote changes do not become undoable', () => {
  const a = create('<p>abcd</p>');
  const b = create('<p>abcd</p>');
  a.transact([
    { start: 0, end: 3, text: '<seg>' },
    { start: 7, end: 11, text: '</seg>' },
  ]);
  deliver(a, b);
  expect(b.canUndo).toBe(false);
  b.transact([{ start: 6, end: 6, text: 'remote' }]);
  deliver(b, a);
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('<p>aremotebcd</p>');
  expect(b.value).toBe(a.value);
  expect(a.canUndo).toBe(false);
});

test('undo leaves remote text at both insertion boundaries', () => {
  const a = create('ab');
  const b = create('ab');
  a.transact([{ start: 1, end: 1, text: 'XYZ' }]);
  deliver(a, b);
  b.transact([
    { start: 1, end: 1, text: 'left' },
    { start: 4, end: 4, text: 'right' },
  ]);
  deliver(b, a);
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('aleftrightb');
  expect(b.value).toBe(a.value);
  a.redo();
  deliver(a, b);
  expect(a.value).toBe('aleftXYZrightb');
  expect(b.value).toBe(a.value);
});

test('undo follows partial remote deletion of locally inserted text', () => {
  const a = create('ab');
  const b = create('ab');
  a.transact([{ start: 1, end: 1, text: 'XYZ' }]);
  deliver(a, b);
  b.transact([{ start: 2, end: 3, text: '' }]);
  deliver(b, a);
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('ab');
  a.redo();
  deliver(a, b);
  expect(a.value).toBe('aXZb');
  expect(b.value).toBe(a.value);
});

test('new local edits clear redo; empty history commands are harmless', () => {
  const a = create();
  a.undo();
  a.redo();
  a.transact([{ start: 0, end: 0, text: 'X' }]);
  a.undo();
  expect(a.canRedo).toBe(true);
  a.transact([{ start: 0, end: 0, text: 'Y' }]);
  expect(a.canRedo).toBe(false);
  a.redo();
  expect(a.value).toBe('Yabcd');
});

test('constructs empty sources and keeps default scheduling optional', () => {
  const adapter = new SourceAdapter();
  adapters.push(adapter);
  expect(adapter.value).toBe('');
  adapter.transact([{ start: 0, end: 0, text: 'new' }]);
  expect(adapter.value).toBe('new');
});

test('snapshots reconstruct acknowledged identities and exclude unsent edits', () => {
  const a = create('😀abc');
  const b = create('😀abc');
  a.transact([{ start: 2, end: 3, text: 'X' }]);
  expect(a.snapshot().events).toEqual([]);
  deliver(a, b);
  const reopened = new SourceAdapter(a.snapshot());
  adapters.push(reopened);
  expect(reopened.value).toBe(a.value);
  reopened.transact([{ start: 3, end: 3, text: 'Y' }]);
  expect(reopened.value).toBe('😀XYbc');
  expect(reopened.canUndo).toBe(true);
  expect(() => reopened.transact([{ start: 0, end: 0, text: 7 }])).toThrow(RangeError);
});

test('replay ignores old deliveries and refuses gaps', () => {
  const a = create();
  expect(a.next()).toBeNull();
  a.transact([{ start: 0, end: 0, text: 'X' }]);
  const event = a.next();
  expect(() => a.receive({ ...event, version: 99 })).toThrow('gap');
  a.receive(event);
  a.receive(event);
  // A duplicate provenance event in an ordered delivery is harmless too.
  a.receive({ ...event, version: a.version });
  expect(a.value).toBe('Xabcd');
});

test('tracked positions collapse across deletions and follow the end of source', () => {
  const a = create('a😀bc');
  const range = a.track(1, 5);
  a.transact([{ start: 0, end: 4, text: '' }]);
  expect(range.start).toBe(0);
  expect(range.end).toBe(1);
  a.transact([{ start: 1, end: 1, text: 'Z' }]);
  expect(range.end).toBe(2);
});

test('deletions spanning split runs merge adjacent source intervals', () => {
  const a = create('abcd');
  a.transact([{ start: 2, end: 2, text: 'X' }]);
  a.undo();
  a.transact([{ start: 1, end: 3, text: '' }]);
  expect(a.value).toBe('ad');
  expect(a.pending.at(-1).deletes).toEqual([{ run: 'initial', start: 1, end: 3 }]);
});

test('same-clock insertions and toggles use deterministic identifier ordering', () => {
  const transaction = {
    __className: 'SourceTransaction',
    id: 'tx',
    clock: 1,
    inserts: [
      { id: 'b', after: null, text: 'B' },
      { id: 'a', after: null, text: 'A' },
    ],
    deletes: [],
  };
  const events = [
    transaction,
    { __className: 'SourceToggle', id: 'a', clock: 2, target: 'tx', active: false },
    { __className: 'SourceToggle', id: 'b', clock: 2, target: 'tx', active: true },
    { __className: 'SourceToggle', id: '0', clock: 2, target: 'tx', active: false },
    { __className: 'SourceToggle', id: 'z', clock: 1, target: 'tx', active: false },
  ];
  const a = new SourceAdapter({ initial: '', events });
  adapters.push(a);
  expect(a.value).toBe('AB');
});

test('source bounds reject mutations atomically and preserve undo availability', () => {
  expect(() => new SourceAdapter('\uD800')).toThrow(RangeError);
  expect(() => new SourceAdapter('x'.repeat(2_097_153))).toThrow(RangeError);
  const a = create('x'.repeat(2_097_152));
  expect(() => a.transact([{ start: 0, end: 0, text: 'more' }])).toThrow('2 MiB');
  expect(a.canUndo).toBe(false);
  expect(a.pending).toEqual([]);
  a.transact([{ start: 0, end: 1, text: '' }]);
  const b = new SourceAdapter(a.initial);
  adapters.push(b);
  deliver(a, b);
  b.transact([{ start: 0, end: 0, text: 'y' }]);
  deliver(b, a);
  expect(() => a.undo()).toThrow('2 MiB');
  expect(a.canUndo).toBe(true);
  expect(a.canRedo).toBe(false);
  expect(a.value.length).toBe(2_097_152);
});

test('provenance bounds protect rendering from unlimited runs and recursive anchors', () => {
  const tx = { __className: 'SourceTransaction', id: 'tx', clock: 1, inserts: [], deletes: [] };
  for (let i = 0; i < 10_001; i++) tx.inserts.push({ id: `tx:${i}`, after: null, text: 'X' });
  const wide = new SourceAdapter({ initial: '', events: [tx] });
  adapters.push(wide);
  expect(() => wide.value).toThrow('run limit');
  tx.inserts = [];
  for (let i = 0; i < 130; i++)
    tx.inserts.push({ id: `tx:${i}`, after: i === 0 ? null : { run: `tx:${i - 1}`, offset: 0 }, text: 'X' });
  const deep = new SourceAdapter({ initial: '', events: [tx] });
  adapters.push(deep);
  expect(deep.value).toBe('X'.repeat(130));
});

test('ordinary sequential typing and acknowledged undo cross former nesting boundaries', () => {
  const a = create('');
  const b = create('');
  for (let i = 0; i < 300; ++i) {
    a.transact([{ start: a.value.length, end: a.value.length, text: 'x' }]);
    deliver(a, b);
  }
  expect(a.value).toBe('x'.repeat(300));
  expect(b.value).toBe(a.value);
  a.undo();
  deliver(a, b);
  expect(b.value).toBe('x'.repeat(299));
});

test('malformed duplicated run identities cannot loop during source projection', () => {
  const tx = {
    __className: 'SourceTransaction',
    id: 'tx',
    clock: 1,
    inserts: [
      { id: 'duplicate', after: null, text: 'a' },
      { id: 'duplicate', after: null, text: 'b' },
    ],
    deletes: [],
  };
  const a = new SourceAdapter({ initial: '', events: [tx] });
  adapters.push(a);
  expect(() => a.value).toThrow('anchor cycle');
});

test('coalesces pending keystrokes into bounded journal batches', () => {
  const a = create('');
  const b = create('');
  for (let i = 0; i < 100; ++i) a.transact([{ start: i, end: i, text: 'x' }]);
  expect(a.next().operations).toHaveLength(64);
  deliver(a, b);
  expect(a.hasPending()).toBe(true);
  expect(a.next().operations).toHaveLength(36);
  deliver(a, b);
  expect(a.hasPending()).toBe(false);
  expect(b.value).toBe(a.value);
  const c = create('');
  c.transact([{ start: 0, end: 0, text: 'x'.repeat(200000) }]);
  c.transact([{ start: 0, end: 0, text: 'y'.repeat(200000) }]);
  expect(c.next().operations).toHaveLength(1);
});

test('no-op and rejected edits leave the next causal clock available', () => {
  const a = create('x'.repeat(2_097_152));
  a.transact([]);
  a.transact([{ start: 0, end: 1, text: 'x' }]);
  expect(a.clock).toBe(0);
  expect(() => a.transact([{ start: 0, end: 0, text: 'more' }])).toThrow('2 MiB');
  expect(a.clock).toBe(0);
  a.transact([{ start: 0, end: 1, text: '' }]);
  expect(a.next().operations[0].clock).toBe(1);
  a.undo();
  expect(a.clock).toBe(2);
  a.redo();
  expect(a.clock).toBe(3);
});

test('listener failures preserve committed edits, undo history and scheduling', () => {
  const schedule = vi.fn();
  const a = create('abc', schedule);
  const failure = new Error('render failed');
  const stop = a.subscribe(() => {
    throw failure;
  });
  const following = vi.fn();
  a.subscribe(following);
  expect(() => a.transact([{ start: 0, end: 0, text: 'X' }])).toThrow(failure);
  expect(a.value).toBe('Xabc');
  expect(a.hasPending()).toBe(true);
  expect(a.canUndo).toBe(true);
  expect(following).toHaveBeenCalledTimes(1);
  expect(schedule).toHaveBeenCalledTimes(1);
  expect(() => a.receive(a.next())).toThrow(failure);
  expect(a.hasPending()).toBe(false);
  expect(a.version).toBe(1);
  expect(following).toHaveBeenCalledTimes(2);
  expect(() => a.undo()).toThrow(failure);
  expect(a.value).toBe('abc');
  expect(a.canUndo).toBe(false);
  expect(a.canRedo).toBe(true);
  expect(schedule).toHaveBeenCalledTimes(2);
  stop();
  a.redo();
  expect(a.value).toBe('Xabc');
  expect(a.canUndo).toBe(true);
});

test('scheduler failures do not roll back a committed transaction', () => {
  const a = create('abc', () => {
    throw new Error('transport failed');
  });
  expect(() => a.transact([{ start: 0, end: 0, text: 'X' }])).toThrow('transport failed');
  expect(a.value).toBe('Xabc');
  expect(a.canUndo).toBe(true);
  expect(a.clock).toBe(1);
  expect(() => a.undo()).toThrow('transport failed');
  expect(a.value).toBe('abc');
  expect(a.canRedo).toBe(true);
});

test('a subscriber can synchronously undo the committed edit', () => {
  const schedule = vi.fn();
  const a = create('abc', schedule);
  let undo = true;
  a.subscribe(() => {
    if (undo) {
      undo = false;
      a.undo();
    }
  });
  a.transact([{ start: 0, end: 0, text: 'X' }]);
  expect(a.value).toBe('abc');
  expect(a.canUndo).toBe(false);
  expect(a.canRedo).toBe(true);
  expect(a.next().operations.map((event) => event.clock)).toEqual([1, 2]);
  expect(schedule).toHaveBeenCalledTimes(2);
  a.redo();
  expect(a.value).toBe('Xabc');
});

test('one replacement and undo span more than 2048 source runs atomically', () => {
  const events = Array.from({ length: 2050 }, (_, index) => {
    const id = `transaction-${String(index).padStart(16, '0')}`;
    const previous = `transaction-${String(index - 1).padStart(16, '0')}:0`;
    return {
      __className: 'SourceTransaction',
      id,
      clock: index + 1,
      inserts: [{ id: `${id}:0`, text: 'x', after: index ? { run: previous, offset: 0 } : null }],
      deletes: [],
    };
  });
  const a = new SourceAdapter({ initial: '', events });
  adapters.push(a);
  a.transact([{ start: 0, end: 2050, text: 'replacement' }]);
  expect(a.value).toBe('replacement');
  const batch = a.next();
  expect(batch.operations).toHaveLength(1);
  expect(batch.operations[0].deletes).toHaveLength(2050);
  a.receive(batch);
  a.undo();
  expect(a.value).toBe('x'.repeat(2050));
  expect(a.next().operations).toHaveLength(1);
});

test('the run bound includes the implicit initial run and fails atomically', () => {
  const id = 'transaction-0000000000000000';
  const events = [
    {
      __className: 'SourceTransaction',
      id,
      clock: 1,
      inserts: Array.from({ length: 9999 }, (_, index) => ({ id: `${id}:${index}`, text: 'x', after: null })),
      deletes: [],
    },
  ];
  const a = new SourceAdapter({ initial: '', events });
  adapters.push(a);
  expect(a.value).toBe('x'.repeat(9999));
  expect(() => a.transact([{ start: 0, end: 0, text: 'one too many' }])).toThrow('run limit');
  expect(a.value).toBe('x'.repeat(9999));
  expect(a.hasPending()).toBe(false);
  expect(a.canUndo).toBe(false);
  expect(a.clock).toBe(1);
  a.transact([{ start: 0, end: 1, text: '' }]);
  expect(a.value).toBe('x'.repeat(9998));
  expect(a.next().operations[0].clock).toBe(2);
});
