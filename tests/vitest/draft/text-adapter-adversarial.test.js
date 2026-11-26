import { SourceAdapter } from '../../../assets/draft/source-adapter.js';

const peers = [];
const create = (text) => {
  const peer = new SourceAdapter(text);
  peers.push(peer);
  return peer;
};
afterEach(() => {
  for (const peer of peers.splice(0)) peer.destroy();
});
const deliver = (sender, recipient) => {
  const batch = sender.next();
  if (!batch) return;
  const event = JSON.parse(JSON.stringify(batch));
  sender.receive(event);
  recipient.receive(event);
};
const sync = (a, b) => {
  for (let i = 0; i < 10 && (a.hasPending() || b.hasPending()); i++) {
    deliver(a, b);
    deliver(b, a);
  }
};

test('undo and redo do not resurrect a remotely deleted part of a local insertion', () => {
  const a = create('ab');
  const b = create('ab');
  a.transact([{ start: 1, end: 1, text: 'XYZ' }]);
  sync(a, b);
  b.transact([{ start: 2, end: 3, text: '' }]);
  sync(a, b);
  expect(a.value).toBe('aXZb');
  a.undo();
  sync(a, b);
  expect(a.value).toBe('ab');
  expect(b.value).toBe(a.value);
  a.redo();
  sync(a, b);
  expect(a.value).toBe('aXZb');
  expect(b.value).toBe(a.value);
});

test('undo of a remotely removed insertion is a no-op', () => {
  const a = create('abc');
  const b = create('abc');
  a.transact([{ start: 1, end: 1, text: 'X' }]);
  sync(a, b);
  b.transact([{ start: 0, end: 3, text: '' }]);
  sync(a, b);
  expect(a.value).toBe('c');
  a.undo();
  sync(a, b);
  expect(a.value).toBe('c');
  expect(b.value).toBe(a.value);
  a.redo();
  sync(a, b);
  expect(a.value).toBe('c');
  expect(b.value).toBe(a.value);
});

test('nested peer insertion survives both peers undoing and redoing', () => {
  const a = create('ab');
  const b = create('ab');
  a.transact([{ start: 1, end: 1, text: 'XYZ' }]);
  sync(a, b);
  b.transact([{ start: 2, end: 2, text: '123' }]);
  sync(a, b);
  a.undo();
  sync(a, b);
  expect(a.value).toBe('a123b');
  b.undo();
  sync(a, b);
  expect(a.value).toBe('ab');
  expect(b.value).toBe(a.value);
  b.redo();
  sync(a, b);
  expect(a.value).toBe('a123b');
  a.redo();
  sync(a, b);
  expect(a.value).toBe('aX123YZb');
  expect(b.value).toBe(a.value);
});

test('offline undo against a concurrent peer insertion converges and preserves peer text', () => {
  const a = create('abcd');
  const b = create('abcd');
  a.transact([{ start: 1, end: 3, text: 'XY' }]);
  sync(a, b);
  b.transact([{ start: 2, end: 2, text: 'remote' }]);
  a.undo();
  sync(a, b);
  expect(a.value).toBe(b.value);
  // The peer remains anchored under hidden X, before the restored original bc.
  expect(a.value).toBe('aremotebcd');
  a.redo();
  sync(a, b);
  expect(a.value).toBe('aXremoteYd');
  expect(b.value).toBe(a.value);
});

test('undo of a deletion does not resurrect characters concurrently deleted by a peer', () => {
  const a = create('abcd');
  const b = create('abcd');
  a.transact([{ start: 1, end: 3, text: '' }]);
  b.transact([{ start: 2, end: 3, text: '' }]);
  sync(a, b);
  expect(a.value).toBe('ad');
  expect(b.value).toBe(a.value);
  a.undo();
  sync(a, b);
  expect(a.value).toBe('abd');
  expect(b.value).toBe(a.value);
});
