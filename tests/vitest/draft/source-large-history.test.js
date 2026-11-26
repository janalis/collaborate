import { SourceAdapter } from '../../../assets/draft/source-adapter.js';

test('opens a bounded toggle-heavy journal without exceeding JavaScript argument limits', () => {
  const id = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
  const events = [
    {
      __className: 'SourceTransaction',
      id,
      clock: 1,
      actor: 1,
      baseVersion: 0,
      inserts: [{ id: `${id}:0`, after: null, text: 'x' }],
      deletes: [],
    },
  ];
  for (let index = 0; index < 130_000; index++)
    events.push({
      __className: 'SourceToggle',
      id: `toggle-${String(index).padStart(16, '0')}`,
      target: id,
      clock: index + 2,
      actor: 1,
      baseVersion: index + 1,
      active: index % 2 === 0,
    });
  expect(new TextEncoder().encode(JSON.stringify(events)).length).toBeLessThan(33_554_432);
  const source = new SourceAdapter({ initial: '', events });
  expect(source.clock).toBe(130_001);
  expect(source.version).toBe(130_001);
  source.destroy();
});

test('fragmented Unicode source indexes each full run only once per projection', () => {
  const initial = '😀'.repeat(250_000);
  const events = Array.from({ length: 1000 }, (_, index) => {
    const id = `transaction-${String(index).padStart(16, '0')}`;
    return {
      __className: 'SourceTransaction',
      id,
      clock: index + 1,
      inserts: [{ id: `${id}:0`, text: 'x', after: { run: 'initial', offset: index * 100 } }],
      deletes: [],
    };
  });
  const source = new SourceAdapter({ initial, events });
  const expansion = vi.spyOn(Array, 'from');
  try {
    const value = source.value;
    expect(value).toBe(`😀x${'😀'.repeat(100).concat('x').repeat(999)}${'😀'.repeat(150_099)}`);
    // Count full-run expansions rather than asserting a hardware-dependent duration.
    expect(expansion.mock.calls.filter(([text]) => text === initial)).toHaveLength(1);
  } finally {
    expansion.mockRestore();
    source.destroy();
  }
});
