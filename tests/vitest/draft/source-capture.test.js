import { SourceAdapter } from '../../../assets/draft/source-adapter.js';

const adapters = [];
const create = (text = 'abcd') => {
  const adapter = new SourceAdapter(text);
  adapters.push(adapter);
  return adapter;
};
const deliver = (sender, receiver) => {
  const event = JSON.parse(JSON.stringify(sender.next()));
  sender.receive(event);
  receiver.receive(event);
};
afterEach(() => {
  for (const adapter of adapters.splice(0)) adapter.destroy();
});

test('captured replacement preserves peer insertion inside the replaced text', () => {
  const a = create();
  const b = create();
  const capture = a.capture();
  b.transact([{ start: 2, end: 2, text: 'REMOTE' }]);
  deliver(b, a);
  capture.transact([{ start: 1, end: 3, text: '漢' }]);
  deliver(a, b);
  expect(a.value).toContain('REMOTE');
  expect(a.value).toContain('漢');
  expect(a.value).not.toContain('bc');
  expect(a.value).toBe(b.value);
  expect(capture.value).toBe('abcd');
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('abREMOTEcd');
  expect(a.value).toBe(b.value);
});

test('captured end replacement preserves peer tail insertion', () => {
  const a = create();
  const b = create();
  const capture = a.capture();
  b.transact([{ start: 4, end: 4, text: 'TAIL' }]);
  deliver(b, a);
  capture.transact([{ start: 3, end: 4, text: '漢' }]);
  deliver(a, b);
  expect(a.value).toContain('TAIL');
  expect(a.value).toContain('漢');
  expect(a.value).toBe(b.value);
  a.undo();
  deliver(a, b);
  expect(a.value).toBe('abcdTAIL');
});

test('captured ranges resolve against live source while validating original boundaries', () => {
  const a = create('a😀bc');
  const b = create('a😀bc');
  const capture = a.capture();
  b.transact([{ start: 0, end: 0, text: 'REMOTE' }]);
  deliver(b, a);
  const range = capture.track(1, 3);
  expect([range.start, range.end]).toEqual([7, 9]);
  expect(() => capture.track(2, 2)).toThrow(RangeError);
  expect(() => capture.transact([{ start: 2, end: 2, text: 'invalid' }])).toThrow(RangeError);
  range.detach();
  a.destroy();
  expect(() => capture.transact([])).toThrow('disposed');
  expect(() => capture.track(0, 0)).toThrow('disposed');
});

test('captures include unacknowledged local provenance', () => {
  const a = create();
  a.transact([{ start: 1, end: 1, text: 'LOCAL' }]);
  const capture = a.capture();
  capture.transact([{ start: 1, end: 6, text: '漢' }]);
  expect(a.value).toBe('a漢bcd');
  a.undo();
  expect(a.value).toBe('aLOCALbcd');
});
