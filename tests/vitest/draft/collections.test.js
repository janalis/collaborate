import { initializeCollections } from '../../../assets/draft/collections.js';
import { addRowInitializer } from '../../../assets/draft/hooks.js';

// EasyAdmin's collection widget, reduced to its delete button: it removes its row and announces it.
function bindDeleteButtons() {
  for (const button of document.querySelectorAll('button.field-collection-delete-button')) {
    if (button.dataset.bound) continue;
    button.dataset.bound = 'true';
    button.addEventListener('click', () => {
      button.closest('.field-collection-item').remove();
      document.dispatchEvent(new Event('ea.collection.item-removed'));
    });
  }
}
document.addEventListener('ea.collection.item-added', bindDeleteButtons);

function fixture(existing = true) {
  document.body.innerHTML =
    '<form><div data-collaborate-collection="Record[items]" data-form-type-name-placeholder="__name__"><div class="ea-form-collection-items"><div class="accordion"></div></div></div></form>';
  const form = document.querySelector('form');
  const container = form.firstElementChild;
  container.dataset.prototype =
    '<div class="field-collection-item"><input name="Record[items][__name__][title]"></div>';
  const fields = {};
  if (existing) {
    container.querySelector('.accordion').innerHTML =
      '<div class="field-collection-item"><input name="Record[items][i1][title]" value="existing"></div>';
    fields['Record[items][@i1]'] = {
      type: 'member',
      initial: { alive: true, order: 1, recordId: '1' },
      value: { alive: true, order: 1, recordId: '1' },
    };
  }
  const session = {
    form,
    state: { fields },
    adapters: new Map(),
    editorWaiters: new Map(),
    initializeControls: vi.fn(),
    schedule: vi.fn(),
    setStatus: vi.fn(),
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
  return { session, form, container, stop };
}

afterEach(() => {
  document.body.innerHTML = '';
});

test('preserves existing entries and applies remote removals as tombstones', () => {
  const { session, form, stop } = fixture();
  expect(session.schedule).not.toHaveBeenCalled();
  session.adapters.get('Record[items][@i1]').receive({ id: 'remote', version: 0, value: { alive: false, order: 1 } });
  expect(form.querySelector('.field-collection-item')).toBeNull();
  stop();
});

test('adopts a saved membership as a shared edit and renders it', () => {
  const { session, form, stop } = fixture();
  const adapter = session.adapters.get('Record[items][@i1]');
  adapter.adopt({ alive: false, order: 1, recordId: '1' });
  expect(form.querySelector('.field-collection-item')).toBeNull();
  expect(adapter.next()).toMatchObject({ kind: 'value', value: { alive: false }, version: 0 });
  expect(session.schedule).toHaveBeenCalled();
  stop();
});

test('ignores older membership events so replay cannot resurrect a removed entry', () => {
  const { session, form, stop } = fixture();
  const adapter = session.adapters.get('Record[items][@i1]');
  const alive = { id: 'alive', version: 0, value: { alive: true, order: 1 } };
  adapter.receive(alive);
  adapter.receive({ id: 'removed', version: 1, value: { alive: false, order: 1 } });
  adapter.receive(alive);
  expect(form.querySelector('.field-collection-item')).toBeNull();
  expect(adapter.value.alive).toBe(false);
  stop();
});

test.each([1, 3])('keeps the active row and caret attached when replay sets its order to %s', (order) => {
  const { session, form, container, stop } = fixture();
  container
    .querySelector('.accordion')
    .insertAdjacentHTML(
      'beforeend',
      '<div class="field-collection-item"><input name="Record[items][0][title]" value="other"></div>'
    );
  document.dispatchEvent(new Event('ea.collection.item-added'));
  const control = form.querySelector('input');
  control.value = 'typing here';
  control.focus();
  control.setSelectionRange(2, 5, 'backward');
  const adapter = session.adapters.get('Record[items][@i1]');
  adapter.receive({ id: 'peer', version: 0, value: { alive: true, order } });
  expect(document.activeElement).toBe(control);
  expect([control.selectionStart, control.selectionEnd, control.selectionDirection]).toEqual([2, 5, 'backward']);
  expect(form.querySelectorAll('input')[order === 1 ? 0 : 1]).toBe(control);
  expect(control.value).toBe('typing here');
  stop();
});

test('gives additions stable keys and projects remote additions without replacing sibling values', () => {
  const { session, form, container, stop } = fixture();
  container
    .querySelector('.accordion')
    .insertAdjacentHTML(
      'beforeend',
      '<div class="field-collection-item"><input name="Record[items][0][title]" value="local"></div>'
    );
  document.dispatchEvent(new Event('ea.collection.item-added'));
  const newItem = form.querySelector('.field-collection-item:last-child');
  expect(newItem.dataset.collaborateItem.startsWith('n')).toBe(true);
  expect(newItem.querySelector('input').name).toContain(newItem.dataset.collaborateItem);
  const name = `Record[items][@${newItem.dataset.collaborateItem}]`;
  const event = session.adapters.get(name).next();
  expect(event.value.alive).toBe(true);
  expect(form.querySelector('input[name="Record[items][i1][title]"]').value).toBe('existing');
  session.adapters.get(name).receive({ id: event.id, version: 0, value: event.value });
  newItem.remove();
  document.dispatchEvent(new Event('ea.collection.item-removed'));
  expect(session.adapters.get(name).next().value.alive).toBe(false);
  stop();
});

test('projects members first discovered by replay and detaches their descendant editors on removal', () => {
  const { session, form, container, stop } = fixture();
  const name = 'Record[items][@remote]';
  session.state.fields[name] = {
    type: 'member',
    initial: { alive: false, order: 0 },
    value: { alive: true, order: 2 },
  };
  document.dispatchEvent(new Event('ea.collection.item-added'));
  const node = form.querySelector('[data-collaborate-item="remote"]');
  expect(node).not.toBeNull();
  expect(node.querySelector('input').name).toBe('Record[items][remote][title]');
  expect(form.querySelector('input[name="Record[items][i1][title]"]').value).toBe('existing');
  const destroy = vi.fn();
  const resolve = vi.fn();
  session.adapters.set('Record[items][remote][title]', { destroy });
  session.editorWaiters.set('Record[items][remote][title]', { resolve });
  const editable = document.createElement('div');
  editable.className = 'ck-editor__editable';
  editable.ckeditorInstance = { destroy: vi.fn() };
  node.append(editable);
  session.adapters.get(name).receive({ id: 'delete', version: 1, value: { alive: false, order: 2 } });
  expect(destroy).toHaveBeenCalledOnce();
  expect(resolve).toHaveBeenCalledOnce();
  expect(editable.ckeditorInstance.destroy).toHaveBeenCalledOnce();
  expect(container.querySelector('[data-collaborate-item="remote"]')).toBeNull();
  stop();
});

test('initializes peer-created row controls through the collection lifecycle without echoing membership', async () => {
  const { session, form, container, stop } = fixture();
  container.setAttribute('data-ea-collection-field', '');
  container.dataset.prototype =
    '<div class="field-collection-item"><input name="Record[items][__name__][title]"><button type="button" class="field-collection-delete-button">Delete</button></div>';
  const added = vi.fn();
  document.addEventListener('ea.collection.item-added', added);
  try {
    const name = 'Record[items][@remote]';
    session.state.fields[name] = { type: 'member', value: { alive: true, order: 2 } };
    // A replay that brings field states asks for a scan, independently of the item-added event.
    session.scanCollections();
    await vi.waitFor(() => expect(added).toHaveBeenCalledOnce());
    const node = form.querySelector('[data-collaborate-item="remote"]');
    expect(added.mock.calls[0][0].detail).toEqual({ newElement: node, collection: container });
    expect(session.schedule).not.toHaveBeenCalled();
    node.querySelector('button').click();
    expect(form.querySelector('[data-collaborate-item="remote"]')).toBeNull();
    expect(session.adapters.get(name).next().value.alive).toBe(false);
    expect(form.querySelector('input').value).toBe('existing');
  } finally {
    document.removeEventListener('ea.collection.item-added', added);
    stop();
  }
});

test('notifies initializers about nested Add controls in a peer-created row', async () => {
  const { session, form, container, stop } = fixture();
  container.setAttribute('data-ea-collection-field', '');
  const prototype = document.createElement('div');
  prototype.className = 'field-collection-item';
  prototype.innerHTML =
    '<input name="Record[items][__name__][title]"><div class="field-array" data-ea-collection-field data-num-items="0" data-form-type-name-placeholder="__child__"><div class="ea-form-collection-items"></div><button type="button" class="field-collection-add-button">Add child</button></div>';
  prototype.querySelector('.field-array').dataset.prototype =
    '<div class="field-collection-item"><input name="Record[items][__name__][children][__child__][name]"></div>';
  container.dataset.prototype = prototype.outerHTML;
  const addChild = vi.fn();
  // EasyAdmin consumes this public event in the browser. The Node-only suite
  // verifies the initializer contract without requiring Composer's vendor tree.
  const initialize = vi.fn(({ detail }) => {
    detail.newElement.querySelector('.field-collection-add-button').addEventListener('click', addChild);
  });
  document.addEventListener('ea.collection.item-added', initialize);
  try {
    session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
    session.scanCollections();
    await vi.waitFor(() => expect(form.querySelector('[data-collaborate-item="remote"]')).not.toBeNull());
    const nested = form.querySelector('[data-collaborate-item="remote"] .field-array');
    nested.querySelector('button').click();
    expect(initialize).toHaveBeenCalledOnce();
    expect(addChild).toHaveBeenCalledOnce();
    expect(nested.dataset.prototype).toContain('Record[items][remote][children][__child__][name]');
  } finally {
    document.removeEventListener('ea.collection.item-added', initialize);
    stop();
  }
});

test('retains a remote row identity when collection decoration removes a redundant wrapper', async () => {
  const { session, form, container, stop } = fixture();
  container.dataset.prototype =
    '<div class="field-collection-item"><div class="accordion-item"><div class="accordion-collapse"><div class="accordion-body"><div class="field-collection-item"><input name="Record[items][__name__][title]"></div></div></div></div></div>';
  // A host's form decorator: it replaces a row that only wraps another with the inner one.
  const unwrap = ({ detail }) => {
    const inner = detail.newElement.querySelector(':scope .accordion-body > .field-collection-item');
    if (inner) detail.newElement.replaceWith(inner);
  };
  document.addEventListener('ea.collection.item-added', unwrap);
  try {
    const name = 'Record[items][@remote]';
    session.state.fields[name] = { type: 'member', value: { alive: true, order: 2 } };
    session.scanCollections();
    await vi.waitFor(() => expect(form.querySelector('input[name="Record[items][remote][title]"]')).not.toBeNull());
    const node = form.querySelector('input[name="Record[items][remote][title]"]').closest('.field-collection-item');
    expect(node.dataset.collaborateItem).toBe('remote');
    session.adapters.get(name).receive({ id: 'reorder', version: 0, value: { alive: true, order: 1 } });
    expect(form.querySelector('input[name="Record[items][remote][title]"]').closest('.field-collection-item')).toBe(
      node
    );
    expect(session.schedule).not.toHaveBeenCalled();
  } finally {
    document.removeEventListener('ea.collection.item-added', unwrap);
    stop();
  }
});

test('does not publish local reorders while projecting intermediate peer membership orders', async () => {
  const { session, form, stop } = fixture();
  try {
    session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
    session.scanCollections();
    await vi.waitFor(() => expect(session.adapters.has('Record[items][@remote]')).toBe(true));
    session.adapters.get('Record[items][@i1]').receive({
      id: 'peer-reorder',
      version: 0,
      value: { alive: true, order: 3, recordId: '1' },
    });
    await vi.waitFor(() =>
      expect(
        Array.from(form.querySelectorAll('[data-collaborate-item]'), (node) => node.dataset.collaborateItem)
      ).toEqual(['remote', 'i1'])
    );
    expect(session.schedule).not.toHaveBeenCalled();
    expect(session.adapters.get('Record[items][@i1]').next()).toBeNull();
    expect(session.adapters.get('Record[items][@remote]').next()).toBeNull();
  } finally {
    stop();
  }
});

test('projects peer rows and reorders into EasyAdmin’s existing nested insertion container', async () => {
  const { session, form, container, stop } = fixture();
  const wrapper = document.createElement('div');
  wrapper.className = 'form-widget-compound';
  wrapper.innerHTML = '<div data-empty-collection></div>';
  const insertion = wrapper.firstElementChild;
  insertion.append(form.querySelector('.field-collection-item'));
  container.querySelector('.accordion').append(wrapper);
  try {
    session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
    await vi.waitFor(() => expect(form.querySelector('[data-collaborate-item="remote"]')).not.toBeNull());
    expect(form.querySelector('[data-collaborate-item="remote"]').parentElement).toBe(insertion);
    session.adapters.get('Record[items][@i1]').receive({
      id: 'peer-reorder',
      version: 0,
      value: { alive: true, order: 3, recordId: '1' },
    });
    expect(Array.from(insertion.children, (node) => node.dataset.collaborateItem)).toEqual(['remote', 'i1']);
    expect(session.schedule).not.toHaveBeenCalled();
  } finally {
    stop();
  }
});

test.each([
  'missing',
  'empty',
  'parent',
])('handles unavailable collection prototypes without replacing existing controls: %s', (kind) => {
  const { session, container, stop } = fixture();
  if (kind === 'missing') delete container.dataset.prototype;
  if (kind === 'empty') container.dataset.prototype = '';
  if (kind === 'parent') container.querySelector('.ea-form-collection-items').remove();
  session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
  session.state.fields['Other[@entry]'] = { type: 'member', value: { alive: true, order: 1 } };
  session.state.fields['Record[title]'] = { type: 'text', value: 'unchanged' };
  document.dispatchEvent(new Event('ea.collection.item-added'));
  if (kind !== 'parent') expect(session.setStatus).toHaveBeenCalledWith('incompatible');
  stop();
});

test('rewrites nested collection names and prototypes when a new parent receives a stable key', () => {
  const { container, stop } = fixture();
  const node = document.createElement('div');
  node.className = 'field-collection-item';
  node.innerHTML =
    '<input name="Record[items][0][title]"><div data-collaborate-collection="Record[items][0][nested]"><div class="ea-form-collection-items"></div></div>';
  const nested = node.querySelector('[data-collaborate-collection]');
  nested.dataset.prototype =
    '<div class="field-collection-item"><input name="Record[items][0][nested][__name__][name]"></div>';
  container.querySelector('.accordion').append(node);
  document.dispatchEvent(new Event('ea.collection.item-added'));
  expect(nested.dataset.collaborateCollection).toBe(`Record[items][${node.dataset.collaborateItem}][nested]`);
  expect(nested.dataset.prototype).toContain(`Record[items][${node.dataset.collaborateItem}][nested][__name__][name]`);
  stop();
});

test('rescans on entry changes, not on rich-editor typing or session status updates', async () => {
  const { session, form, container, stop } = fixture();
  form.insertAdjacentHTML(
    'afterbegin',
    '<p data-collaborate-status></p><div class="ck ck-editor"><div class="ck-editor__editable"><p>a</p></div></div>'
  );
  await new Promise((resolve) => setTimeout(resolve));
  session.initializeControls.mockClear();
  form.querySelector('.ck-editor__editable p').append(document.createTextNode('b'));
  form.querySelector('[data-collaborate-status]').textContent = 'Saved';
  await new Promise((resolve) => setTimeout(resolve));
  expect(session.initializeControls).not.toHaveBeenCalled();
  // A widget redrawing its options or a validation message adds no entry and no control.
  form.insertAdjacentHTML('beforeend', '<div class="ts-dropdown"><div class="option">Lyon</div></div>');
  form.querySelector('.ts-dropdown .option').remove();
  await new Promise((resolve) => setTimeout(resolve));
  expect(session.initializeControls).not.toHaveBeenCalled();
  form.insertAdjacentHTML('beforeend', '<span><input name="Record[late]"></span>');
  await new Promise((resolve) => setTimeout(resolve));
  expect(session.initializeControls).toHaveBeenCalled();
  session.initializeControls.mockClear();
  container.querySelector('.field-collection-item').remove();
  await new Promise((resolve) => setTimeout(resolve));
  expect(session.initializeControls).toHaveBeenCalled();
  stop();
});

test('projects a member a replay brought when the session asks for a scan', () => {
  const { session, form, stop } = fixture();
  session.state.fields['Record[items][@peer]'] = {
    type: 'member',
    initial: { alive: false, order: 0 },
    value: { alive: true, order: 2 },
  };
  session.scanCollections();
  expect(form.querySelector('[data-collaborate-item="peer"]')).not.toBeNull();
  stop();
  expect(session.scanCollections).toBeNull();
});

function positioned(positions, values = {}) {
  document.body.innerHTML =
    '<form><div data-collaborate-collection="Record[items]"><div class="ea-form-collection-items"><div class="accordion"></div></div></div></form>';
  const form = document.querySelector('form');
  const container = form.firstElementChild;
  container.dataset.prototype =
    '<div class="field-collection-item"><input name="Record[items][__name__][title]"></div>';
  const fields = {};
  container.querySelector('.accordion').innerHTML = Object.entries(positions)
    .map(
      ([key, position]) =>
        `<div class="field-collection-item"><input name="Record[items][${key}][title]"><input type="hidden" name="Record[items][${key}][position]" value="${position}"></div>`
    )
    .join('');
  Object.keys(positions).forEach((key, index) => {
    const initial = { alive: true, order: index + 1, recordId: key };
    fields[`Record[items][@${key}]`] = { type: 'member', initial, value: values[key] || initial };
  });
  const session = {
    form,
    state: { fields },
    adapters: new Map(),
    editorWaiters: new Map(),
    initializeControls: vi.fn(),
    schedule: vi.fn(),
    setStatus: vi.fn(),
    ensureField(name) {
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
  const posted = () =>
    Object.fromEntries(Array.from(new FormData(form)).filter(([name]) => name.endsWith('[position]')));
  return { session, stop, posted };
}

test('leaves the posted positions alone while the rows keep their order', () => {
  const { stop, posted } = positioned({ a: 0, b: 1 });
  expect(posted()).toEqual({ 'Record[items][a][position]': '0', 'Record[items][b][position]': '1' });
  stop();
});

test('posts the positions of an order a peer sent, not the order the page loaded', () => {
  const { session, stop, posted } = positioned({ a: 1, b: 2 });
  session.adapters
    .get('Record[items][@b]')
    .receive({ id: 'up', version: 0, value: { alive: true, order: 0, recordId: 'b' } });
  expect(posted()).toEqual({ 'Record[items][b][position]': '1', 'Record[items][a][position]': '2' });
  stop();
});

test('posts the positions of the draft order a reopened page restores', () => {
  const { stop, posted } = positioned({ a: 1, b: 2 }, { b: { alive: true, order: 0, recordId: 'b' } });
  expect(posted()).toEqual({ 'Record[items][b][position]': '1', 'Record[items][a][position]': '2' });
  stop();
});

test('runs the host row initializers on the decorated row a peer added, and on no row the page already had', async () => {
  const initialize = vi.fn();
  const remove = addRowInitializer(initialize);
  const { session, form, container, stop } = fixture();
  container.dataset.prototype =
    '<div class="field-collection-item"><div class="accordion-item"><div class="accordion-collapse"><div class="accordion-body"><div class="field-collection-item"><input name="Record[items][__name__][title]"></div></div></div></div></div>';
  const unwrap = ({ detail }) => {
    const inner = detail.newElement.querySelector(':scope .accordion-body > .field-collection-item');
    if (inner) detail.newElement.replaceWith(inner);
  };
  document.addEventListener('ea.collection.item-added', unwrap);
  try {
    expect(initialize).not.toHaveBeenCalled();
    session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
    session.scanCollections();
    await vi.waitFor(() => expect(initialize).toHaveBeenCalledOnce());
    const row = form.querySelector('[data-collaborate-item="remote"]');
    expect(initialize).toHaveBeenCalledWith(row);
    expect(row.querySelector('.field-collection-item')).toBeNull();
  } finally {
    document.removeEventListener('ea.collection.item-added', unwrap);
    remove();
    stop();
  }
});

test('names a peer-added row from the default placeholder when the collection names none', () => {
  const { session, form, container, stop } = fixture();
  container.removeAttribute('data-form-type-name-placeholder');
  session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
  session.scanCollections();
  expect(form.querySelector('[data-collaborate-item="remote"] input').name).toBe('Record[items][remote][title]');
  stop();
});

test('adds no row for a peer entry when the prototype renders no element', () => {
  const { session, form, container, stop } = fixture();
  container.dataset.prototype = 'text only';
  session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
  session.scanCollections();
  expect(form.querySelectorAll('.field-collection-item')).toHaveLength(1);
  expect(container.querySelector('.accordion').textContent).toBe('');
  expect(session.setStatus).not.toHaveBeenCalled();
  stop();
});

test('initializes the row a peer added when a host listener takes it out of the collection', () => {
  const initialize = vi.fn();
  const remove = addRowInitializer(initialize);
  const { session, form, stop } = fixture();
  const detach = ({ detail }) => detail.newElement.remove();
  document.addEventListener('ea.collection.item-added', detach);
  try {
    session.state.fields['Record[items][@remote]'] = { type: 'member', value: { alive: true, order: 2 } };
    session.scanCollections();
    expect(initialize).toHaveBeenCalledOnce();
    const row = initialize.mock.calls[0][0];
    expect(row.dataset.collaborateItem).toBe('remote');
    expect(row.isConnected).toBe(false);
    expect(form.querySelectorAll('.field-collection-item')).toHaveLength(1);
  } finally {
    document.removeEventListener('ea.collection.item-added', detach);
    remove();
    stop();
  }
});

test('neither registers nor sends a row added locally whose membership the grant does not define', () => {
  const { session, stop } = positioned({ a: 1 });
  session.register = vi.fn(session.register);
  const accordion = document.querySelector('.accordion');
  accordion.insertAdjacentHTML(
    'beforeend',
    '<div class="field-collection-item"><input name="Record[items][0][title]"></div><div class="field-collection-item"><span>No control</span></div>'
  );
  document.dispatchEvent(new Event('ea.collection.item-added'));
  const [, added, keyless] = accordion.children;
  // The added row takes a stable key all the same; one without a named control names no entry.
  expect(added.dataset.collaborateItem).toMatch(/^n/);
  expect(keyless.dataset.collaborateItem).toBeUndefined();
  expect(session.register).not.toHaveBeenCalled();
  expect(session.schedule).not.toHaveBeenCalled();
  expect(session.adapters.get('Record[items][@a]').next()).toBeNull();
  stop();
});

test('keeps an unsent local reorder over a peer order heard before it is sent', () => {
  const { session, stop } = positioned({ a: 1, b: 2 });
  const accordion = document.querySelector('.accordion');
  // The editor drags the second row first: both memberships wait to be sent.
  accordion.prepend(accordion.lastElementChild);
  document.dispatchEvent(new Event('ea.collection.item-added'));
  const a = session.adapters.get('Record[items][@a]');
  expect(a.next().value.order).toBe(2);
  a.receive({ id: 'peer', version: 0, value: { alive: true, order: 1, recordId: 'a' } });
  expect(a.value.order).toBe(2);
  expect(a.next().value.order).toBe(2);
  expect(Array.from(accordion.children, (row) => row.dataset.collaborateItem)).toEqual(['b', 'a']);
  stop();
});

test('projects the memberships a reopened page replays only once every member has registered', () => {
  document.body.innerHTML =
    '<form><div data-collaborate-collection="Record[items]"><div class="ea-form-collection-items"><div class="accordion"><div class="field-collection-item"><input name="Record[items][a][title]" value="a"></div><div class="field-collection-item"><input name="Record[items][b][title]" value="b"></div></div></div></div></form>';
  const form = document.querySelector('form');
  const member = (order) => ({ type: 'member', initial: { alive: true, order }, value: { alive: true, order } });
  // The journal moved `b` first; replaying `a`'s event alone would render it before `b` is a member.
  const history = new Map([['Record[items][@a]', [{ id: 'move', version: 0, value: { alive: true, order: 2 } }]]]);
  const session = {
    form,
    state: { fields: { 'Record[items][@a]': member(1), 'Record[items][@b]': member(1) } },
    adapters: new Map(),
    editorWaiters: new Map(),
    initializeControls: vi.fn(),
    schedule: vi.fn(),
    setStatus: vi.fn(),
    ensureField(name) {
      return this.state.fields[name];
    },
    register(name, adapter) {
      this.adapters.set(name, adapter);
      for (const event of history.get(name) || []) adapter.receive(event);
    },
    formName: (name) => name,
  };
  const stop = initializeCollections(session);
  expect(session.adapters.get('Record[items][@a]').value.order).toBe(2);
  expect(Array.from(form.querySelectorAll('input'), (input) => input.value)).toEqual(['b', 'a']);
  expect(session.schedule).not.toHaveBeenCalled();
  stop();
});

test('leaves in place a row the page loaded without a named control', () => {
  const { session, stop } = positioned({ a: 1 });
  stop();
  const accordion = document.querySelector('.accordion');
  accordion.insertAdjacentHTML('afterbegin', '<div class="field-collection-item"><span>Header</span></div>');
  const restart = initializeCollections({ ...session, adapters: new Map() });
  const [keyless, row] = accordion.children;
  expect(keyless.isConnected).toBe(true);
  expect(keyless.dataset.collaborateItem).toBeUndefined();
  expect(row.dataset.collaborateItem).toBe('a');
  restart();
});
