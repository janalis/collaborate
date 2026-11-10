// A host whose collections have no prototype: its server keys the entries (`collections.entry`), renders an empty
// row that is no entry until typed in, adds a peer's entry itself (`collections.create`) and removes one itself
// (`collections.remove`), replacing the whole widget as it does (Drupal's AJAX rebuilds).
import { registerBridge, resetBridges } from '../../../assets/bridge.js';
import { initializeCollections } from '../../../assets/draft/collections.js';

const hooks = { entry: vi.fn(), create: vi.fn(), remove: vi.fn() };

registerBridge({
  name: 'host',
  entries: ['tr.item'],
  collections: {
    containers: ['tbody'],
    added: null,
    changed: ['host:rebuilt'],
    position: '_weight',
    entry: (...args) => hooks.entry(...args),
    create: (...args) => hooks.create(...args),
    remove: (...args) => hooks.remove(...args),
  },
});

afterAll(() => resetBridges());

const row = (key, value = '') => `<tr class="item"><td><input name="notes[${key}][value]" value="${value}"></td></tr>`;

function widget(rows) {
  return `<div data-collaborate-collection="notes"><table><tbody>${rows.join('')}</tbody></table></div>`;
}

function fixture(rows = [row('p0', 'First'), row('t1')], members = { p0: { alive: true, order: 1 } }) {
  document.body.innerHTML = `<form>${widget(rows)}</form>`;
  const form = document.querySelector('form');
  const fields = {};
  for (const [key, value] of Object.entries(members)) {
    fields[`notes[@${key}]`] = { type: 'member', initial: value, value };
  }
  const session = {
    form,
    state: { fields },
    adapters: new Map(),
    editorWaiters: new Map(),
    initializeControls: vi.fn(),
    schedule: vi.fn(),
    setStatus: vi.fn(),
    report: vi.fn(),
    stopped: false,
    ensureField(name) {
      this.state.fields[name] ||= { type: 'member', initial: { alive: false, order: 0, recordId: null } };
      return this.state.fields[name];
    },
    register(name, adapter) {
      this.adapters.set(name, adapter);
    },
    formName(name) {
      return name;
    },
  };
  const stop = initializeCollections(session);
  return { session, form, stop };
}

/** A peer's new entry, as the draft brings it. */
function peerAdds(session, key, order = 2) {
  session.state.fields[`notes[@${key}]`] = {
    type: 'member',
    initial: { alive: false, order: 0 },
    value: { alive: true, order },
  };
  session.scanCollections();
}

const flush = () => new Promise((resolve) => setTimeout(resolve));

beforeEach(() => {
  // The server's keys are entries; an empty row (`t…`) is none until marked touched.
  hooks.entry.mockImplementation((node, key) =>
    key.startsWith('t') ? ('touched' in node.dataset ? null : false) : true
  );
  hooks.create.mockReset();
  hooks.remove.mockReset();
});

afterEach(() => {
  document.body.innerHTML = '';
});

test('adopts the keys the server names its entries by, and leaves an empty row out', () => {
  const { session, form, stop } = fixture();
  const [entry, empty] = form.querySelectorAll('tr');
  expect(entry.dataset.collaborateItem).toBe('p0');
  expect(empty.dataset.collaborateItem).toBeUndefined();
  expect('collaboratePending' in empty.dataset).toBe(true);
  expect(session.adapters.has('notes[@t1]')).toBe(false);
  // The empty row stays below the entries.
  expect(form.querySelector('tbody').lastElementChild).toBe(empty);
  stop();
});

test('makes a typed-in empty row an entry of its own', () => {
  const { session, form, stop } = fixture();
  const empty = form.querySelectorAll('tr')[1];
  empty.dataset.touched = '';
  session.scanCollections();
  const key = empty.dataset.collaborateItem;
  expect(key).toMatch(/^n/);
  expect(empty.querySelector('input').name).toBe(`notes[${key}][value]`);
  expect('collaboratePending' in empty.dataset).toBe(false);
  expect(session.adapters.get(`notes[@${key}]`).next().value).toMatchObject({ alive: true, order: 2 });
  stop();
});

test("takes a peer's entry into a row the host gives at once", () => {
  const { session, form, stop } = fixture();
  hooks.create.mockImplementation((container, _key, rekey) => {
    const empty = container.querySelector('[data-collaborate-pending]');
    rekey(empty, 't1');
    return empty;
  });
  peerAdds(session, 'nPeer');
  const rows = form.querySelectorAll('tr');
  expect(rows[1].dataset.collaborateItem).toBe('nPeer');
  expect(rows[1].querySelector('input').name).toBe('notes[nPeer][value]');
  expect('collaboratePending' in rows[1].dataset).toBe(false);
  stop();
});

test("waits for the row the host adds for a peer's entry, keeping the entry alive meanwhile", async () => {
  const { session, form, stop } = fixture([row('p0', 'First')]);
  let resolve;
  hooks.create.mockImplementation((container, _key, rekey) => {
    return new Promise((done) => {
      resolve = () => {
        // The host rebuilt its widget, with a new empty row.
        container.outerHTML = widget([row('p0', 'First'), row('t1')]);
        document.dispatchEvent(new Event('host:rebuilt'));
        const added = form.querySelector('[data-collaborate-pending]');
        rekey(added, 't1');
        done(added);
      };
    });
  });
  peerAdds(session, 'nPeer');
  // Asked once, however often the page scans meanwhile.
  session.scanCollections();
  expect(hooks.create).toHaveBeenCalledOnce();
  expect(session.adapters.get('notes[@nPeer]').next()).toBeNull();
  resolve();
  await flush();
  const rows = form.querySelectorAll('tr');
  expect(Array.from(rows, (item) => item.dataset.collaborateItem)).toEqual(['p0', 'nPeer']);
  // The rebuilt widget's entries took their keys again.
  expect(session.adapters.get('notes[@p0]').next()).toBeNull();
  stop();
});

test.each([
  ['gives no row', () => null],
  ['adds no row', () => Promise.resolve(null)],
  ['fails', () => Promise.reject(new Error('rebuild failed'))],
])("cannot follow the collection when the host %s for a peer's entry", async (_, create) => {
  const { session, stop } = fixture([row('p0', 'First')]);
  hooks.create.mockImplementation(create);
  peerAdds(session, 'nPeer');
  await flush();
  expect(session.setStatus).toHaveBeenCalledWith('incompatible');
  expect(session.report).toHaveBeenCalledWith('prototype', 'notes');
  stop();
});

test('renders nothing more once the session stopped while the host was adding a row', async () => {
  const { session, stop } = fixture([row('p0', 'First')]);
  let resolve;
  hooks.create.mockImplementation(() => new Promise((done) => (resolve = done)));
  peerAdds(session, 'nPeer');
  session.stopped = true;
  resolve(null);
  await flush();
  expect(session.setStatus).toHaveBeenCalledTimes(1);
  stop();
});

test("lets the host remove a peer's removed entry, which is no entry meanwhile", () => {
  const { session, form, stop } = fixture([row('p0', 'First'), row('p1', 'Second')], {
    p0: { alive: true, order: 1 },
    p1: { alive: true, order: 2 },
  });
  const first = form.querySelector('tr');
  session.adapters.get('notes[@p0]').receive({ id: 'peer', version: 0, value: { alive: false, order: 1 } });
  expect(hooks.remove).toHaveBeenCalledWith(first, form.querySelector('[data-collaborate-collection]'));
  // Still in the page until the host removes it, it is no entry: the page does not take it for its own again.
  expect(first.isConnected).toBe(true);
  expect('collaborateRemoved' in first.dataset).toBe(true);
  session.scanCollections();
  expect(session.adapters.get('notes[@p0]').next()).toBeNull();
  stop();
});

test("numbers the host's own position control once a peer's order moves the rows", () => {
  const weighted = (key, _weight) =>
    `<tr class="item"><td><input name="notes[${key}][value]"><select name="notes[${key}][_weight]">` +
    '<option>0</option><option>1</option><option>2</option></select></td></tr>';
  document.body.innerHTML = '';
  const { session, form, stop } = fixture([weighted('p0', 0), weighted('p1', 1)], {
    p0: { alive: true, order: 1 },
    p1: { alive: true, order: 2 },
  });
  session.adapters.get('notes[@p1]').receive({ id: 'peer', version: 0, value: { alive: true, order: 0 } });
  const rows = form.querySelectorAll('tr');
  expect(Array.from(rows, (item) => item.dataset.collaborateItem)).toEqual(['p1', 'p0']);
  expect(Array.from(form.querySelectorAll('select'), (select) => [select.name, select.value])).toEqual([
    ['notes[p1][_weight]', '1'],
    ['notes[p0][_weight]', '2'],
  ]);
  stop();
});
