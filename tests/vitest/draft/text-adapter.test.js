import { TextAdapter } from '../../../assets/draft/text-adapter.js';

const adapters = [];
const create = (text = 'abcd', schedule) => {
  const adapter = new TextAdapter(text, schedule);
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
