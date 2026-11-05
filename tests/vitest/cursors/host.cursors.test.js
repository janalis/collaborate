const mocks = vi.hoisted(() => ({ subscribe: vi.fn() }));
vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: mocks.subscribe }));

import { cursorsFor, initCursors, LABEL_DELAY, POINTERS, PULSE } from '../../../assets/cursors/cursors.js';
import { configureGeometry } from '../../../assets/cursors/geometry.js';
import { createPresence } from '../../../assets/cursors/presence.js';
import { CollaborateHub } from '../../../assets/hub.js';

// A host's file listing: a listing of records keyed by `data-key`, a dialog holding a popup of a
// record, presence as a menu, and every option on.
const CONFIG = {
  throttle: 100,
  heartbeat: 5000,
  expiry: 15000,
  maxAvatars: 5,
  renew: 240000,
  colors: ['blue', 'indigo', 'violet', 'purple', 'pink', 'red'],
  regions: { modal: 'dialog[open]', listing: '#listing', body: 'body' },
  keyAttribute: 'data-key',
  keyScope: '#listing',
  caretRegions: ['modal'],
  popups: { share: { where: 'sharing %name%', line: 'Also open by %names%.' } },
  idle: 60000,
  markers: true,
  edges: '.pane',
  topLayer: true,
  presence: 'menu',
  distinctColors: true,
  silentAlone: true,
  messages: { showPointers: 'Pointers', idle: 'idle', on: 'on %name%', here: 'here', is: '%name% is %place%' },
};
const GRANT = '1.999.hash.room-a.place.sig';

let instance;
let fetcher;
let stream;
let now;
let turn;
const anchor = { region: 'listing', selector: 'tr[data-key="a"] > td:nth-of-type(1)', x: 0.5, y: 0.5 };
const response = (status = 200, data = {}) => ({ ok: status < 400, status, json: async () => data });
const event = (overrides = {}) => ({
  channel: 'cursors',
  userId: 2,
  clientId: 'peer-client-123456',
  sequence: 1,
  kind: 'move',
  color: 'purple',
  label: 'Alice',
  timestamp: Date.now(),
  style: 'mac',
  shape: 'default',
  anchor,
  ...overrides,
});
const layer = () => document.querySelector('[data-collaborate-layer]');
const begin = (config = CONFIG) => {
  layer().dataset.config = JSON.stringify(config);
  return initCursors(
    layer(),
    fetcher,
    new CollaborateHub(layer(), fetcher, mocks.subscribe),
    createPresence(undefined, config.maxAvatars, config),
    turn
  );
};
const receive = (overrides) => instance.receive({ data: JSON.stringify(event(overrides)) });
const flush = async () => {
  await vi.advanceTimersByTimeAsync(0);
};
const posted = () => fetcher.mock.calls.filter(([url]) => url === '/events').map(([, init]) => JSON.parse(init.body));
const sessions = () =>
  fetcher.mock.calls.filter(([url]) => url === '/session').map(([, init]) => JSON.parse(init.body));

beforeEach(() => {
  vi.useFakeTimers();
  now = 0;
  document.body.innerHTML = `
    <div data-collaborate-presence aria-label="Also here" hidden></div>
    <main id="listing"><div class="pane"><table><tbody>
      <tr data-key="a" data-collaborate-label="a.mp3"><td>a.mp3</td></tr><tr data-key="b"><td>b.mp3</td></tr>
    </tbody></table></div></main>
    <dialog id="share"><div data-key="a" data-collaborate-popup="share"><input name="title"></div></dialog>
    <div data-collaborate-layer data-grant="${GRANT}" data-csrf="csrf" data-session-url="/session" data-event-url="/events"></div>`;
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.elementFromPoint = vi.fn(() => document.querySelector('tr[data-key="a"] td'));
  vi.stubGlobal('CSS', { escape: (value) => value });
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }));
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      disconnect() {}
    }
  );
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
    left: 0,
    top: 0,
    width: 200,
    height: 100,
    right: 200,
    bottom: 100,
  });
  vi.spyOn(performance, 'now').mockImplementation(() => now);
  vi.stubGlobal('requestAnimationFrame', (callback) =>
    setTimeout(() => {
      now += 20;
      callback(now);
    }, 20)
  );
  vi.stubGlobal('cancelAnimationFrame', clearTimeout);
  // The layer is a popover of the top layer.
  HTMLElement.prototype.showPopover = vi.fn(function () {
    this.dataset.popoverOpen = 'true';
  });
  HTMLElement.prototype.hidePopover = vi.fn(function () {
    delete this.dataset.popoverOpen;
  });
  const matches = Element.prototype.matches;
  vi.spyOn(Element.prototype, 'matches').mockImplementation(function (selector) {
    return selector === ':popover-open' ? this.dataset.popoverOpen === 'true' : matches.call(this, selector);
  });
  Element.prototype.scrollIntoView = vi.fn();
  turn = { take: vi.fn(async () => {}), give: vi.fn() };
  localStorage.clear();
  fetcher = vi.fn(async (url) =>
    url === '/session'
      ? response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' })
      : response(204)
  );
  mocks.subscribe.mockImplementation(async (_url, options) => {
    stream = options;
    options.onopen({ ok: true, headers: new Headers({ 'content-type': 'text/event-stream' }) });
    return new Promise(() => {});
  });
});
afterEach(() => {
  instance?.stop();
  instance = null;
  configureGeometry();
  delete HTMLElement.prototype.showPopover;
  delete HTMLElement.prototype.hidePopover;
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function start(config) {
  instance = begin(config);
  await flush();
  return instance;
}

function pointer(type, pointerType = 'mouse', target = document.querySelector('td')) {
  const pointed = new MouseEvent(type, { bubbles: true, clientX: 50, clientY: 25 });
  Object.defineProperty(pointed, 'pointerType', { value: pointerType });
  target.dispatchEvent(pointed);
}

test('the cursors of a layer are found by the host, and a layer without cursors has none', async () => {
  await start();
  expect(cursorsFor(layer())).toBe(instance);
  expect(cursorsFor()).toBe(instance);
  expect(cursorsFor(null)).toBeNull();
  instance.stop();
  expect(cursorsFor(layer())).toBeNull();
  instance = null;
});

test('the layer is a popover of the top layer, raised above a dialog that opens', async () => {
  await start();
  expect(layer().getAttribute('popover')).toBe('manual');
  expect(HTMLElement.prototype.showPopover).toHaveBeenCalledOnce();
  document.querySelector('dialog').setAttribute('open', '');
  await flush();
  expect(HTMLElement.prototype.hidePopover).toHaveBeenCalledOnce();
  expect(HTMLElement.prototype.showPopover).toHaveBeenCalledTimes(2);
  // Out of the page, it is raised no more.
  instance.stop();
  instance = null;
  document.querySelector('dialog').removeAttribute('open');
  await flush();
  expect(HTMLElement.prototype.showPopover).toHaveBeenCalledTimes(2);
  // A stopped layer is as it was before it started, and starts again.
  expect(layer().hasAttribute('popover')).toBe(false);
  expect(layer().dataset.popoverOpen).toBeUndefined();
  for (const name of ['initialized', 'labels', 'hidden']) expect(layer().dataset[name]).toBeUndefined();
  await start();
  expect(instance).not.toBeNull();
  expect(cursorsFor(layer())).toBe(instance);
  expect(layer().getAttribute('popover')).toBe('manual');
  // A popover already hidden is not hidden again.
  layer().hidePopover();
  HTMLElement.prototype.hidePopover.mockClear();
  instance.stop();
  instance = null;
  expect(HTMLElement.prototype.hidePopover).not.toHaveBeenCalled();
});

test('without popovers, or unless the host asks, the layer stays where it is', async () => {
  delete HTMLElement.prototype.showPopover;
  await start();
  expect(layer().hasAttribute('popover')).toBe(false);
  instance.stop();
  instance = null;
  HTMLElement.prototype.showPopover = vi.fn();
  await start({ ...CONFIG, topLayer: false, idle: 0, presence: 'avatars' });
  expect(layer().hasAttribute('popover')).toBe(false);
  expect(layer().dataset.labels).toBeUndefined();
  expect(document.querySelector('.collaborate-announce')).toBeNull();
});

test('a reader alone tells nothing but hello, and answers the hello of a peer who comes', async () => {
  await start();
  expect(posted().map((sent) => sent.kind)).toEqual(['hello']);
  now = 1000;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(5000);
  expect(posted().map((sent) => sent.kind)).toEqual(['hello']);
  receive({ kind: 'hello', anchor: null });
  now = 7000;
  await vi.advanceTimersByTimeAsync(100);
  expect(posted().at(-1).kind).toBe('move');
  expect(posted().at(-1).anchor.selector).toBe('tr[data-key="a"] > td:nth-of-type(1)');
  expect(posted().at(-1).popup).toBeNull();
});

test('a lost hello is sent again behind the backoff; one refused for good is not', async () => {
  fetcher.mockImplementation(async (url) =>
    url === '/session'
      ? response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' })
      : response(503)
  );
  await start();
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  expect(posted().map((sent) => sent.kind)).toEqual(['hello', 'hello']);
  fetcher.mockImplementation(async (url) =>
    url === '/session'
      ? response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' })
      : response(400)
  );
  now = 3000;
  await vi.advanceTimersByTimeAsync(2000);
  const count = posted().length;
  now = 10000;
  await vi.advanceTimersByTimeAsync(4000);
  expect(posted()).toHaveLength(count);
});

test('peers who stopped hearing each other say hello again', async () => {
  await start();
  receive({ kind: 'hello', anchor: null });
  now = 20000;
  await vi.advanceTimersByTimeAsync(20000);
  expect(instance.presence.users.size).toBe(0);
  now = 21000;
  await vi.advanceTimersByTimeAsync(200);
  expect(posted().filter((sent) => sent.kind === 'hello')).toHaveLength(2);
});

test('in a crowd, a tab sends less often', async () => {
  await start();
  for (let id = 2; id <= 8; id++)
    receive({ userId: id, clientId: `client-${id}-1234567890`, kind: 'hello', anchor: null });
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  const count = posted().length;
  // Seven peers: a third of the throttle more for each past the crowd of ${CROWD}.
  pointer('pointermove');
  now = 1200;
  await vi.advanceTimersByTimeAsync(200);
  expect(posted()).toHaveLength(count);
  now = 1300;
  await vi.advanceTimersByTimeAsync(40);
  expect(posted()).toHaveLength(count + 1);
});

test('a dragged file is followed where it rests, and a finger is found where it is', async () => {
  await start();
  receive({ kind: 'hello', anchor: null });
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  const drag = new MouseEvent('dragover', { bubbles: true, clientX: 60, clientY: 30 });
  document.dispatchEvent(drag);
  document.dispatchEvent(new MouseEvent('dragover', { bubbles: true, clientX: 60, clientY: 30 }));
  // The frames drawn meanwhile move the clock on: a second is past any throttle.
  now = 2000;
  await vi.advanceTimersByTimeAsync(1000);
  expect(posted().at(-1).kind).toBe('move');
  expect(document.elementFromPoint).toHaveBeenCalledWith(60, 30);
  document.elementFromPoint.mockClear();
  pointer('pointermove', 'touch', document.querySelector('tr[data-key="b"] td'));
  now = 4000;
  await vi.advanceTimersByTimeAsync(1000);
  expect(document.elementFromPoint).toHaveBeenCalledWith(50, 25);
});

test('peers on records are marked, drawn in distinct colours, dimmed when still and labelled while they move', async () => {
  await start();
  receive({ color: 'pink' });
  receive({ userId: 3, clientId: 'third-client-123456', color: 'red', anchor: { ...anchor, x: 0.2 } });
  await vi.advanceTimersByTimeAsync(20);
  const [first, second] = document.querySelectorAll('.collaborate-cursor');
  expect(first.dataset.color).toBe('pink');
  expect(second.dataset.color).not.toBe('red');
  expect(layer().dataset.labels).toBe('moving');
  expect(first.classList.contains('collaborate-cursor--labelled')).toBe(true);
  await vi.advanceTimersByTimeAsync(LABEL_DELAY);
  expect(first.classList.contains('collaborate-cursor--labelled')).toBe(false);
  const marker = document.querySelector('.collaborate-marker');
  expect(marker.title).toBe('Alice, Alice');
  expect(instance.presence.users.get(2).file).toBe('a');
  // A still pointer is dimmed after the idle time, and wakes as it moves.
  for (let beat = 0; beat < 13; beat++) {
    receive({ sequence: 2 + beat, kind: 'heartbeat', timestamp: Date.now() });
    receive({
      userId: 3,
      clientId: 'third-client-123456',
      sequence: 2 + beat,
      kind: 'heartbeat',
      anchor: { ...anchor, x: 0.2 },
      timestamp: Date.now(),
    });
    await vi.advanceTimersByTimeAsync(5000);
  }
  expect(first.classList.contains('collaborate-cursor--idle')).toBe(true);
  receive({ sequence: 40, anchor: { ...anchor, x: 0.9 } });
  expect(first.classList.contains('collaborate-cursor--idle')).toBe(false);
});

test('records are marked again once a peer changes record or popup, or the page moves, and not as it says who', async () => {
  await start();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  expect(document.querySelector('.collaborate-marker')).not.toBeNull();
  // Only a marker measures the cells of its row.
  const measured = vi.spyOn(document.querySelector('tr[data-key="a"]'), 'querySelector');
  receive({ sequence: 2, anchor: { ...anchor, x: 0.9 } });
  await vi.advanceTimersByTimeAsync(220);
  expect(measured).not.toHaveBeenCalled();
  receive({ sequence: 3, anchor: { ...anchor, x: 0.9 }, popup: { kind: 'share', key: 'a' } });
  await vi.advanceTimersByTimeAsync(20);
  expect(measured).toHaveBeenCalledOnce();
  window.dispatchEvent(new Event('resize'));
  await vi.advanceTimersByTimeAsync(20);
  expect(measured).toHaveBeenCalledTimes(2);
  // The menu scrolls to a peer, then says where they are: the announcement moves nothing on the page.
  instance.findReader(2);
  await vi.advanceTimersByTimeAsync(20);
  measured.mockClear();
  await vi.advanceTimersByTimeAsync(100);
  expect(document.querySelector('.collaborate-announce').textContent).toBe('Alice is sharing a.mp3');
  expect(measured).not.toHaveBeenCalled();
});

test('a peer in a popup of a record is said in it, and the popup open here is told to the others', async () => {
  await start();
  receive({ kind: 'hello', anchor: null });
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  const dialog = document.querySelector('dialog');
  dialog.setAttribute('open', '');
  await flush();
  now = 1100;
  await vi.advanceTimersByTimeAsync(100);
  expect(posted().at(-1).popup).toEqual({ kind: 'share', key: 'a' });
  // The same popup again tells nothing new.
  const count = posted().length;
  dialog.querySelector('input').setAttribute('class', 'x');
  await vi.advanceTimersByTimeAsync(200);
  expect(posted()).toHaveLength(count);
  receive({ sequence: 2, anchor: null, popup: { kind: 'share', key: 'a' } });
  await vi.advanceTimersByTimeAsync(60);
  const line = dialog.querySelector('[data-collaborate-popup-line]');
  expect(line.textContent).toContain('Also open by Alice.');
  // A popup of an unknown kind, or none at all, is no event of the host.
  receive({ sequence: 3, popup: { kind: 'evil', key: 'a' } });
  expect(instance.presence.users.get(2).popup).toEqual({ kind: 'share', key: 'a' });
  dialog.removeAttribute('open');
  await vi.advanceTimersByTimeAsync(60);
  expect(dialog.querySelector('[data-collaborate-popup-line]')).toBeNull();
});

test('a host without popups sends none', async () => {
  await start({ ...CONFIG, popups: undefined });
  receive({ kind: 'hello', anchor: null });
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  expect(posted().at(-1)).not.toHaveProperty('popup');
  document.querySelector('dialog').setAttribute('open', '');
  await vi.advanceTimersByTimeAsync(200);
  expect(posted().at(-1)).not.toHaveProperty('popup');
  instance.enter('1.999.hash.room-b.place.sig');
  await flush();
  expect(sessions().at(-1).grant).toBe('1.999.hash.room-b.place.sig');
});

test('a grant that names no room is another place', async () => {
  layer().dataset.grant = 'malformed';
  await start();
  instance.enter('other');
  const leave = fetcher.mock.calls.findLast(([url]) => url === '/events');
  expect(JSON.parse(leave[1].body)).toMatchObject({ kind: 'leave', grant: 'malformed' });
  expect(layer().dataset.grant).toBe('other');
});

test('a peer found from the menu is scrolled to, pointed out and said', async () => {
  await start();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const where = document.querySelector('.collaborate-presence__where');
  expect(where.textContent).toBe('on a.mp3');
  document.querySelector('.collaborate-presence__item[data-user-id="2"]').click();
  expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' });
  const cursor = document.querySelector('.collaborate-cursor');
  expect(cursor.classList.contains('collaborate-pulse')).toBe(true);
  expect(document.querySelector('.collaborate-marker').classList.contains('collaborate-pulse')).toBe(true);
  const announcer = document.querySelector('.collaborate-announce');
  expect(announcer.getAttribute('role')).toBe('status');
  expect(announcer.textContent).toBe('');
  await vi.advanceTimersByTimeAsync(50);
  expect(announcer.textContent).toBe('Alice is on a.mp3');
  await vi.advanceTimersByTimeAsync(PULSE);
  expect(cursor.classList.contains('collaborate-pulse')).toBe(false);
  // In a popup, on a record without a label, or on nothing: said each way.
  receive({ sequence: 2, anchor: null, popup: { kind: 'share', key: 'a' } });
  instance.findReader(2);
  await vi.advanceTimersByTimeAsync(50);
  expect(announcer.textContent).toBe('Alice is sharing a.mp3');
  receive({ sequence: 3, anchor: { ...anchor, selector: 'tr[data-key="b"] > td:nth-of-type(1)' }, popup: null });
  instance.findReader(2);
  await vi.advanceTimersByTimeAsync(50);
  expect(announcer.textContent).toBe('Alice is here');
  receive({ sequence: 4, anchor: { ...anchor, region: 'body', selector: 'main' } });
  Element.prototype.scrollIntoView.mockClear();
  instance.findReader(2);
  expect(Element.prototype.scrollIntoView).toHaveBeenCalledOnce();
  // A peer the page no longer has, or one without a pointer, a popup or a record.
  instance.findReader(9);
  receive({ userId: 4, clientId: 'fourth-client-1234', kind: 'heartbeat', anchor: null });
  Element.prototype.scrollIntoView.mockClear();
  instance.findReader(4);
  expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
});

test('a popup without its own sentence, and a page without a menu, say what they can', async () => {
  await start({ ...CONFIG, popups: { share: {} }, messages: { where: 'in %name%' }, presence: 'avatars' });
  receive({ anchor: null, popup: { kind: 'share', key: 'a' } });
  instance.findReader(2);
  receive({ userId: 3, clientId: 'third-client-123456', anchor: { ...anchor, region: 'body', selector: 'main' } });
  instance.findReader(3);
  expect(document.querySelector('.collaborate-announce')).toBeNull();
});

test('the reader hides the pointers of the others, in every tab, and still shares theirs', async () => {
  await start();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const toggle = document.querySelector('.collaborate-presence__pointers');
  expect(toggle.getAttribute('aria-checked')).toBe('true');
  toggle.click();
  expect(localStorage.getItem(POINTERS)).toBe('hidden');
  expect(layer().dataset.hidden).toBe('true');
  expect(document.querySelector('.collaborate-marker')).toBeNull();
  expect(document.querySelector('.collaborate-presence__pointers').getAttribute('aria-checked')).toBe('false');
  receive({ sequence: 2, anchor: { ...anchor, x: 0.1 } });
  await vi.advanceTimersByTimeAsync(60);
  expect(document.querySelector('.collaborate-marker')).toBeNull();
  document.querySelector('.collaborate-presence__pointers').click();
  expect(localStorage.getItem(POINTERS)).toBe('shown');
  window.dispatchEvent(new StorageEvent('storage', { key: POINTERS, newValue: 'hidden' }));
  expect(layer().dataset.hidden).toBe('true');
  // Another tab shows them again.
  window.dispatchEvent(new StorageEvent('storage', { key: POINTERS, newValue: 'shown' }));
  expect(layer().dataset.hidden).toBe('false');
  window.dispatchEvent(new StorageEvent('storage', { key: 'other', newValue: 'hidden' }));
  expect(layer().dataset.hidden).toBe('false');
  // A page loaded while they are hidden starts hidden.
  instance.stop();
  layer().removeAttribute('data-initialized');
  localStorage.setItem(POINTERS, 'hidden');
  await start();
  expect(layer().dataset.hidden).toBe('true');
});

test('a browser that keeps no preference shows the pointers and forgets the choice', async () => {
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new Error('denied');
  });
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new Error('denied');
  });
  await start();
  expect(layer().dataset.hidden).toBe('false');
  receive();
  document.querySelector('.collaborate-presence__pointers').click();
  expect(layer().dataset.hidden).toBe('true');
});

test('the host enters another place: the page leaves the room, and joins the next with its grant', async () => {
  await start();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const next = '1.999.hash.room-b.place.sig';
  instance.enter(next);
  expect(layer().dataset.grant).toBe(next);
  const leave = fetcher.mock.calls.findLast(([url]) => url === '/events');
  expect(JSON.parse(leave[1].body)).toMatchObject({ kind: 'leave', grant: GRANT });
  expect(instance.peers.size).toBe(0);
  expect(turn.give).not.toHaveBeenCalled();
  // What the stream says before it opens again is of the room left.
  stream.onmessage({ data: JSON.stringify(event({ sequence: 9 })) });
  expect(instance.peers.size).toBe(0);
  await flush();
  expect(sessions().at(-1).grant).toBe(next);
  receive({ sequence: 10 });
  expect(instance.peers.size).toBe(1);
});

test('a grant to the same room keeps the stream; one refused is entered again by the next grant', async () => {
  await start();
  const before = sessions().length;
  instance.enter('1.1000.hash.room-a.place.sig2');
  await flush();
  expect(sessions()).toHaveLength(before);
  expect(layer().dataset.grant).toBe('1.1000.hash.room-a.place.sig2');
  // The server refuses the next place for good: the page renews nothing from itself.
  fetcher.mockImplementation(async (url) => (url === '/session' ? response(403) : response(500)));
  instance.enter('1.1000.hash.room-x.place.sig');
  await flush();
  await flush();
  expect(layer().dataset.connected).toBe('false');
  const refused = sessions().length;
  instance.publish('hello');
  await vi.advanceTimersByTimeAsync(2000);
  expect(sessions()).toHaveLength(refused);
  fetcher.mockImplementation(async (url) =>
    url === '/session'
      ? response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' })
      : response(204)
  );
  document.dispatchEvent(new CustomEvent('collaborate:enter', { detail: { grant: '1.1001.hash.room-x.place.sig3' } }));
  await flush();
  expect(layer().dataset.connected).toBe('true');
  // Nothing, or no grant, enters nothing.
  for (const grant of [undefined, '', 7]) instance.enter(grant);
  document.dispatchEvent(new CustomEvent('collaborate:enter'));
  expect(layer().dataset.grant).toBe('1.1001.hash.room-x.place.sig3');
  instance.stop();
  instance.enter('1.1.hash.room-c.place.sig');
  expect(layer().dataset.grant).toBe('1.1001.hash.room-x.place.sig3');
  instance = null;
});

test('a refusal of the grant of a room already left refuses nothing; a CSRF token renewed meanwhile neither', async () => {
  await start();
  receive({ kind: 'hello', anchor: null });
  fetcher.mockImplementation(async (url, init) => {
    if (url !== '/events')
      return response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' });
    if (JSON.parse(init.body).kind === 'leave') return response(403);
    layer().dataset.csrf = 'renewed';
    return response(403);
  });
  instance.enter('1.999.hash.room-b.place.sig');
  await flush();
  expect(layer().dataset.connected).toBe('true');
  receive({ kind: 'hello', anchor: null, sequence: 2 });
  now = 2000;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(100);
  expect(layer().dataset.connected).toBe('true');
});

test('over HTTP/1.1 with a shared stream, the page streams in its turn, kept from a place to the next', async () => {
  let granted;
  turn.take.mockImplementation(
    () =>
      new Promise((resolve) => {
        granted = resolve;
      })
  );
  await start({ ...CONFIG, shareStream: true });
  expect(mocks.subscribe).not.toHaveBeenCalled();
  granted();
  await flush();
  expect(mocks.subscribe).toHaveBeenCalledOnce();
  instance.enter('1.999.hash.room-b.place.sig');
  expect(turn.give).not.toHaveBeenCalled();
  await flush();
  // Hidden, the page gives its turn up; a turn that comes once it left joins nothing.
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  document.dispatchEvent(new Event('visibilitychange'));
  expect(turn.give).toHaveBeenCalled();
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.dispatchEvent(new Event('visibilitychange'));
  window.dispatchEvent(new Event('offline'));
  const calls = mocks.subscribe.mock.calls.length;
  granted();
  await flush();
  expect(mocks.subscribe).toHaveBeenCalledTimes(calls);
  // A turn the browser fails to give is waited for no longer.
  turn.take.mockRejectedValueOnce(new Error('no locks'));
  window.dispatchEvent(new Event('online'));
  await flush();
});

test('a page without a secure context shares nothing, and leaves the layer to start later', async () => {
  vi.stubGlobal('crypto', {});
  expect(begin()).toBeNull();
  expect(layer().dataset.initialized).toBeUndefined();
  vi.stubGlobal('crypto', undefined);
  expect(begin()).toBeNull();
  vi.unstubAllGlobals();
  vi.stubGlobal('CSS', { escape: (value) => value });
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }));
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      disconnect() {}
    }
  );
  vi.stubGlobal('requestAnimationFrame', (callback) => setTimeout(() => callback(now), 20));
  vi.stubGlobal('cancelAnimationFrame', clearTimeout);
  await start();
  expect(instance).not.toBeNull();
});
