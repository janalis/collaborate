// EasyAdmin's markup: its bridge's profile.
import '../../../assets/bridges/easyadmin.js';
const mocks = vi.hoisted(() => ({ subscribe: vi.fn(), pointerShape: vi.fn(), resolveCaret: vi.fn() }));
vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: mocks.subscribe }));
// Spies around the measuring helpers count how often a frame or a move measures layout.
vi.mock('../../../assets/cursors/geometry.js', async (original) => {
  const actual = await original();
  mocks.pointerShape.mockImplementation(actual.pointerShape);
  return { ...actual, pointerShape: mocks.pointerShape };
});
vi.mock('../../../assets/cursors/carets.js', async (original) => {
  const actual = await original();
  mocks.resolveCaret.mockImplementation(actual.resolveCaret);
  return { ...actual, resolveCaret: mocks.resolveCaret };
});

import { initCursors } from '../../../assets/cursors/cursors.js';
import { CURSOR_SHAPES, shapeHotspot } from '../../../assets/cursors/geometry.js';
import { CollaborateHub } from '../../../assets/hub.js';

// The hub loads its transport's module on its first connection: loaded here, it is ready within a tick.
beforeAll(() => import('../../../assets/hub/mercure.js'));

let instance;
let fetcher;
let stream;
let now;
const anchor = { region: 'content', selector: 'button[id="target"]', x: 0.5, y: 0.5 };
const response = (status = 200, data = {}) => ({ ok: status < 400, status, json: async () => data });
const event = (overrides = {}) => ({
  channel: 'cursors',
  userId: 2,
  clientId: 'peer-client-123456',
  sequence: 1,
  kind: 'move',
  color: 'purple',
  label: '<b>Alice</b>',
  timestamp: Date.now(),
  style: 'mac',
  shape: 'default',
  anchor,
  ...overrides,
});
let hub;
const begin = (layer = document.querySelector('[data-collaborate-layer]')) => {
  hub = new CollaborateHub(layer, fetcher);
  return initCursors(layer, fetcher, hub);
};
const receive = (overrides) => instance.receive({ data: JSON.stringify(event(overrides)) });
const flush = async () => {
  await vi.advanceTimersByTimeAsync(0);
};

beforeEach(() => {
  vi.useFakeTimers();
  now = 0;
  document.body.innerHTML =
    '<div data-collaborate-presence hidden></div><main class="content-wrapper"><button id="target">Target</button></main><div data-collaborate-layer data-grant="grant" data-csrf="csrf" data-session-url="/session" data-event-url="/events"></div>';
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.elementFromPoint = vi.fn(() => document.querySelector('button'));
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
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function start() {
  instance = begin();
  await flush();
  return instance;
}

function pointer(type, pointerType = 'mouse', target = document.querySelector('button')) {
  const event = new MouseEvent(type, { bubbles: true, clientX: 50, clientY: 25 });
  Object.defineProperty(event, 'pointerType', { value: pointerType });
  target.dispatchEvent(event);
}

test('initializes once and subscribes using scoped authorization without loading state', async () => {
  expect(initCursors(null)).toBeNull();
  await start();
  expect(initCursors(document.querySelector('[data-collaborate-layer]'))).toBeNull();
  expect(stream.headers.Authorization).toBe('Bearer jwt');
  expect(mocks.subscribe.mock.calls[0][0]).toBe('http://localhost/.well-known/mercure?match=private');
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('true');
  expect(document.body.classList.contains('loading')).toBe(false);
  expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({
    grant: 'grant',
    updates: false,
    notifications: false,
    drafts: [],
  });
});

test('renders safe labelled platform cursors, ignores self, and smooths subsequent motion', async () => {
  await start();
  receive({ userId: 1 });
  expect(instance.peers.size).toBe(0);
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const cursor = document.querySelector('.collaborate-cursor');
  expect(cursor.dataset.color).toBe('purple');
  expect(cursor.dataset.style).toBe('mac');
  expect(cursor.textContent).toBe('<b>Alice</b>');
  expect(cursor.querySelector('b')).toBeNull();
  expect(cursor.style.transform).toBe('translate3d(100px, 50px, 0)');
  receive({ sequence: 2, anchor: { ...anchor, x: 1 } });
  await vi.advanceTimersByTimeAsync(40);
  expect(instance.peers.get(2).point.x).toBeGreaterThan(100);
  expect(instance.peers.get(2).point.x).toBeLessThan(200);
  await vi.advanceTimersByTimeAsync(100);
  expect(instance.peers.get(2).point.x).toBe(200);
  receive({ sequence: 3, style: 'touch' });
  expect(cursor.querySelector('path').getAttribute('d')).toContain('a14');
});

test('rejects malformed, stale, duplicate and invalid events', async () => {
  await start();
  instance.receive({ data: 'not-json' });
  instance.receive({ data: 'null' });
  for (const invalid of [
    { userId: '2' },
    { clientId: 1 },
    { sequence: '1' },
    { kind: 'fake' },
    { color: 'fake' },
    { label: null },
    { style: 'fake' },
    { timestamp: null },
    { anchor: { ...anchor, selector: 1 } },
    { anchor: { ...anchor, selector: 'x'.repeat(1025) } },
    { anchor: { ...anchor, region: 'invalid' } },
    { anchor: { ...anchor, x: -1 } },
    { anchor: { ...anchor, y: 2 } },
  ])
    receive(invalid);
  expect(instance.peers.size).toBe(0);
  receive();
  receive({ label: 'wrong duplicate' });
  expect(document.querySelector('.collaborate-cursor').textContent).toBe('<b>Alice</b>');
  receive({ sequence: 2, timestamp: Date.now() - 1, label: 'older' });
  expect(document.querySelector('.collaborate-cursor').textContent).toBe('<b>Alice</b>');
  receive({ userId: 3, sequence: 1, timestamp: Date.now() - 15001, label: 'replayed' });
  expect(instance.peers.has(3)).toBe(false);
});

test('accepts a server clock far from the client clock and expires peers by receipt time', async () => {
  await start();
  const server = Date.now() + 3_600_000;
  receive({ timestamp: server });
  expect(instance.peers.size).toBe(1);
  receive({ sequence: 2, timestamp: server + 5000, label: 'moved' });
  expect(document.querySelector('.collaborate-cursor').textContent).toBe('moved');
  receive({ userId: 3, timestamp: server - 15001 });
  expect(instance.peers.has(3)).toBe(false);
  receive({ userId: 3, timestamp: server - 10000 });
  expect(instance.peers.has(3)).toBe(true);
  await vi.advanceTimersByTimeAsync(20000);
  expect(instance.peers.size).toBe(0);
});

test('the latest moving tab owns a user cursor, other tabs cannot hide it', async () => {
  await start();
  receive();
  receive({ clientId: 'another-client-123', kind: 'heartbeat', anchor: null });
  expect(instance.peers.size).toBe(1);
  receive({ clientId: 'another-client-123', sequence: 2, kind: 'move', style: 'windows' });
  expect(document.querySelector('.collaborate-cursor').dataset.style).toBe('windows');
  receive({ sequence: 2, kind: 'hide', anchor: null });
  expect(instance.peers.size).toBe(1);
  receive({ clientId: 'another-client-123', sequence: 3, kind: 'hide', anchor: null });
  expect(instance.peers.size).toBe(0);
});

test('resolves scroll and layout changes and hides missing anchors', async () => {
  await start();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  document.querySelector('button').getBoundingClientRect = () => ({
    left: 200,
    top: 100,
    width: 200,
    height: 100,
    right: 400,
    bottom: 200,
  });
  document.dispatchEvent(new Event('scroll'));
  await vi.advanceTimersByTimeAsync(140);
  expect(instance.peers.get(2).point).toEqual({ x: 300, y: 150 });
  document.querySelector('button').remove();
  await vi.advanceTimersByTimeAsync(40);
  expect(document.querySelector('.collaborate-cursor').hidden).toBe(true);
});

test('throttles and coalesces movement and switches to a transient touch cursor', async () => {
  await start();
  pointer('pointermove');
  pointer('pointermove');
  expect(fetcher.mock.calls.filter(([url]) => url === '/events')).toHaveLength(1);
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('move');
  pointer('pointerdown', 'touch');
  pointer('pointerup', 'touch');
  pointer('pointerleave', 'touch', document.documentElement);
  now = 200;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).style).toBe('touch');
  now = 600;
  await vi.advanceTimersByTimeAsync(400);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('hide');
});

describe('on the hub socket', () => {
  let sent;
  const posted = () => fetcher.mock.calls.filter(([url]) => url === '/events').map(([, init]) => JSON.parse(init.body));
  const overSocket = () => sent.mock.calls.map(([message]) => message);

  // The socket takes cursor events from the start: the opening hello goes on it too.
  async function startOnSocket() {
    instance = begin();
    sent = vi.spyOn(hub, 'send').mockReturnValue(true);
    await flush();
  }

  test('sends moves on the socket, without the grant, and posts nothing', async () => {
    await startOnSocket();
    now = 100;
    pointer('pointermove');
    expect(overSocket().map(({ hub, event }) => [hub, event.kind])).toEqual([
      ['cursor', 'hello'],
      ['cursor', 'move'],
    ]);
    expect(overSocket()[1].event).toMatchObject({
      clientId: expect.any(String),
      sequence: 2,
      anchor: { selector: anchor.selector },
    });
    expect(overSocket()[1].event).not.toHaveProperty('grant');
    expect(posted()).toEqual([]);
  });

  test('keeps the throttle and the crowd spacing, waiting on no answer', async () => {
    await startOnSocket();
    now = 100;
    pointer('pointermove');
    pointer('pointermove');
    expect(overSocket()).toHaveLength(2);
    now = 200;
    await vi.advanceTimersByTimeAsync(100);
    expect(overSocket()).toHaveLength(3);
    // Nothing is pending on an answer: the next move past the throttle leaves at once.
    now = 300;
    pointer('pointermove');
    expect(overSocket()).toHaveLength(4);
    // Six peers: twice the throttle between two events.
    for (let id = 2; id <= 7; id++)
      receive({ userId: id, clientId: `client-${id}-1234567890`, kind: 'hello', anchor: null, timestamp: 1 });
    now = 2000;
    await vi.advanceTimersByTimeAsync(1000);
    const times = [];
    sent.mockImplementation(() => times.push(performance.now()) > 0);
    for (let step = 0; step < 20; step++) {
      now += 50;
      pointer('pointermove');
      await vi.advanceTimersByTimeAsync(50);
    }
    expect(times.length).toBeGreaterThan(2);
    for (let index = 1; index < times.length; index++)
      expect(times[index] - times[index - 1]).toBeGreaterThanOrEqual(200);
    expect(posted()).toEqual([]);
  });

  test('sends a state queued behind the hello once the throttle allows', async () => {
    await startOnSocket();
    now = 100;
    pointer('pointermove');
    // Within the throttle: the move waits, and a stream opening again queues its hello beside it.
    now = 150;
    pointer('pointermove');
    hub.reconnect();
    await flush();
    now = 200;
    await vi.advanceTimersByTimeAsync(50);
    now = 300;
    await vi.advanceTimersByTimeAsync(100);
    expect(overSocket().map(({ event }) => event.kind)).toEqual(['hello', 'move', 'hello', 'move']);
    expect(posted()).toEqual([]);
  });

  test('posts what the socket cannot send', async () => {
    await startOnSocket();
    sent.mockReturnValue(false);
    now = 100;
    pointer('pointermove');
    expect(posted().map((event) => event.kind)).toEqual(['move']);
    expect(posted()[0].grant).toBe('grant');
    sent.mockReturnValue(true);
    now = 200;
    pointer('pointermove');
    await vi.advanceTimersByTimeAsync(100);
    expect(posted()).toHaveLength(1);
    expect(overSocket().at(-1).event.kind).toBe('move');
  });

  test('leaves over HTTP, which outlives the page', async () => {
    await startOnSocket();
    window.dispatchEvent(new Event('pagehide'));
    expect(posted().map((event) => event.kind)).toEqual(['leave']);
    expect(fetcher.mock.calls.at(-1)[1].keepalive).toBe(true);
    expect(overSocket().map(({ event }) => event.kind)).toEqual(['hello']);
  });
});

test('posts every event under Mercure, the hub sending none', async () => {
  await start();
  const sent = vi.spyOn(hub, 'send');
  now = 100;
  pointer('pointermove');
  expect(sent).toHaveReturnedWith(false);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('move');
});

test('posts movement with the grant and CSRF token the hub last renewed on the layer', async () => {
  await start();
  const layer = document.querySelector('[data-collaborate-layer]');
  layer.dataset.grant = 'fresh';
  layer.dataset.csrf = 'renewed';
  now = 100;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(100);
  const [, options] = fetcher.mock.calls.filter(([url]) => url === '/events').at(-1);
  expect(options.headers['X-CSRF-Token']).toBe('renewed');
  expect(JSON.parse(options.body).grant).toBe('fresh');
});

test('a refused movement post has the hub renew the page grant once; the same grant refused again stops', async () => {
  await start();
  const layer = document.querySelector('[data-collaborate-layer]');
  const sessions = () => fetcher.mock.calls.filter(([url]) => url === '/session').length;
  const before = sessions();
  fetcher.mockImplementation(async (url) =>
    url === '/events'
      ? response(403)
      : url === '/session'
        ? response(200, { userId: 1, url: '/.well-known/mercure', topics: ['private'], token: 'jwt' })
        : response(204)
  );
  now = 100;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(100);
  expect(sessions()).toBe(before + 1);
  expect(layer.dataset.connected).toBe('true');
  // A refused post backs off for a second.
  now = 1200;
  pointer('pointerleave', 'mouse', document.documentElement);
  await vi.advanceTimersByTimeAsync(100);
  expect(fetcher.mock.calls.at(-1)[0]).toBe('/events');
  expect(layer.dataset.connected).toBe('false');
});

test('a movement post refused after the hub renewed the grant leaves the cursors running', async () => {
  await start();
  const layer = document.querySelector('[data-collaborate-layer]');
  fetcher.mockImplementation(async (url) => {
    if (url !== '/events') return response(204);
    layer.dataset.grant = 'fresh';
    return response(403);
  });
  now = 100;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(100);
  expect(fetcher.mock.calls.filter(([url]) => url === '/session')).toHaveLength(1);
  expect(layer.dataset.connected).toBe('true');
});

test('answers joins with a snapshot and expires disconnected peers', async () => {
  await start();
  receive();
  receive({ userId: 3, kind: 'hello', anchor: null });
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('heartbeat');
  await vi.advanceTimersByTimeAsync(35000);
  expect(instance.peers.size).toBe(0);
});

test('hides on pointer exit, leaves on pagehide and tears down', async () => {
  await start();
  pointer('pointermove');
  document.documentElement.dispatchEvent(new Event('pointerleave'));
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('hide');
  window.dispatchEvent(new Event('pagehide'));
  expect(fetcher.mock.calls.at(-1)[1].keepalive).toBe(true);
  expect(stream.signal.aborted).toBe(true);
});

test('leaves when the back/forward cache keeps the page, and joins again when it is restored', async () => {
  const strip = document.querySelector('[data-collaborate-presence]');
  await start();
  receive();
  expect(instance.peers.size).toBe(1);
  pointer('pointermove');
  const hide = new Event('pagehide');
  Object.defineProperty(hide, 'persisted', { value: true });
  window.dispatchEvent(hide);
  const leave = JSON.parse(fetcher.mock.calls.at(-1)[1].body);
  expect(leave).toMatchObject({ kind: 'leave', anchor: null, caret: null });
  expect(fetcher.mock.calls.at(-1)[1].keepalive).toBe(true);
  expect(stream.signal.aborted).toBe(true);
  expect(instance.peers.size).toBe(0);
  expect(strip.hidden).toBe(true);
  // A show the cache did not restore is the page's first: nothing to rejoin.
  const sessions = () => fetcher.mock.calls.filter(([url]) => url === '/session').length;
  const before = sessions();
  window.dispatchEvent(new Event('pageshow'));
  await flush();
  expect(sessions()).toBe(before);
  const show = new Event('pageshow');
  Object.defineProperty(show, 'persisted', { value: true });
  window.dispatchEvent(show);
  await flush();
  expect(sessions()).toBe(before + 1);
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  expect(fetcher.mock.calls.map(([, init]) => init?.body && JSON.parse(init.body).kind)).toContain('hello');
  receive({ sequence: 5 });
  expect(instance.peers.size).toBe(1);
  // Once stopped, a restore joins nothing.
  instance.stop();
  window.dispatchEvent(show);
  await flush();
  expect(sessions()).toBe(before + 1);
});

test('lists peers on the page beside the own avatar until they leave or expire', async () => {
  const strip = document.querySelector('[data-collaborate-presence]');
  const users = () => [...strip.querySelectorAll('.collaborate-presence__user')].map((user) => user.dataset.userId);
  await start();
  receive({ userId: 1 });
  expect(strip.hidden).toBe(true);
  receive({ userId: 3, kind: 'hello', anchor: null, avatar: '/avatars/bob.jpg' });
  receive();
  expect(strip.hidden).toBe(false);
  expect(users()).toEqual(['2', '3']);
  expect(strip.querySelector('[data-user-id="3"] img').src).toBe('http://localhost/avatars/bob.jpg');
  expect(strip.querySelector('[data-user-id="2"]').dataset.color).toBe('purple');
  // A pointer leaving the window removes the cursor, not the presence.
  receive({ sequence: 2, kind: 'hide', anchor: null });
  expect(instance.peers.size).toBe(0);
  expect(users()).toEqual(['2', '3']);
  // A second tab keeps the user present after the first one leaves.
  receive({ clientId: 'peer-client-second', sequence: 1, kind: 'heartbeat', anchor: null });
  receive({ sequence: 3, kind: 'leave', anchor: null });
  expect(users()).toEqual(['2', '3']);
  receive({ clientId: 'peer-client-second', sequence: 2, kind: 'leave', anchor: null });
  expect(users()).toEqual(['3']);
  receive({ userId: 4, avatar: 1 });
  expect(users()).toEqual(['3']);
  await vi.advanceTimersByTimeAsync(25000);
  expect(strip.hidden).toBe(true);
});

test('a departing peer drops its cursor and caret at once', async () => {
  await start();
  receive({ caret: { selector: 'button[id="target"]', region: 'content', start: 0, end: 0 } });
  expect(instance.peers.size).toBe(1);
  receive({ sequence: 2, kind: 'leave', anchor: null, caret: null });
  expect(instance.peers.size).toBe(0);
});

test('closes hidden tabs, clears remote cursors and reconnects on return', async () => {
  await start();
  receive();
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  document.dispatchEvent(new Event('visibilitychange'));
  expect(instance.peers.size).toBe(0);
  expect(instance.presence.users.size).toBe(0);
  expect(stream.signal.aborted).toBe(true);
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.dispatchEvent(new Event('visibilitychange'));
  await flush();
  expect(mocks.subscribe).toHaveBeenCalledTimes(2);
});

test('stops on expired authentication and backs off on transient session failures', async () => {
  fetcher.mockResolvedValueOnce(response(503));
  await start();
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('false');
  await vi.advanceTimersByTimeAsync(1000);
  expect(mocks.subscribe).toHaveBeenCalledOnce();
  instance.stop();
  document.querySelector('[data-collaborate-layer]').removeAttribute('data-initialized');
  fetcher.mockResolvedValueOnce(response(403));
  await start();
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('false');
});

test('recovers from stream errors and renews credentials', async () => {
  mocks.subscribe.mockImplementationOnce(async (_url, options) => options.onclose());
  await start();
  await vi.advanceTimersByTimeAsync(1000);
  expect(mocks.subscribe).toHaveBeenCalledTimes(2);
  await vi.advanceTimersByTimeAsync(240000);
  expect(mocks.subscribe).toHaveBeenCalledTimes(3);
});

test('fades touch peers before removing them and clears pending fade timers on stop', async () => {
  await start();
  receive({ style: 'touch' });
  receive({ sequence: 2, kind: 'hide', anchor: null });
  expect(document.querySelector('.collaborate-cursor').classList.contains('collaborate-cursor--fading')).toBe(true);
  await vi.advanceTimersByTimeAsync(200);
  expect(document.querySelector('.collaborate-cursor')).toBeNull();
  receive({ sequence: 3, style: 'touch' });
  receive({ sequence: 4, kind: 'hide', anchor: null });
  instance.stop();
  await vi.advanceTimersByTimeAsync(200);
  expect(document.querySelector('.collaborate-cursor')).toBeNull();
});

test('backs off failed movement posts without leaving unhandled rejections', async () => {
  await start();
  fetcher.mockRejectedValueOnce(new Error('offline'));
  pointer('pointermove');
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  pointer('pointermove');
  now = 1200;
  await vi.advanceTimersByTimeAsync(1000);
  fetcher.mockResolvedValueOnce(response(503));
  pointer('pointermove');
  now = 2400;
  await vi.advanceTimersByTimeAsync(1000);
  pointer('pointercancel');
});

test('retries invalid stream responses and stream transport errors', async () => {
  mocks.subscribe.mockImplementationOnce(async (_url, options) =>
    options.onopen({ ok: false, headers: new Headers() })
  );
  await start();
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('false');
  mocks.subscribe.mockImplementationOnce(async (_url, options) => options.onerror(new Error('stream failure')));
  await vi.advanceTimersByTimeAsync(1000);
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('false');
  await vi.advanceTimersByTimeAsync(2000);
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('true');
});

test('initializes after DOMContentLoaded when the entrypoint loads in the head', async () => {
  instance?.stop();
  document.body.innerHTML = '';
  Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
  vi.resetModules();
  await import('../../../assets/cursors/cursors.js');
  document.dispatchEvent(new Event('DOMContentLoaded'));
  Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
  expect(document.querySelector('[data-collaborate-layer]')).toBeNull();
});

test('a failed best-effort departure cannot raise an unhandled rejection', async () => {
  await start();
  fetcher.mockRejectedValueOnce(new Error('navigation interrupted'));
  window.dispatchEvent(new Event('pagehide'));
  await flush();
  expect(stream.signal.aborted).toBe(true);
});

test('preserves join requests while coalescing movement behind an in-flight request', async () => {
  await start();
  let finish;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        finish = resolve;
      })
  );
  now = 100;
  pointer('pointermove');
  await vi.advanceTimersByTimeAsync(100);
  instance.publish('hello');
  pointer('pointermove');
  now = 200;
  finish(response(204));
  await flush();
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('hello');
  now = 300;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('move');
});

test('recaptures what a stationary sender points at after scrolling or resizing', async () => {
  await start();
  pointer('pointermove');
  const next = document.createElement('button');
  next.id = 'next';
  document.querySelector('main').appendChild(next);
  document.elementFromPoint = vi.fn(() => next);
  document.dispatchEvent(new Event('scroll'));
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).anchor.selector).toBe('button[id="next"]');
  document.elementFromPoint.mockReturnValue(null);
  window.dispatchEvent(new Event('resize'));
  now = 200;
  await vi.advanceTimersByTimeAsync(100);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('hide');
});

test('pauses immediately offline and exchanges a fresh snapshot when the network returns', async () => {
  await start();
  receive();
  const online = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
  window.dispatchEvent(new Event('offline'));
  expect(instance.peers.size).toBe(0);
  expect(stream.signal.aborted).toBe(true);
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('false');
  pointer('pointermove');
  window.dispatchEvent(new Event('online'));
  await vi.advanceTimersByTimeAsync(5000);
  expect(mocks.subscribe).toHaveBeenCalledOnce();
  online.mockReturnValue(true);
  window.dispatchEvent(new Event('online'));
  await flush();
  expect(mocks.subscribe).toHaveBeenCalledTimes(2);
  expect(document.querySelector('[data-collaborate-layer]').dataset.connected).toBe('true');
});

test('does not subscribe when the page is hidden while session credentials arrive', async () => {
  let deliver;
  fetcher.mockResolvedValueOnce({
    ...response(),
    json: () =>
      new Promise((resolve) => {
        deliver = resolve;
      }),
  });
  instance = begin();
  await flush();
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  document.dispatchEvent(new Event('visibilitychange'));
  window.dispatchEvent(new Event('online'));
  deliver({ userId: 1, url: '/.well-known/mercure', topic: 'private', token: 'jwt' });
  await flush();
  expect(mocks.subscribe).not.toHaveBeenCalled();
});

const caret = { region: 'content', selector: 'textarea[name="notes"]', start: 1, end: 3 };
const sent = () => JSON.parse(fetcher.mock.calls.at(-1)[1].body);
function notes() {
  document.querySelector('main').insertAdjacentHTML('beforeend', '<textarea name="notes">hello world</textarea>');
  return document.querySelector('textarea');
}

// Past the 100 ms send throttle; the animation-frame stub also advances `now`.
async function tick() {
  now += 200;
  await vi.advanceTimersByTimeAsync(200);
}

test('sends the shape the sender sees and draws it from the platform set at its hotspot', async () => {
  await start();
  document.querySelector('button').style.cursor = 'pointer';
  pointer('pointermove');
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', shape: 'pointer', caret: null });
  receive({ style: 'windows', shape: 'pointer' });
  const cursor = document.querySelector('.collaborate-cursor');
  expect(cursor.dataset.shape).toBe('pointer');
  expect(cursor.querySelector('path').getAttribute('d')).toBe(CURSOR_SHAPES.windows.pointer.d);
  expect(cursor.querySelector('path').hasAttribute('transform')).toBe(false);
  const [x, y] = shapeHotspot(CURSOR_SHAPES.windows.pointer);
  expect(cursor.querySelector('svg').style.translate).toBe(`${(-x * 0.75).toFixed(2)}px ${(-y * 0.75).toFixed(2)}px`);
  receive({ sequence: 2, style: 'ubuntu', shape: 'ns-resize' });
  expect(cursor.querySelector('path').getAttribute('transform')).toBe('rotate(90 16 16)');
  expect(cursor.querySelector('svg').style.translate).toBe('-12.00px -12.00px');
  receive({ sequence: 3, style: 'touch', shape: 'text' });
  expect(cursor.querySelector('path').hasAttribute('transform')).toBe(false);
  expect(cursor.querySelector('svg').style.translate).toBe('');
  receive({ sequence: 4, shape: 'url(evil.png)', label: 'rejected' });
  receive({ sequence: 5, shape: undefined, label: 'rejected' });
  expect(cursor.textContent).toBe('<b>Alice</b>');
});

test('shares the focused field caret, keeps it while the pointer is away and clears it on blur', async () => {
  await start();
  const textarea = notes();
  textarea.focus();
  textarea.setSelectionRange(1, 3);
  document.dispatchEvent(new Event('selectionchange'));
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', anchor: null, caret });
  const posts = () => fetcher.mock.calls.filter(([url]) => url === '/events').length;
  const before = posts();
  textarea.setSelectionRange(1, 3);
  document.dispatchEvent(new Event('selectionchange'));
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
  await tick();
  expect(posts()).toBe(before);
  textarea.setSelectionRange(4, 4);
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
  await tick();
  expect(sent().caret).toMatchObject({ start: 4, end: 4 });
  pointer('pointermove');
  document.documentElement.dispatchEvent(new Event('pointerleave'));
  await tick();
  expect(sent()).toMatchObject({ kind: 'hide', anchor: null, caret: { start: 4, end: 4 } });
  textarea.blur();
  await flush();
  await tick();
  expect(sent()).toMatchObject({ kind: 'hide', caret: null });
  pointer('pointermove');
  textarea.focus();
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', caret: { start: 4 } });
  expect(sent().anchor).not.toBeNull();
});

test('clears its caret on hidden tabs and pagehide and republishes it on return', async () => {
  await start();
  notes().focus();
  await tick();
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  document.dispatchEvent(new Event('visibilitychange'));
  expect(sent()).toMatchObject({ kind: 'leave', caret: null });
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.dispatchEvent(new Event('visibilitychange'));
  await tick();
  expect(sent()).toMatchObject({ kind: 'hello', caret: { selector: 'textarea[name="notes"]' } });
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', caret: { selector: 'textarea[name="notes"]' } });
  window.dispatchEvent(new Event('pagehide'));
  expect(sent()).toMatchObject({ kind: 'leave', shape: 'default', anchor: null, caret: null });
});

test('draws peer carets with a selection tint and a fading name flag, independently of the pointer', async () => {
  await start();
  const textarea = notes();
  document.elementFromPoint = vi.fn(() => textarea);
  vi.spyOn(Element.prototype, 'getClientRects').mockReturnValue([
    { left: 10, top: 10, width: 30, height: 16, right: 40, bottom: 26 },
  ]);
  receive({ caret });
  await vi.advanceTimersByTimeAsync(20);
  const view = document.querySelector('.collaborate-caret');
  expect(view.hidden).toBe(false);
  expect(view.dataset.color).toBe('purple');
  expect(view.querySelector('.collaborate-caret__label').textContent).toBe('<b>Alice</b>');
  expect(view.querySelector('.collaborate-caret__bar').style.height).toBe('100px');
  expect(view.querySelector('.collaborate-caret__selection').children).toHaveLength(1);
  expect(view.classList.contains('collaborate-caret--labelled')).toBe(true);
  await vi.advanceTimersByTimeAsync(2000);
  expect(view.classList.contains('collaborate-caret--labelled')).toBe(false);
  receive({ sequence: 2, caret: { ...caret } });
  expect(view.classList.contains('collaborate-caret--labelled')).toBe(false);
  receive({ sequence: 3, kind: 'hide', anchor: null, caret: { ...caret, start: 3 } });
  expect(view.classList.contains('collaborate-caret--labelled')).toBe(true);
  expect(document.querySelector('.collaborate-cursor')).toBeNull();
  Element.prototype.getClientRects.mockReturnValue([]);
  receive({ sequence: 4, anchor: null, caret: { ...caret, start: 3, end: 3 } });
  await vi.advanceTimersByTimeAsync(20);
  expect(view.querySelector('.collaborate-caret__selection').children).toHaveLength(0);
  document.elementFromPoint = vi.fn(() => document.body);
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
  await vi.advanceTimersByTimeAsync(20);
  expect(view.hidden).toBe(true);
  vi.stubGlobal('innerWidth', 100);
  document.elementFromPoint = vi.fn(() => textarea);
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
  await vi.advanceTimersByTimeAsync(20);
  expect(view.dataset.edge).toBe('right');
  receive({ userId: 9, kind: 'hide', anchor: null });
  receive({ sequence: 5, kind: 'move' });
  expect(document.querySelector('.collaborate-cursor')).not.toBeNull();
  expect(document.querySelector('.collaborate-caret')).toBeNull();
  receive({ sequence: 6, caret });
  receive({ sequence: 7, kind: 'heartbeat', anchor: null, caret: null });
  expect(instance.peers.size).toBe(0);
  expect(document.querySelector('[data-collaborate-layer]').children).toHaveLength(0);
});

test('rejects invalid carets and lets a typing tab take over the cursor', async () => {
  await start();
  for (const invalid of [
    'fake',
    { ...caret, selector: 1 },
    { ...caret, selector: 'x'.repeat(1025) },
    { ...caret, region: 'invalid' },
    { ...caret, start: -1 },
    { ...caret, end: 1.5 },
    { ...caret, end: 10485761 },
  ])
    receive({ caret: invalid });
  expect(instance.peers.size).toBe(0);
  notes().focus();
  await tick();
  receive({ userId: 3, kind: 'hello', anchor: null });
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', anchor: null, caret: { start: 0 } });
  receive();
  receive({ clientId: 'another-client-123', kind: 'move', anchor: null, caret });
  expect(instance.peers.get(2).clientId).toBe('another-client-123');
  expect(document.querySelector('.collaborate-cursor')).toBeNull();
  instance.stop();
  expect(document.querySelector('[data-collaborate-layer]').children).toHaveLength(0);
});

test('measures the pointer once per sent event, however many moves the throttle coalesced', async () => {
  await start();
  now = 1000;
  await vi.advanceTimersByTimeAsync(100);
  mocks.pointerShape.mockClear();
  const before = fetcher.mock.calls.length;
  for (let move = 0; move < 5; move++) pointer('pointermove');
  expect(mocks.pointerShape).toHaveBeenCalledOnce();
  for (let move = 0; move < 5; move++) pointer('pointermove');
  now = 1200;
  await vi.advanceTimersByTimeAsync(100);
  expect(mocks.pointerShape).toHaveBeenCalledTimes(2);
  expect(fetcher.mock.calls.length - before).toBe(2);
  expect(sent()).toMatchObject({ kind: 'move', anchor: { selector: anchor.selector } });
  // A pointer over an element removed before the send is measured where it stands.
  document.querySelector('button').remove();
  pointer('pointermove', 'mouse', document.querySelector('main'));
  document.querySelector('main').remove();
  now = 1400;
  await vi.advanceTimersByTimeAsync(100);
  expect(document.elementFromPoint).toHaveBeenCalledWith(50, 25);
});

test('a peer that moves is measured in the next frame, with none of the others', async () => {
  await start();
  receive({});
  receive({ userId: 3, clientId: 'bob-client-1234567', label: 'Bob' });
  await vi.advanceTimersByTimeAsync(220);
  document.elementFromPoint.mockClear();
  receive({ sequence: 2, anchor: { ...anchor, x: 0.9 } });
  // Not as the event comes, between two frames' writes.
  expect(document.elementFromPoint).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(20);
  expect(document.elementFromPoint).toHaveBeenCalledOnce();
  // The frames of its glide measure nothing.
  await vi.advanceTimersByTimeAsync(200);
  expect(document.elementFromPoint).toHaveBeenCalledOnce();
  window.dispatchEvent(new Event('resize'));
  await vi.advanceTimersByTimeAsync(20);
  expect(document.elementFromPoint).toHaveBeenCalledTimes(3);
});

test('a frame measures the carets that moved, and every caret once the page moved', async () => {
  await start();
  notes();
  receive({ caret, anchor });
  await vi.advanceTimersByTimeAsync(20);
  mocks.resolveCaret.mockClear();
  // The pointer moves, its caret stays: neither the event nor the animation's frames measure the caret.
  receive({ sequence: 2, caret, anchor: { ...anchor, x: 0.9, y: 0.9 } });
  await vi.advanceTimersByTimeAsync(220);
  expect(mocks.resolveCaret).not.toHaveBeenCalled();
  receive({ sequence: 3, caret: { ...caret, end: 4 }, anchor: { ...anchor, x: 0.9, y: 0.9 } });
  await vi.advanceTimersByTimeAsync(20);
  expect(mocks.resolveCaret).toHaveBeenCalledOnce();
  window.dispatchEvent(new Event('resize'));
  await vi.advanceTimersByTimeAsync(20);
  expect(mocks.resolveCaret).toHaveBeenCalledTimes(2);
});

test('shares the shared form control it is in, a choice included, so peers can mark its field', async () => {
  await start();
  document
    .querySelector('main')
    .insertAdjacentHTML(
      'beforeend',
      '<form data-collaborate="{}"><div class="form-group"><select name="Record[kind]"><option>a</option></select></div></form><select name="outside"></select>'
    );
  const select = document.querySelector('select[name="Record[kind]"]');
  select.focus();
  await tick();
  expect(sent()).toMatchObject({ kind: 'move', anchor: null, caret: null, field: 'Record[kind]' });
  document.querySelector('select[name="outside"]').focus();
  await flush();
  await tick();
  expect(sent()).toMatchObject({ kind: 'hide', field: null });
  select.focus();
  await tick();
  document.dispatchEvent(new Event('visibilitychange'));
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  document.dispatchEvent(new Event('visibilitychange'));
  expect(sent()).toMatchObject({ kind: 'leave', field: null });
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  document.dispatchEvent(new Event('visibilitychange'));
  await flush();
  await tick();
  expect(sent()).toMatchObject({ field: 'Record[kind]' });
});

test('outlines the field a peer is in with its colour and name, and clears it when the peer moves on', async () => {
  await start();
  document
    .querySelector('main')
    .insertAdjacentHTML(
      'beforeend',
      '<form data-collaborate="{}"><div class="form-group" id="kind"><input type="radio" name="Record[kind]" value="a"><input type="radio" name="Record[kind]" value="b"></div>' +
        '<div class="form-group" id="title"><input name="Record[title]"></div><input name="Record[bare]"></form>'
    );
  receive({ anchor: null, field: 'Record[kind]' });
  const kind = document.querySelector('#kind');
  expect(kind.dataset.peerFocus).toBe('purple');
  expect(kind.dataset.peerFocusLabel).toBe('<b>Alice</b>');
  // The pointer leaves; the mark stays while the field keeps focus.
  receive({ sequence: 2, kind: 'hide', anchor: null, field: 'Record[kind]' });
  expect(kind.dataset.peerFocus).toBe('purple');
  receive({
    userId: 3,
    clientId: 'bob-client-1234567',
    color: 'teal',
    label: 'Bob',
    anchor: null,
    field: 'Record[kind]',
  });
  expect(kind.dataset.peerFocus).toBe('purple');
  expect(kind.dataset.peerFocusLabel).toBe('<b>Alice</b>, Bob');
  receive({ sequence: 3, field: 'Record[title]' });
  expect(kind.dataset.peerFocus).toBe('teal');
  expect(document.querySelector('#title').dataset.peerFocus).toBe('purple');
  // A replay that redraws the field is marked again on the next layout frame.
  const title = document.querySelector('#title');
  const redrawn = title.cloneNode(true);
  redrawn.removeAttribute('data-peer-focus');
  redrawn.removeAttribute('data-peer-focus-label');
  title.replaceWith(redrawn);
  expect(redrawn.dataset.peerFocus).toBeUndefined();
  document.body.dispatchEvent(new Event('scroll'));
  await vi.advanceTimersByTimeAsync(20);
  expect(document.querySelector('#title').dataset.peerFocus).toBe('purple');
  // A control outside any group is marked itself; an unknown one marks nothing.
  receive({ sequence: 4, field: 'Record[bare]' });
  expect(document.querySelector('[name="Record[bare]"]').dataset.peerFocus).toBe('purple');
  receive({ sequence: 5, field: 'Record[missing]' });
  expect(document.querySelectorAll('[data-peer-focus]')).toHaveLength(1);
  receive({ userId: 3, clientId: 'bob-client-1234567', sequence: 2, kind: 'leave', anchor: null, field: null });
  expect(document.querySelectorAll('[data-peer-focus]')).toHaveLength(0);
  expect(kind.hasAttribute('data-peer-focus-label')).toBe(false);
});

test('rejects a field that is not a short control name', async () => {
  await start();
  for (const field of ['', 7, 'x'.repeat(513)]) receive({ anchor: null, field });
  expect(instance.peers.size).toBe(0);
});

test('starts nothing on a layer without an event URL: cursors are disabled', () => {
  const layer = document.querySelector('[data-collaborate-layer]');
  delete layer.dataset.eventUrl;
  expect(begin(layer)).toBeNull();
  expect(layer.dataset.initialized).toBeUndefined();
  expect(fetcher).not.toHaveBeenCalled();
});

test('throttles, beats, expires and colours as the layer configures', async () => {
  const layer = document.querySelector('[data-collaborate-layer]');
  layer.dataset.config = JSON.stringify({
    throttle: 300,
    heartbeat: 1000,
    expiry: 4000,
    maxAvatars: 1,
    colors: ['lime'],
  });
  instance = initCursors(layer, fetcher, new CollaborateHub(layer, fetcher));
  await flush();
  const events = () => fetcher.mock.calls.filter(([url]) => url === '/events').map(([, init]) => JSON.parse(init.body));
  expect(events().map((sent) => sent.kind)).toEqual(['hello']);
  pointer('pointermove');
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  expect(events()).toHaveLength(1);
  now = 300;
  await vi.advanceTimersByTimeAsync(200);
  expect(events().at(-1).kind).toBe('move');
  // Only the configured colours are drawn.
  receive();
  expect(instance.peers.size).toBe(0);
  receive({ color: 'lime' });
  receive({ userId: 3, color: 'lime' });
  expect(instance.peers.size).toBe(2);
  const strip = document.querySelector('[data-collaborate-presence]');
  expect(strip.querySelectorAll('.collaborate-presence__user')).toHaveLength(1);
  expect(strip.querySelector('.collaborate-presence__more').textContent).toBe('+1');
  // An event older than the expiry, by the newest server clock, is stale.
  receive({ userId: 4, color: 'lime', timestamp: Date.now() - 4001 });
  expect(instance.peers.has(4)).toBe(false);
  const beats = events().length;
  now = 1300;
  await vi.advanceTimersByTimeAsync(1000);
  expect(events().length).toBeGreaterThan(beats);
  await vi.advanceTimersByTimeAsync(4000);
  expect(instance.peers.size).toBe(0);
  expect(strip.hidden).toBe(true);
});

test('turns the label of a cursor near the right or bottom edge of the window back into the page', async () => {
  await start();
  document.querySelector('button').getBoundingClientRect = () => ({
    left: innerWidth - 100,
    top: innerHeight - 20,
    width: 100,
    height: 20,
    right: innerWidth,
    bottom: innerHeight,
  });
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const cursor = document.querySelector('.collaborate-cursor');
  expect(cursor.dataset.edge).toBe('right');
  expect(cursor.dataset.bottom).toBe('true');
});

test('shows a cursor whose anchor appears after its move right at that anchor, without a glide', async () => {
  await start();
  const button = document.querySelector('button');
  button.remove();
  receive();
  await vi.advanceTimersByTimeAsync(20);
  const cursor = document.querySelector('.collaborate-cursor');
  expect(cursor.hidden).toBe(true);
  expect(instance.peers.get(2).point).toBeFalsy();
  document.querySelector('main').append(button);
  await vi.advanceTimersByTimeAsync(20);
  expect(cursor.hidden).toBe(false);
  expect(instance.peers.get(2).point).toEqual({ x: 100, y: 50 });
  expect(cursor.style.transform).toBe('translate3d(100px, 50px, 0)');
});

test('keeps a mouse pointer shown after its button is released, unlike a lifted finger', async () => {
  await start();
  pointer('pointerdown');
  pointer('pointerup');
  now = 1000;
  await vi.advanceTimersByTimeAsync(1000);
  const kinds = fetcher.mock.calls.filter(([url]) => url === '/events').map(([, init]) => JSON.parse(init.body).kind);
  expect(kinds).not.toContain('hide');
  expect(kinds.at(-1)).toBe('move');
});

test('sends no heartbeat while the tab is hidden', async () => {
  await start();
  pointer('pointermove');
  now = 100;
  await vi.advanceTimersByTimeAsync(100);
  const sent = () => fetcher.mock.calls.filter(([url]) => url === '/events').length;
  const before = sent();
  Object.defineProperty(document, 'hidden', { configurable: true, value: true });
  now = 6000;
  await vi.advanceTimersByTimeAsync(5000);
  expect(sent()).toBe(before);
  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  now = 11000;
  await vi.advanceTimersByTimeAsync(5000);
  expect(sent()).toBe(before + 1);
  expect(JSON.parse(fetcher.mock.calls.at(-1)[1].body).kind).toBe('heartbeat');
});
