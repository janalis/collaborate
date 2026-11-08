import { CollaborateHub, hubFor, layerConfig, sources } from '../../assets/hub.js';

let layer;
let subscribe;
let streams;
let transports;

const session = (overrides = {}) =>
  Response.json({
    url: '/mercure',
    topics: ['cursors'],
    token: 'jwt',
    userId: 7,
    resend: [],
    denied: [],
    ...overrides,
  });
const body = (fetcher, call = -1) => JSON.parse(fetcher.mock.calls.at(call)[1].body);
const member = (channel, overrides = {}) => ({
  channel,
  receive: vi.fn(),
  opened: vi.fn(),
  closed: vi.fn(),
  denied: vi.fn(),
  ...overrides,
});

beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML =
    '<div data-collaborate-layer data-grant="page" data-csrf="csrf" data-session-url="/session"></div>';
  layer = document.querySelector('[data-collaborate-layer]');
  streams = [];
  // A stream stays open until the test fails it.
  transports = { mercure: async () => subscribe, websocket: async () => subscribe };
  subscribe = vi.fn(
    (url, options) =>
      new Promise((_resolve, fail) => {
        streams.push({ url, options, fail });
      })
  );
});
afterEach(() => {
  vi.useRealTimers();
  document.body.innerHTML = '';
});

test('gives each cursor layer one hub, and none without a session URL', () => {
  expect(hubFor(null)).toBeNull();
  expect(hubFor(document.createElement('div'))).toBeNull();
  expect(hubFor(layer)).toBe(hubFor(layer));
  expect(hubFor()).toBe(hubFor(layer));
});

test('subscribes once for every member that joined on the same task, with their topics and the page grant', async () => {
  const fetcher = vi.fn(async () => session({ topics: ['cursors', 'updates', 'draft:a'] }));
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  const updates = member('updates');
  const draft = member('draft', { draft: 'a', grant: vi.fn((full) => (full ? 'full.sig' : 'sig')) });
  hub.join(cursors);
  hub.join(updates);
  hub.join(draft);
  await vi.advanceTimersByTimeAsync(0);
  expect(fetcher).toHaveBeenCalledOnce();
  expect(fetcher.mock.calls[0][0]).toBe('/session');
  expect(fetcher.mock.calls[0][1].headers['X-CSRF-Token']).toBe('csrf');
  expect(body(fetcher)).toEqual({ grant: 'page', updates: true, notifications: false, drafts: ['sig'] });
  expect(subscribe).toHaveBeenCalledOnce();
  expect(streams[0].url).toBe('/mercure');
  expect(streams[0].options).toMatchObject({ token: 'jwt', topics: ['cursors', 'updates', 'draft:a'], fetch: fetcher });
  await streams[0].options.onopen();
  for (const joined of [cursors, updates, draft])
    expect(joined.opened).toHaveBeenCalledWith(expect.objectContaining({ userId: 7 }));
});

test('routes each message to the members of its channel, a draft message to its own draft', async () => {
  const hub = new CollaborateHub(
    layer,
    vi.fn(async () => session({ denied: undefined })),
    transports
  );
  const cursors = member('cursors');
  const first = member('draft', { draft: 'a', grant: () => 'a' });
  const second = member('draft', { draft: 'b', grant: () => 'b' });
  for (const joined of [cursors, first, second]) hub.join(joined);
  await vi.advanceTimersByTimeAsync(0);
  const send = (data) => streams[0].options.onmessage({ data: JSON.stringify(data) });
  send({ channel: 'cursors', kind: 'move' });
  send({ channel: 'draft', draft: 'a', kind: 'append' });
  send({ channel: 'draft', kind: 'record' });
  streams[0].options.onmessage({ data: 'not json' });
  send(null);
  expect(cursors.receive).toHaveBeenCalledOnce();
  expect(cursors.receive.mock.calls[0][1]).toEqual({ channel: 'cursors', kind: 'move' });
  expect(first.receive.mock.calls.map(([, data]) => data.kind)).toEqual(['append', 'record']);
  expect(second.receive.mock.calls.map(([, data]) => data.kind)).toEqual(['record']);
});

test('asks for the notifications topic while a member follows it', async () => {
  const fetcher = vi.fn(async () => session({ topics: ['notifications:7'] }));
  const hub = new CollaborateHub(layer, fetcher, transports);
  const bell = member('notifications');
  hub.join(bell);
  await vi.advanceTimersByTimeAsync(0);
  expect(body(fetcher)).toMatchObject({ updates: false, notifications: true });
  await streams[0].options.onmessage({ data: JSON.stringify({ channel: 'notifications' }) });
  expect(bell.receive).toHaveBeenCalledWith(expect.anything(), { channel: 'notifications' });
});

test('reconnects without a member that left, closes with the last one, and renews every four minutes', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const leaveCursors = hub.join(member('cursors'));
  const leaveUpdates = hub.join(member('updates'));
  await vi.advanceTimersByTimeAsync(0);
  await vi.advanceTimersByTimeAsync(240_000);
  expect(subscribe).toHaveBeenCalledTimes(2);
  expect(streams[0].options.signal.aborted).toBe(true);
  leaveUpdates();
  leaveUpdates();
  await vi.advanceTimersByTimeAsync(0);
  expect(body(fetcher)).toMatchObject({ updates: false });
  expect(subscribe).toHaveBeenCalledTimes(3);
  leaveCursors();
  expect(streams[2].options.signal.aborted).toBe(true);
  await vi.advanceTimersByTimeAsync(300_000);
  expect(subscribe).toHaveBeenCalledTimes(3);
  await hub.connect();
  expect(fetcher).toHaveBeenCalledTimes(3);
});

test('sends unknown draft signatures in full once, and drops the drafts the server refused', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(session({ resend: [0] }))
    .mockResolvedValueOnce(session({ resend: [0], denied: [1] }));
  // The stream fails once: only the drafts still followed hear of it.
  subscribe.mockImplementationOnce(async (url, options) => {
    streams.push({ url, options });
    throw new Error('stream failed');
  });
  const hub = new CollaborateHub(layer, fetcher, transports);
  const known = member('draft', { draft: 'a', grant: (full) => (full ? 'a.full' : 'a') });
  const refused = member('draft', { draft: 'b', grant: (full) => (full ? 'b.full' : 'b') });
  hub.join(known);
  hub.join(refused);
  await vi.advanceTimersByTimeAsync(0);
  expect(fetcher.mock.calls.map((_call, index) => body(fetcher, index).drafts)).toEqual([
    ['a', 'b'],
    ['a.full', 'b.full'],
  ]);
  expect(refused.denied).toHaveBeenCalledOnce();
  expect(known.denied).not.toHaveBeenCalled();
  expect(known.closed).toHaveBeenCalledOnce();
  expect(refused.closed).not.toHaveBeenCalled();
  await streams[0].options.onopen();
  expect(known.opened).toHaveBeenCalledOnce();
  expect(refused.opened).not.toHaveBeenCalled();
  expect(hub.members.has(refused)).toBe(false);
});

test.each([
  ['a refused page', new Response('{}', { status: 403 })],
  ['an expired session', new Response('{}', { status: 401 })],
  ['a page that no longer exists', new Response('{}', { status: 404 })],
  ['a sign-in redirect', { ok: true, status: 200, redirected: true, json: async () => ({}) }],
])('stops every member on %s, once renewing from the page fails too', async (_case, response) => {
  const fetcher = vi.fn(async () => response);
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.denied).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(60_000);
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(fetcher.mock.calls[1][0]).toBe(location.href);
  // The server keeps the flash messages a render for a renewal reads.
  expect(fetcher.mock.calls[1][1].headers).toEqual({ 'X-Collaborate-Renew': '1' });
  expect(subscribe).not.toHaveBeenCalled();
});

const page = (attributes) =>
  new Response(`<html><body><div data-collaborate-layer ${attributes}></div></body></html>`, {
    headers: { 'Content-Type': 'text/html' },
  });

test('renews a refused page grant and CSRF token once from the page, and keeps its members', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(session())
    .mockResolvedValueOnce(new Response('{}', { status: 403 }))
    .mockResolvedValueOnce(page('data-grant="fresh" data-csrf="renewed"'))
    .mockResolvedValueOnce(session())
    .mockResolvedValueOnce(new Response('{}', { status: 403 }))
    .mockResolvedValueOnce(page('data-grant="later" data-csrf="again"'))
    .mockResolvedValueOnce(session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const updates = member('updates');
  hub.join(updates);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen();
  await vi.advanceTimersByTimeAsync(240_000);
  expect(fetcher.mock.calls[3][1].headers['X-CSRF-Token']).toBe('renewed');
  expect(body(fetcher, 3).grant).toBe('fresh');
  expect(layer.dataset).toMatchObject({ grant: 'fresh', csrf: 'renewed' });
  // A session that answers resets the renewal: a refusal hours later renews again.
  await vi.advanceTimersByTimeAsync(240_000);
  expect(body(fetcher, 6).grant).toBe('later');
  expect(updates.denied).not.toHaveBeenCalled();
  expect(hub.members.has(updates)).toBe(true);
});

test.each([
  ['a page without a cursor layer', () => page('data-grant="" data-csrf="renewed"')],
  ['a page that fails to load', () => Promise.reject(new TypeError('offline'))],
  ['a page that refuses it again', () => page('data-grant="fresh" data-csrf="renewed"')],
])('stops every member once renewal fails: %s', async (_case, renewal) => {
  const refused = () => new Response('{}', { status: 403 });
  const fetcher = vi
    .fn()
    .mockImplementationOnce(async () => refused())
    .mockImplementationOnce(renewal);
  fetcher.mockImplementation(async () => refused());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const updates = member('updates');
  hub.join(updates);
  await vi.advanceTimersByTimeAsync(0);
  expect(updates.denied).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(60_000);
  expect(fetcher.mock.calls.length).toBeLessThanOrEqual(3);
});

test('a renewal answered after its connection was replaced reports nothing', async () => {
  let answer;
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response('{}', { status: 403 }))
    .mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)))
    .mockResolvedValue(session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const updates = member('updates');
  hub.join(updates);
  await vi.advanceTimersByTimeAsync(0);
  hub.join(member('cursors'));
  await vi.advanceTimersByTimeAsync(0);
  answer(page('data-grant="fresh" data-csrf="renewed"'));
  await vi.advanceTimersByTimeAsync(0);
  expect(updates.denied).not.toHaveBeenCalled();
  expect(subscribe).toHaveBeenCalledOnce();
  // The stale answer spent no renewal: the next refusal still renews.
  expect(hub.renewed).toBe(false);
});

test('closes the open stream when a renewal is refused', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(session())
    .mockResolvedValueOnce(new Response('{}', { status: 403 }));
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen();
  await vi.advanceTimersByTimeAsync(240_000);
  expect(cursors.denied).toHaveBeenCalledOnce();
  expect(streams[0].options.signal.aborted).toBe(true);
  await vi.advanceTimersByTimeAsync(300_000);
  expect(fetcher).toHaveBeenCalledTimes(3);
});

test('backs off and retries an unavailable session', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response('{}', { status: 503 }))
    .mockImplementation(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscribe).toHaveBeenCalledOnce();
  expect(hub.failures).toBe(1);
  streams[0].options.onopen();
  expect(hub.failures).toBe(0);
});

test('a failed subscription reports the members closed and tries again', async () => {
  const fetcher = vi.fn(async () => session());
  subscribe.mockRejectedValueOnce(new Error('stream failed'));
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  // A member may follow the stream without hearing of its state.
  hub.join({ channel: 'updates', receive: vi.fn() });
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscribe).toHaveBeenCalledTimes(2);
  await streams[0].options.onopen();
  expect(cursors.opened).toHaveBeenCalledOnce();
});

test('ignores a session answered after its connection was replaced', async () => {
  let answer;
  let decode;
  const fetcher = vi
    .fn()
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          answer = resolve;
        })
    )
    .mockImplementationOnce(async () => ({
      ok: true,
      json: () =>
        new Promise((resolve) => {
          decode = resolve;
        }),
    }))
    .mockImplementation(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const leave = hub.join(member('cursors'));
  await vi.advanceTimersByTimeAsync(0);
  hub.join(member('updates'));
  await vi.advanceTimersByTimeAsync(0);
  answer(session());
  await vi.advanceTimersByTimeAsync(0);
  expect(subscribe).not.toHaveBeenCalled();
  leave();
  await vi.advanceTimersByTimeAsync(0);
  decode({ url: '/obsolete', topics: ['old'], token: 'old' });
  await vi.advanceTimersByTimeAsync(0);
  expect(subscribe).toHaveBeenCalledOnce();
  expect(streams[0].options.topics).toEqual(['cursors']);
});

test('a failure after its connection was replaced reports nothing', async () => {
  let fail;
  const fetcher = vi
    .fn()
    .mockImplementationOnce(
      () =>
        new Promise((_resolve, reject) => {
          fail = reject;
        })
    )
    .mockImplementation(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  hub.join(member('updates'));
  await vi.advanceTimersByTimeAsync(0);
  fail(new Error('late'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).not.toHaveBeenCalled();
});

test('keeps the open stream until the one replacing it opens, so no event falls between them', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  const leaveCursors = hub.join(cursors);
  hub.join(member('updates'));
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen();
  // The renewal subscribes again: the first stream still delivers until the second opens.
  await vi.advanceTimersByTimeAsync(240_000);
  expect(streams).toHaveLength(2);
  expect(streams[0].options.signal.aborted).toBe(false);
  await streams[1].options.onopen();
  expect(streams[0].options.signal.aborted).toBe(true);
  // A member leaving does the same.
  leaveCursors();
  await vi.advanceTimersByTimeAsync(0);
  expect(streams[1].options.signal.aborted).toBe(false);
  // A pending replacement that a newer one supersedes before opening is dropped; the open stream stays.
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  expect(streams[2].options.signal.aborted).toBe(true);
  expect(streams[1].options.signal.aborted).toBe(false);
  await streams[3].options.onopen();
  expect(streams[1].options.signal.aborted).toBe(true);
});

test('retries a replacement that fails while the open stream goes on carrying the topics', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen();
  subscribe.mockRejectedValueOnce(new Error('stream failed'));
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).not.toHaveBeenCalled();
  expect(streams[0].options.signal.aborted).toBe(false);
  await vi.advanceTimersByTimeAsync(1000);
  await streams[1].options.onopen();
  expect(streams[0].options.signal.aborted).toBe(true);
  // The open stream failing while a newer connection is under way leaves the report to that one,
  // which reports the loss before its opening: what was published in between is missed.
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[1].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).not.toHaveBeenCalled();
  const opened = cursors.opened.mock.calls.length;
  await streams[2].options.onopen();
  expect(cursors.closed).toHaveBeenCalledOnce();
  expect(cursors.opened).toHaveBeenCalledTimes(opened + 1);
  expect(cursors.closed.mock.invocationCallOrder[0]).toBeLessThan(cursors.opened.mock.invocationCallOrder.at(-1));
  await streams[2].options.onopen();
  expect(cursors.closed).toHaveBeenCalledOnce();
  // The connection that opened failing reports the loss and retries.
  streams[2].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledTimes(2);
  await vi.advanceTimersByTimeAsync(1000);
  expect(streams).toHaveLength(4);
});

test('reports a loss once when the open stream and then its replacement fail', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen();
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[0].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  streams[1].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  await streams[2].options.onopen();
  expect(cursors.closed).toHaveBeenCalledOnce();
  // Closing forgets a loss no stream reported yet.
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[2].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  hub.close();
  hub.connect();
  await vi.advanceTimersByTimeAsync(0);
  await streams.at(-1).options.onopen();
  expect(cursors.closed).toHaveBeenCalledOnce();
});

test.each([
  ['no layer', null, {}],
  ['no data-config', '<div></div>', {}],
  ['malformed JSON', `<div data-config="{"></div>`, {}],
  ['an array', `<div data-config="[1]"></div>`, {}],
  ['null', `<div data-config="null"></div>`, {}],
  ['settings', `<div data-config='{"renew":1000,"colors":["red"]}'></div>`, { renew: 1000, colors: ['red'] }],
])('reads the layer settings from %s', (_case, html, expected) => {
  const template = document.createElement('template');
  template.innerHTML = html ?? '';
  expect(layerConfig(template.content.firstElementChild)).toEqual(expected);
});

test('renews the stream on the interval the layer configures', async () => {
  layer.dataset.config = JSON.stringify({ renew: 60_000 });
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  hub.join(member('cursors'));
  await vi.advanceTimersByTimeAsync(0);
  expect(subscribe).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(59_999);
  expect(subscribe).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1);
  expect(subscribe).toHaveBeenCalledTimes(2);
});

test('renews from the page with the renewal header, reading the fresh layer', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response(null, { status: 403 }))
    .mockResolvedValueOnce(
      new Response('<main><div data-collaborate-layer data-grant="fresh" data-csrf="renewed"></div></main>', {
        status: 200,
      })
    )
    .mockImplementation(async () => session());
  const hub = new CollaborateHub(layer, fetcher, transports);
  hub.join(member('cursors'));
  await vi.advanceTimersByTimeAsync(0);
  expect(fetcher.mock.calls[1][0]).toBe(location.href);
  expect(fetcher.mock.calls[1][1]).toEqual({
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { 'X-Collaborate-Renew': '1' },
  });
  expect(layer.dataset).toMatchObject({ grant: 'fresh', csrf: 'renewed' });
});

describe('transports', () => {
  let sockets;
  let websocket;
  let renew;
  const socketSession = (overrides = {}) =>
    session({ transport: 'websocket', url: '/collaborate-hub', token: 'first', ...overrides });
  // A socket opens when told, with a handle renewing it in place.
  const open = (index = -1) => sockets.at(index).options.onopen({ url: sockets.at(index).url, renew });
  const closed = (code) => Object.assign(new Error('closed'), { code });

  beforeEach(() => {
    sockets = [];
    renew = vi.fn(async () => {});
    websocket = vi.fn(
      (url, options) =>
        new Promise((resolve, fail) => {
          sockets.push({ url, options, resolve, fail });
        })
    );
    transports = { mercure: vi.fn(async () => subscribe), websocket: vi.fn(async () => websocket) };
  });

  test('loads each source from its own module', async () => {
    const { mercureSource } = await import('../../assets/hub/mercure.js');
    const { websocketSource } = await import('../../assets/hub/websocket.js');
    expect(await sources.mercure()).toBe(mercureSource);
    expect(await sources.websocket()).toBe(websocketSource);
  });

  test('follows the transport the session names, Mercure when it names none, loading each module once', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(session())
      .mockResolvedValueOnce(socketSession())
      .mockImplementation(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    // Both modules load while the first session is asked.
    expect(transports.mercure).toHaveBeenCalledOnce();
    expect(transports.websocket).toHaveBeenCalledOnce();
    expect(subscribe).toHaveBeenCalledOnce();
    expect(websocket).not.toHaveBeenCalled();
    streams[0].options.onopen();
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(websocket).toHaveBeenCalledOnce();
    expect(sockets[0].url).toBe('/collaborate-hub');
    expect(sockets[0].options).toMatchObject({ token: 'first', topics: ['cursors'] });
    expect(transports.mercure).toHaveBeenCalledOnce();
    expect(transports.websocket).toHaveBeenCalledOnce();
    open();
    expect(streams[0].options.signal.aborted).toBe(true);
    expect(hub.transport).toBe('websocket');
  });

  test('retries a transport the page does not know, and a module that failed to load', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(session({ transport: 'pigeon' }))
      .mockImplementation(async () => socketSession());
    transports.websocket
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockRejectedValueOnce(new TypeError('offline'));
    const hub = new CollaborateHub(layer, fetcher, transports);
    const cursors = member('cursors');
    hub.join(cursors);
    await vi.advanceTimersByTimeAsync(0);
    expect(cursors.closed).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1000);
    expect(websocket).not.toHaveBeenCalled();
    expect(cursors.closed).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(2000);
    expect(transports.websocket).toHaveBeenCalledTimes(3);
    expect(websocket).toHaveBeenCalledOnce();
  });

  test('a connection replaced while its module loads opens nothing; the newer one shares the load', async () => {
    let loaded;
    transports.websocket.mockImplementation(
      () =>
        new Promise((resolve) => {
          loaded = resolve;
        })
    );
    const hub = new CollaborateHub(
      layer,
      vi.fn(async () => socketSession()),
      transports
    );
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    loaded(websocket);
    await vi.advanceTimersByTimeAsync(0);
    expect(transports.websocket).toHaveBeenCalledOnce();
    expect(websocket).toHaveBeenCalledOnce();
    expect(sockets[0].options.signal.aborted).toBe(false);
  });

  test('renews an open socket in place, keeping it the one connection to retry', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(socketSession())
      .mockImplementation(async () => socketSession({ token: 'second', topics: ['cursors', 'updates'] }));
    const hub = new CollaborateHub(layer, fetcher, transports);
    const cursors = member('cursors');
    hub.join(cursors);
    await vi.advanceTimersByTimeAsync(0);
    open();
    expect(cursors.opened).toHaveBeenCalledOnce();
    const updates = member('updates');
    hub.join(updates);
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).toHaveBeenCalledOnce();
    expect(renew.mock.calls[0][0]).toBe('second');
    expect(renew.mock.calls[0][1]).toBeInstanceOf(AbortSignal);
    expect(websocket).toHaveBeenCalledOnce();
    expect(sockets[0].options.signal.aborted).toBe(false);
    expect(cursors.opened).toHaveBeenCalledTimes(2);
    expect(updates.opened).toHaveBeenCalledWith(expect.objectContaining({ topics: ['cursors', 'updates'] }));
    expect(hub.controller).toBe(hub.streaming);
    // The renewed socket lost: every member it carries hears of it, and a new one is asked for.
    sockets[0].fail(closed(1006));
    await vi.advanceTimersByTimeAsync(0);
    expect(cursors.closed).toHaveBeenCalledOnce();
    expect(updates.closed).toHaveBeenCalledOnce();
    expect(hub.live).toBeNull();
    await vi.advanceTimersByTimeAsync(1000);
    expect(websocket).toHaveBeenCalledTimes(2);
    open();
    expect(cursors.opened).toHaveBeenCalledTimes(3);
  });

  test('renews on the interval in place, and replaces a socket that refuses or no longer answers', async () => {
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    const cursors = member('cursors');
    hub.join(cursors);
    await vi.advanceTimersByTimeAsync(0);
    open();
    await vi.advanceTimersByTimeAsync(240_000);
    expect(renew).toHaveBeenCalledOnce();
    renew.mockRejectedValueOnce(closed(4401));
    await vi.advanceTimersByTimeAsync(240_000);
    expect(renew).toHaveBeenCalledTimes(2);
    expect(websocket).toHaveBeenCalledTimes(2);
    // The refusing socket carries the topics until its replacement opens.
    expect(sockets[0].options.signal.aborted).toBe(false);
    open();
    expect(sockets[0].options.signal.aborted).toBe(true);
    expect(cursors.closed).not.toHaveBeenCalled();
  });

  test('opens a new socket rather than renewing one of another URL or transport', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(socketSession())
      .mockResolvedValueOnce(socketSession({ url: 'wss://elsewhere.example/hub' }))
      .mockResolvedValueOnce(session({ url: 'wss://elsewhere.example/hub' }));
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    open();
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).not.toHaveBeenCalled();
    expect(websocket).toHaveBeenCalledTimes(2);
    open();
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).not.toHaveBeenCalled();
    expect(subscribe).toHaveBeenCalledOnce();
    // A Mercure stream opens with no handle: nothing is renewed in place after it.
    streams[0].options.onopen();
    expect(hub.live).toBeNull();
  });

  test('a renewal superseded by a newer connection reports nothing, answered or refused', async () => {
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    const cursors = member('cursors');
    hub.join(cursors);
    await vi.advanceTimersByTimeAsync(0);
    open();
    const answers = [];
    renew.mockImplementation(
      () =>
        new Promise((resolve, reject) => {
          answers.push({ resolve, reject });
        })
    );
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).toHaveBeenCalledTimes(2);
    expect(renew.mock.calls[0][1].aborted).toBe(true);
    answers[0].resolve();
    await vi.advanceTimersByTimeAsync(0);
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    answers[1].reject(new Error('cancelled'));
    await vi.advanceTimersByTimeAsync(0);
    expect(websocket).toHaveBeenCalledOnce();
    expect(cursors.opened).toHaveBeenCalledOnce();
    answers[2].resolve();
    await vi.advanceTimersByTimeAsync(0);
    expect(cursors.opened).toHaveBeenCalledTimes(2);
    expect(cursors.closed).not.toHaveBeenCalled();
  });

  test('a socket lost while it renews reports the loss once the replacement opens', async () => {
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    const cursors = member('cursors');
    hub.join(cursors);
    await vi.advanceTimersByTimeAsync(0);
    open();
    let refuse;
    renew.mockImplementationOnce(
      () =>
        new Promise((_resolve, reject) => {
          refuse = reject;
        })
    );
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    sockets[0].fail(closed(1006));
    await vi.advanceTimersByTimeAsync(0);
    expect(cursors.closed).not.toHaveBeenCalled();
    refuse(closed(1006));
    await vi.advanceTimersByTimeAsync(0);
    expect(websocket).toHaveBeenCalledTimes(2);
    open();
    expect(cursors.closed).toHaveBeenCalledOnce();
    expect(cursors.opened).toHaveBeenCalledTimes(2);
  });

  test('asks for a new session at once when the hub refuses the token (4401), once in a row', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(0);
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    open();
    sockets[0].fail(closed(4401));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(websocket).toHaveBeenCalledTimes(2);
    sockets[1].fail(closed(4401));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(500);
    expect(fetcher).toHaveBeenCalledTimes(3);
    // Opening again allows the next refusal its immediate retry.
    open();
    sockets[2].fail(closed(4401));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(4);
    vi.restoreAllMocks();
  });

  test('a Mercure stream failing as 4401 or 1008 backs off as any other', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(0);
    const fetcher = vi.fn(async () => session());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    streams[0].fail(closed(4401));
    await vi.advanceTimersByTimeAsync(499);
    expect(fetcher).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledTimes(2);
    streams[1].fail(closed(1008));
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledTimes(3);
    vi.restoreAllMocks();
  });

  test('waits the longest after a client error (1008)', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(0);
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    sockets[0].fail(closed(1008));
    await vi.advanceTimersByTimeAsync(14_999);
    expect(fetcher).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledTimes(2);
    vi.restoreAllMocks();
  });

  test.each([
    [0, 500],
    [0.5, 750],
    [0.999, 999],
  ])('spreads each retry over its second half (random %s waits %s ms)', async (random, wait) => {
    vi.spyOn(Math, 'random').mockReturnValue(random);
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(new Response('{}', { status: 503 }))
      .mockImplementation(async () => session());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    await vi.advanceTimersByTimeAsync(Math.floor(wait) - 1);
    expect(fetcher).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledTimes(2);
    vi.restoreAllMocks();
  });

  test('names a proxy that drops WebSocket upgrades once, after three handshakes failed in a row', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    const failHandshake = async () => {
      sockets.at(-1).fail(closed(1006));
      await vi.advanceTimersByTimeAsync(30_000);
    };
    await vi.advanceTimersByTimeAsync(0);
    await failHandshake();
    // Neither a refused token nor an opened socket's loss counts.
    sockets.at(-1).fail(closed(4401));
    await vi.advanceTimersByTimeAsync(0);
    await failHandshake();
    expect(warn).not.toHaveBeenCalled();
    await failHandshake();
    expect(warn).toHaveBeenCalledOnce();
    expect(warn.mock.calls[0][0]).toContain('docs/guide/websocket-hub.md');
    await failHandshake();
    await failHandshake();
    await failHandshake();
    // An opening starts the count again, and the hint is not repeated.
    open();
    sockets.at(-1).fail(closed(1006));
    await vi.advanceTimersByTimeAsync(30_000);
    for (let attempt = 0; attempt < 3; attempt++) await failHandshake();
    expect(warn).toHaveBeenCalledOnce();
    warn.mockRestore();
  });

  test('a Mercure stream failing never hints at the WebSocket proxy', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetcher = vi.fn(async () => session());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    for (let attempt = 0; attempt < 4; attempt++) {
      streams.at(-1).fail(closed(1006));
      await vi.advanceTimersByTimeAsync(30_000);
    }
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  test('checks an open socket still answers when the device comes online or the page shows again', async () => {
    const fetcher = vi.fn(async () => socketSession());
    const hub = new CollaborateHub(layer, fetcher, transports);
    const leave = hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    // Nothing is renewed before a socket opens.
    window.dispatchEvent(new Event('online'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledOnce();
    open();
    window.dispatchEvent(new Event('online'));
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).toHaveBeenCalledOnce();
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).toHaveBeenCalledOnce();
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(0);
    expect(renew).toHaveBeenCalledTimes(2);
    leave();
    expect(hub.live).toBeNull();
    window.dispatchEvent(new Event('online'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(3);
    delete document.hidden;
  });

  test('sends on the open socket only while its session takes cursor events', async () => {
    const send = vi.fn(() => true);
    const fetcher = vi
      .fn()
      .mockImplementationOnce(async () => socketSession({ cursorEvents: true }))
      .mockImplementationOnce(async () => socketSession({ cursorEvents: false }))
      .mockImplementationOnce(async () => socketSession({ cursorEvents: true }))
      .mockImplementation(async () => session());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    const message = { hub: 'cursor', event: { kind: 'move' } };
    expect(hub.send(message)).toBe(false);
    await vi.advanceTimersByTimeAsync(0);
    // Nothing goes before the socket opens.
    expect(hub.send(message)).toBe(false);
    sockets[0].options.onopen({ url: sockets[0].url, renew, send });
    expect(hub.send(message)).toBe(true);
    expect(send).toHaveBeenCalledWith(message);
    // A renewed session that no longer takes them stops it; one that takes them again restarts it.
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(hub.send(message)).toBe(false);
    hub.reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(hub.send(message)).toBe(true);
    send.mockReturnValueOnce(false);
    expect(hub.send(message)).toBe(false);
    // A socket lost, or a Mercure stream, sends nothing.
    sockets[0].fail(closed(1006));
    await vi.advanceTimersByTimeAsync(0);
    expect(hub.send(message)).toBe(false);
    await vi.advanceTimersByTimeAsync(1000);
    streams[0].options.onopen();
    expect(hub.send(message)).toBe(false);
    expect(send).toHaveBeenCalledTimes(3);
  });

  test('a socket opened without a send handle sends nothing', async () => {
    const hub = new CollaborateHub(
      layer,
      vi.fn(async () => socketSession({ cursorEvents: true })),
      transports
    );
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    open();
    expect(hub.send({})).toBe(false);
  });

  test('a Mercure stream is not probed: it reconnects on its own', async () => {
    const fetcher = vi.fn(async () => session());
    const hub = new CollaborateHub(layer, fetcher, transports);
    hub.join(member('cursors'));
    await vi.advanceTimersByTimeAsync(0);
    streams[0].options.onopen();
    window.dispatchEvent(new Event('online'));
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledOnce();
  });
});
