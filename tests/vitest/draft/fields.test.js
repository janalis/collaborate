// EasyAdmin's markup: its bridge's profile.
import '../../../assets/bridges/easyadmin.js';
import { initializeText, initializeValue } from '../../../assets/draft/fields.js';

const sessions = [];
function setup(html, initial, text = false) {
  document.body.innerHTML = `<form>${html}</form>`;
  const form = document.querySelector('form');
  const session = { removers: [], schedule: vi.fn(), register: vi.fn() };
  sessions.push(session);
  const control = form.elements.namedItem('field');
  (text ? initializeText : initializeValue)(session, control, 'field', { initial });
  return { control, session, adapter: session.register.mock.calls[0][1] };
}
afterEach(() => {
  for (const session of sessions.splice(0)) for (const remove of session.removers) remove();
  document.body.innerHTML = '';
});

test.each([
  ['<input type="checkbox" name="field">', false, true],
  ['<input type="checkbox" name="field" value="a">', [], ['a']],
  ['<input type="radio" name="field" value="a"><input type="radio" name="field" value="b">', 'a', 'b'],
  ['<input type="checkbox" name="field" value="a"><input type="checkbox" name="field" value="b">', ['a'], ['b']],
  ['<select multiple name="field"><option value="a">A</option><option value="b">B</option></select>', ['a'], ['b']],
])('synchronizes native choices without echoing their rendered values: %s', (html, initial, remote) => {
  const { control, adapter, session } = setup(html, initial);
  adapter.receive({ id: 'remote', version: 0, value: remote });
  const first = control instanceof RadioNodeList ? control[0] : control;
  first.dispatchEvent(new Event('change'));
  expect(adapter.next()).toBeNull();
  session.applying = true;
  first.dispatchEvent(new Event('change'));
  expect(session.schedule).not.toHaveBeenCalled();
  expect(adapter.next()).toBeNull();
});

test('announces a rendered peer value with collaborate:applied only, and not a value it keeps unsent', () => {
  const { control, adapter } = setup(
    '<select name="field"><option value="a">A</option><option value="b">B</option><option value="c">C</option></select>',
    'a'
  );
  const applied = vi.fn();
  const changed = vi.fn();
  document.addEventListener('collaborate:applied', applied);
  control.addEventListener('change', changed);
  control.addEventListener('input', changed);
  try {
    expect(applied).not.toHaveBeenCalled();
    adapter.receive({ id: 'peer', version: 0, value: 'b' });
    expect(control.value).toBe('b');
    expect(applied).toHaveBeenCalledOnce();
    expect(applied.mock.calls[0][0].target).toBe(control);
    expect(changed).not.toHaveBeenCalled();
    expect(adapter.next()).toBeNull();

    control.value = 'c';
    control.dispatchEvent(new Event('change'));
    changed.mockClear();
    adapter.receive({ id: 'peer-2', version: 1, value: 'a' });
    expect(control.value).toBe('c');
    expect(applied).toHaveBeenCalledOnce();
  } finally {
    document.removeEventListener('collaborate:applied', applied);
  }
});

test('adds an unknown remote association to the native select and its TomSelect options, under its label', () => {
  const { control, adapter } = setup('<select name="field"><option value="">None</option></select>', '');
  control.tomselect = {
    addOption: vi.fn(),
    setValue: vi.fn(),
    settings: { valueField: 'entityId', labelField: 'entityAsString' },
  };
  adapter.receive({ id: 'remote', version: 0, value: '42', labels: { 42: 'Widget' } });
  expect(control.options[1].textContent).toBe('Widget');
  expect(control.tomselect.addOption).toHaveBeenCalledWith({ entityId: '42', entityAsString: 'Widget' });
  expect(control.tomselect.setValue).toHaveBeenCalledWith('42', true);
});

test('names an unknown remote choice by its id when the event has no label for it', () => {
  const { control, adapter } = setup('<select multiple name="field"></select>', []);
  control.tomselect = { addOption: vi.fn(), setValue: vi.fn() };
  adapter.receive({ id: 'remote', version: 0, value: ['42', '7'], labels: { 7: 'Gadget' } });
  expect(Array.from(control.options, (option) => option.textContent)).toEqual(['42', 'Gadget']);
  expect(control.tomselect.addOption).toHaveBeenCalledWith({ value: '42', text: '42' });
});

test('sends the labels of the selected choices, bounded, and none for other controls', () => {
  const long = '😀'.repeat(300);
  const { control, adapter } = setup(
    `<select multiple name="field"><option value="">None</option><option value="42">Widget</option><option value="7">${long}</option></select>`,
    []
  );
  for (const option of control.options) option.selected = true;
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).toMatchObject({ value: ['', '42', '7'], labels: { 42: 'Widget', 7: '😀'.repeat(255) } });
  adapter.adopt(['42']);
  expect(adapter.next()).toMatchObject({ value: ['42'], labels: { 42: 'Widget' } });
  for (const option of control.options) option.selected = false;
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).not.toHaveProperty('labels');
  const text = setup('<input name="field">', 'a');
  text.control.value = 'b';
  text.control.dispatchEvent(new Event('input'));
  expect(text.adapter.next()).not.toHaveProperty('labels');
});

test.each([
  'number',
  'date',
  'time',
  'range',
])('retains a local %s input before blur when a peer value arrives', (type) => {
  const values = {
    number: ['10', '123', '20'],
    date: ['2026-01-01', '2026-02-02', '2026-03-03'],
    time: ['10:00', '12:30', '14:00'],
    range: ['10', '30', '20'],
  };
  const [initial, local, remote] = values[type];
  const { control, adapter, session } = setup(`<input type="${type}" name="field">`, initial);
  control.focus();
  control.value = local;
  control.dispatchEvent(new Event('input'));
  const pending = adapter.next();
  expect(pending).toMatchObject({ value: local, version: 0 });
  adapter.receive({ id: 'peer', version: 0, value: remote });
  expect(control.value).toBe(local);
  expect(adapter.next()).toMatchObject({ id: pending.id, value: local, version: 1 });
  control.dispatchEvent(new Event('change'));
  expect(adapter.next().id).toBe(pending.id);
  expect(session.schedule).toHaveBeenCalledOnce();
  adapter.receive({ ...adapter.next(), version: 1 });
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).toBeNull();
});

test('deduplicates input and change on multiple choices and removes both listeners', () => {
  const { control, adapter, session } = setup(
    '<select multiple name="field"><option>a</option><option>b</option></select>',
    ['a']
  );
  control.options[1].selected = true;
  control.dispatchEvent(new Event('input'));
  const update = adapter.next();
  expect(update.value).toEqual(['a', 'b']);
  control.dispatchEvent(new Event('change'));
  expect(adapter.next().id).toBe(update.id);
  adapter.receive({ ...update, version: 0 });
  adapter.receive({ id: 'remote', version: 1, value: ['b'] });
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).toBeNull();
  for (const remove of session.removers) remove();
  control.options[0].selected = true;
  control.dispatchEvent(new Event('input'));
  control.dispatchEvent(new Event('change'));
  expect(session.schedule).toHaveBeenCalledOnce();
});

test('turns replacement and deletion into model operations and ignores duplicate native input events', () => {
  const { control, adapter, session } = setup('<input name="field">', 'a😀b', true);
  control.value = 'a😁b';
  control.dispatchEvent(new Event('input'));
  const update = adapter.next();
  expect(update.operations.map((operation) => operation.__className)).toEqual(['MoveOperation', 'InsertOperation']);
  const calls = session.schedule.mock.calls.length;
  control.dispatchEvent(new Event('change'));
  expect(session.schedule).toHaveBeenCalledTimes(calls);
  adapter.receive(update);
  expect(control.value).toBe('a😁b');
});

test("without a bridge, a field EasyAdmin's slug widget would write is a plain field", () => {
  const { control, adapter } = setup(
    '<div data-collaborate-layer data-config=\'{"bridge":"none"}\'></div><input name="name"><input name="field" data-ea-slug-field="">',
    'a',
    true
  );
  // EasyAdmin's widget would have written the slug: under no bridge, the form's input is not this field's edit.
  control.value = 'b';
  control.form.elements.namedItem('name').dispatchEvent(new Event('input', { bubbles: true }));
  expect(adapter.next()).toBeNull();
  control.dispatchEvent(new Event('input'));
  expect(adapter.next()).not.toBeNull();
});

test("shares the slug EasyAdmin's widget writes for a local edit, and keeps the shared slug through a peer's edit", () => {
  const { control, adapter, session } = setup(
    '<input name="name"><input name="field" data-ea-slug-field=""><button type="button">Lock</button>',
    'manual-a',
    true
  );
  const name = control.form.elements.namedItem('name');
  // EasyAdmin's locked widget rewrites its field on every input of its target, and on locking, without an event.
  name.addEventListener('input', () => {
    control.value = name.value.toLowerCase();
  });
  const lock = control.form.querySelector('button');
  lock.addEventListener('click', () => {
    control.value = 'locked';
  });
  name.value = 'Beta';
  name.dispatchEvent(new Event('input', { bubbles: true }));
  const typed = adapter.next();
  expect(typed).not.toBeNull();
  adapter.receive(typed);
  expect(control.value).toBe('beta');
  // A peer's edit of the target is applied with an input event: its own page shares the slug it computed.
  session.applying = true;
  name.value = 'Gamma';
  name.dispatchEvent(new Event('input', { bubbles: true }));
  session.applying = false;
  expect(control.value).toBe('beta');
  expect(adapter.next()).toBeNull();
  lock.click();
  expect(adapter.next()).not.toBeNull();
});

test('does not echo the value Tagify re-serializes while it loads a remote text value', () => {
  const peer = setup('<input name="field">', 'a', true);
  peer.control.value = 'a,b';
  peer.control.dispatchEvent(new Event('input'));
  const update = peer.adapter.next();
  const { control, adapter } = setup('<input name="field">', 'a', true);
  // Tagify rewrites its original input and dispatches change while loading values.
  control.__tagify = {
    loadOriginalValues: (value) => {
      control.value = `${value} `;
      control.dispatchEvent(new Event('change'));
    },
  };
  adapter.receive(update);
  expect(adapter.next()).toBeNull();
});

test('preserves the array shape when the only expanded multiple-choice checkbox is cleared', () => {
  const { control, adapter } = setup('<input type="checkbox" name="field" value="a">', ['a']);
  expect(control.checked).toBe(true);
  control.checked = false;
  control.dispatchEvent(new Event('change'));
  expect(adapter.next().value).toEqual([]);
});

test('ignores duplicate and older value events without losing a pending choice or regressing its version', () => {
  const { control, adapter } = setup(
    '<select name="field"><option>a</option><option>b</option><option>c</option></select>',
    'a'
  );
  const first = { id: 'first', version: 0, value: 'b' };
  adapter.receive(first);
  adapter.receive({ id: 'second', version: 1, value: 'c' });
  adapter.receive(first);
  expect(control.value).toBe('c');
  control.value = 'a';
  control.dispatchEvent(new Event('change'));
  adapter.receive(first);
  expect(adapter.next()).toMatchObject({ version: 2, value: 'a' });
});

test('bounds the labels it sends to the length the form configures', () => {
  const { control, adapter, session } = setup(
    '<select name="field"><option value="42">Configured label</option></select>',
    ''
  );
  session.configuration = { config: { labelLength: 10 } };
  control.value = '42';
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).toMatchObject({ value: '42', labels: { 42: 'Configured' } });
});

test('turns a pure deletion into a removal alone', () => {
  const { control, adapter } = setup('<input name="field">', 'abcd', true);
  control.value = 'ad';
  control.dispatchEvent(new Event('input'));
  const update = adapter.next();
  expect(update.operations.map((operation) => operation.__className)).toEqual(['MoveOperation']);
  adapter.receive(update);
  expect(control.value).toBe('ad');
});

test('empties a plain control a peer cleared to no value', () => {
  const { control, adapter } = setup('<input name="field">', 'kept');
  expect(control.value).toBe('kept');
  adapter.receive({ id: 'peer', version: 0, value: null });
  expect(control.value).toBe('');
  control.dispatchEvent(new Event('change'));
  expect(adapter.next()).toBeNull();
});
