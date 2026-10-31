import { initUpdates } from '../../assets/updates.js';

afterEach(() => {
  vi.useRealTimers();
  document.body.innerHTML = '';
  document.body.className = '';
  sessionStorage.clear();
  vi.restoreAllMocks();
});

/** The page's hub: members join it and leave it; transport is `hub.test.js`'s. */
function fakeHub() {
  const hub = {
    members: [],
    leaves: 0,
    join: vi.fn((member) => {
      hub.members.push(member);
      return () => {
        hub.leaves++;
        hub.members = hub.members.filter((joined) => joined !== member);
      };
    }),
  };
  return hub;
}

function start(html = '', reload = vi.fn(), hub = fakeHub()) {
  document.body.innerHTML = `<div data-collaborate-layer data-updates="true"></div>${html}`;
  const updates = initUpdates(document.querySelector('[data-collaborate-layer]'), hub, reload);
  return { updates, hub, reload };
}

test('does nothing while the feature is disabled or the page has no hub', () => {
  expect(initUpdates(null)).toBeNull();
  document.body.innerHTML = '<div data-collaborate-layer></div>';
  expect(initUpdates(document.querySelector('[data-collaborate-layer]'), fakeHub())).toBeNull();
  document.body.innerHTML = '<div data-collaborate-layer data-updates="true"></div>';
  expect(initUpdates(document.querySelector('[data-collaborate-layer]'), null)).toBeNull();
});

test('follows the saved-views channel, leaves it on a cached navigation and joins it again on return', () => {
  const { updates, hub } = start();
  expect(hub.join).toHaveBeenCalledOnce();
  expect(hub.members[0].channel).toBe('updates');
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  expect(hub.leaves).toBe(1);
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  expect(hub.join).toHaveBeenCalledTimes(2);
  updates.stop();
  expect(hub.members).toEqual([]);
});

test('refreshes embedded lists and defers active interactions', async () => {
  vi.useFakeTimers();
  const { updates } = start(
    '<form data-collaborate><div class="field-embedded-list" data-src="/list" data-loaded="true"><input></div></form>'
  );
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  document.querySelector('input').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  document.querySelector('input').blur();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('permanent navigation tears down invalidations and persisted pageshow cannot revive them', async () => {
  vi.useFakeTimers();
  const { updates, hub, reload } = start('', vi.fn());
  document.body.className = 'ea-index';
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false }));
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(hub.join).toHaveBeenCalledOnce();
  expect(hub.members).toEqual([]);
  expect(reload).not.toHaveBeenCalled();
});

test('refreshes read views after filters lose focus and preserves only batch selection', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-index';
  const { updates, reload } = start(
    '<input id="query"><input type="checkbox" class="form-batch-checkbox" value="42" checked><input type="checkbox" name="enabled" value="1" checked>'
  );
  document.getElementById('query').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  document.getElementById('query').blur();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  const state = JSON.parse(sessionStorage.getItem('collaborate-update:/'));
  expect(state.selected).toEqual(['42']);
  updates.stop();
});

test('stops when the hub refuses the page, without affecting ordinary pages', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-index';
  const { updates, hub, reload } = start();
  hub.members[0].denied();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  expect(hub.join).toHaveBeenCalledOnce();
});

test.each(['focusRow', 'focusId'])('restores a read view snapshot including batch selection and %s', async (focus) => {
  vi.useFakeTimers();
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  sessionStorage.setItem(
    'collaborate-update:/',
    JSON.stringify({ x: 1, y: 2, selected: ['42'], [focus]: focus === 'focusRow' ? '42' : 'query' })
  );
  const { updates } = start(
    '<input id="query"><input class="form-batch-checkbox" type="checkbox" value="42"><input class="form-batch-checkbox" type="checkbox" value="43">'
  );
  await vi.advanceTimersByTimeAsync(20);
  expect(window.scrollTo).toHaveBeenCalledWith(1, 2);
  expect(document.querySelector('[value="42"]').checked).toBe(true);
  expect(document.querySelector('[value="43"]').checked).toBe(false);
  expect(document.activeElement).toBe(
    focus === 'focusRow' ? document.querySelector('[value="42"]') : document.getElementById('query')
  );
  updates.stop();
});

test('leaves an active record form to its draft topic and keeps deferred embedded-list listeners bounded', async () => {
  vi.useFakeTimers();
  const { updates, reload: pageReload } = start(
    '<form data-collaborate><input name="field"><div class="field-embedded-list" data-src="/list" data-loaded="true"><input id="a"><input id="b"></div></form>'
  );
  const list = document.querySelector('.field-embedded-list');
  // EasyAdmin answers a reload once the list has loaded again.
  const reload = vi.fn(() =>
    document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }))
  );
  list.addEventListener('ea.embedded-list.reload', reload);
  document.getElementById('a').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  document.getElementById('b').focus();
  await vi.advanceTimersByTimeAsync(0);
  expect(reload).not.toHaveBeenCalled();
  document.getElementById('b').blur();
  await vi.advanceTimersByTimeAsync(0);
  expect(reload).toHaveBeenCalledOnce();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledTimes(2);
  document.getElementById('a').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  // The edit page itself is not reloaded: its draft topic announces its own record.
  expect(pageReload).not.toHaveBeenCalled();
  updates.stop();
});

test('keeps a deferred embedded list while focus moves between its controls', async () => {
  vi.useFakeTimers();
  const { updates } = start(
    '<form data-collaborate><div class="field-embedded-list" data-src="/list" data-loaded="true"><input id="a"><input id="b"></div><input id="outside"></form>'
  );
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  document.getElementById('a').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  // A browser runs focusout's microtasks before focus lands on the next control: the body is active meanwhile.
  vi.spyOn(document, 'activeElement', 'get').mockReturnValue(document.body);
  const leave = (target, relatedTarget) =>
    target.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget }));
  leave(document.getElementById('a'), document.getElementById('b'));
  await vi.advanceTimersByTimeAsync(0);
  expect(reload).not.toHaveBeenCalled();
  leave(document.getElementById('b'), document.getElementById('outside'));
  await vi.advanceTimersByTimeAsync(0);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('lets an embedded list finish loading before it refreshes for a save heard meanwhile', async () => {
  vi.useFakeTimers();
  const { updates } = start('<form data-collaborate><div class="field-embedded-list" data-src="/list"></div></form>');
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  const loaded = () => {
    list.dataset.loaded = 'true';
    document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }));
  };
  // EasyAdmin aborts a pending load for a reload: the first load is left to finish.
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  loaded();
  expect(reload).toHaveBeenCalledOnce();
  // So is the reload it then sent.
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  loaded();
  expect(reload).toHaveBeenCalledTimes(2);
  loaded();
  expect(reload).toHaveBeenCalledTimes(2);
  // A reload that never answered does not hold the list for good.
  await vi.advanceTimersByTimeAsync(30_000);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledTimes(3);
  updates.stop();
  updates.changed();
  loaded();
  expect(reload).toHaveBeenCalledTimes(3);
});

test("lets an embedded list finish the user's own page change before it refreshes", async () => {
  vi.useFakeTimers();
  const { updates } = start(
    '<form data-collaborate><div class="field-embedded-list" data-src="/list" data-loaded="true"><a href="/list?page=2" data-ea-fragment-bound="true">2</a><span>1</span></div></form>'
  );
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  // EasyAdmin loads the page in place; a clicked link keeps no focus in Safari.
  const link = list.querySelector('a');
  link.addEventListener('click', (event) => event.preventDefault());
  link.click();
  expect(list.contains(document.activeElement)).toBe(false);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }));
  expect(reload).toHaveBeenCalledOnce();
  // Neither a link the list does not load in place nor a click outside any list holds it.
  document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }));
  list.querySelector('span').click();
  document.body.click();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledTimes(2);
  // A page change started while a save waits on that reload holds the list from its own start.
  updates.changed();
  await vi.advanceTimersByTimeAsync(20_000);
  link.click();
  await vi.advanceTimersByTimeAsync(10_000);
  expect(reload).toHaveBeenCalledTimes(2);
  await vi.advanceTimersByTimeAsync(20_000);
  expect(reload).toHaveBeenCalledTimes(3);
  updates.stop();
});

test('reloads an embedded list again once a reload that failed no longer holds it', async () => {
  vi.useFakeTimers();
  const { updates } = start(
    '<form data-collaborate><div class="field-embedded-list" data-src="/list" data-loaded="true"></div></form>'
  );
  const list = document.querySelector('.field-embedded-list');
  // A form inside a form is dropped by the HTML parser, not by the DOM.
  const search = document.createElement('form');
  search.dataset.eaFragmentBound = 'true';
  list.append(search);
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  // The reload fails: EasyAdmin announces nothing. A save heard meanwhile waits for it, then reloads.
  await vi.advanceTimersByTimeAsync(10_000);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(19_000);
  expect(reload).toHaveBeenCalledTimes(2);
  // A search submitted in the list holds it as its own load does, and a cached navigation drops the wait.
  search.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }));
  search.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  await vi.advanceTimersByTimeAsync(60_000);
  expect(reload).toHaveBeenCalledTimes(2);
  updates.stop();
});

test('reloads an embedded list whose first load never landed', async () => {
  vi.useFakeTimers();
  const { updates } = start('<form data-collaborate><div class="field-embedded-list" data-src="/list"></div></form>');
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(29_000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('ignores a corrupt browser snapshot', () => {
  sessionStorage.setItem('collaborate-update:/', '{bad');
  const { updates, hub } = start();
  expect(sessionStorage.getItem('collaborate-update:/')).toBeNull();
  updates.stop();
  expect(hub.leaves).toBe(1);
});

test('refreshes a read view while a batch checkbox has focus, and gives the focus back to its row', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-index';
  const { updates, reload } = start(
    '<input type="checkbox" class="form-batch-checkbox" value="42"><input type="checkbox" name="enabled" value="1">'
  );
  const row = document.querySelector('.form-batch-checkbox');
  row.checked = true;
  row.focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  expect(JSON.parse(sessionStorage.getItem('collaborate-update:/'))).toMatchObject({
    selected: ['42'],
    focusRow: '42',
  });
  // Any other focused control still holds the refresh back.
  document.querySelector('[name="enabled"]').focus();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('defers a read view refresh while a control left without submitting holds typed text', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-index';
  const { updates, reload } = start(
    '<input id="query" value="saved"><textarea id="note"></textarea><input type="checkbox" class="form-batch-checkbox" value="42">'
  );
  const query = document.getElementById('query');
  const note = document.getElementById('note');
  // A ticked row is restored after the refresh: it does not hold it back.
  document.querySelector('.form-batch-checkbox').checked = true;
  query.value = 'typed';
  updates.changed();
  await vi.advanceTimersByTimeAsync(3000);
  expect(reload).not.toHaveBeenCalled();
  query.value = 'saved';
  note.value = 'typed';
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  note.value = '';
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('uses the browser reload for an idle read view', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-detail';
  document.body.innerHTML = '<div data-collaborate-layer data-updates="true"></div>';
  const updates = initUpdates(document.querySelector('[data-collaborate-layer]'), fakeHub());
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(sessionStorage.getItem('collaborate-update:/')).not.toBeNull();
  updates.stop();
});

function readView(entity) {
  vi.useFakeTimers();
  document.body.className = `ea-index ea-index-${entity}`;
  const { updates, hub, reload } = start();
  const receive = (data) => hub.members[0].receive({ data: JSON.stringify(data) }, data);
  return { reload, updates, receive };
}

test('refreshes a read view only for saved events that name its entity', async () => {
  const { reload, updates, receive } = readView('Product');
  receive({ channel: 'updates', kind: 'saved', entities: ['Category', 'Supplier'] });
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  receive({ channel: 'updates', kind: 'saved', entities: ['Product', 'Category'] });
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('refreshes a read view for an event without entities', async () => {
  const { reload, updates, receive } = readView('Product');
  receive({ channel: 'updates', kind: 'saved' });
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('refreshes a read view once its stream reopens after a loss, since a save published meanwhile is missed', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-index ea-index-Product';
  const { updates, hub, reload } = start();
  const [member] = hub.members;
  // Attempts that failed before the first opening, the first opening and a handover (no loss reported)
  // leave the page as rendered.
  member.closed();
  member.opened();
  member.opened();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  member.closed();
  member.closed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  member.opened();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  member.opened();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('refreshes once a second at most, however often saves arrive', async () => {
  const { reload, updates } = readView('Product');
  for (let tick = 0; tick < 4; tick++) {
    updates.changed();
    await vi.advanceTimersByTimeAsync(400);
  }
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('never cancels a navigation that started before the refresh, and refreshes once the page stays', async () => {
  const { reload, updates } = readView('Product');
  updates.changed();
  window.dispatchEvent(new Event('beforeunload'));
  await vi.advanceTimersByTimeAsync(2000);
  expect(reload).not.toHaveBeenCalled();
  // A download leaves the page in place; the next interaction catches up.
  window.dispatchEvent(new Event('pointerdown'));
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  window.dispatchEvent(new Event('beforeunload'));
  window.dispatchEvent(new Event('keydown'));
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
  updates.changed();
  window.dispatchEvent(new Event('beforeunload'));
  updates.stop();
  window.dispatchEvent(new Event('keydown'));
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).toHaveBeenCalledOnce();
});

test('waits once for a loading embedded list, however many saves it hears meanwhile', async () => {
  vi.useFakeTimers();
  const { updates } = start('<form data-collaborate><div class="field-embedded-list" data-src="/list"></div></form>');
  const list = document.querySelector('.field-embedded-list');
  const reload = vi.fn();
  list.addEventListener('ea.embedded-list.reload', reload);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(28_000);
  expect(reload).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(60_000);
  expect(reload).toHaveBeenCalledOnce();
  updates.stop();
});

test('leaves a page that is neither a list nor a record view, nor holds a form, as it is', async () => {
  vi.useFakeTimers();
  document.body.className = 'ea-edit';
  const { updates, reload } = start();
  updates.changed();
  await vi.advanceTimersByTimeAsync(1000);
  expect(reload).not.toHaveBeenCalled();
  expect(sessionStorage.getItem('collaborate-update:/')).toBeNull();
  updates.stop();
});

describe('starting on its own', () => {
  afterEach(() => {
    vi.resetModules();
  });

  async function load(readyState) {
    vi.resetModules();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue(readyState);
    const join = vi.fn(() => () => {});
    vi.doMock('../../assets/hub.js', () => ({ hubFor: () => ({ join }) }));
    document.body.innerHTML = '<div data-collaborate-layer data-updates="true"></div>';
    await import('../../assets/updates.js');
    vi.doUnmock('../../assets/hub.js');
    return join;
  }

  test('follows the saved-views channel at once on a parsed page', async () => {
    const join = await load('complete');
    expect(join).toHaveBeenCalledOnce();
    expect(join.mock.calls[0][0].channel).toBe('updates');
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  });

  test('waits for the page to be parsed before following it', async () => {
    const join = await load('loading');
    expect(join).not.toHaveBeenCalled();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(join).toHaveBeenCalledOnce();
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  });
});
