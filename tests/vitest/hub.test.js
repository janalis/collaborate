import { CollaborateHub, hubFor, layerConfig } from '../../assets/hub.js';

let layer;
let subscribe;
let streams;

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
const eventStream = { ok: true, headers: new Headers({ 'content-type': 'text/event-stream' }) };
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  expect(body(fetcher)).toEqual({ grant: 'page', updates: true, drafts: ['sig'] });
  expect(subscribe).toHaveBeenCalledOnce();
  expect(streams[0].url).toBe('http://localhost/mercure?match=cursors&match=updates&match=draft%3Aa');
  expect(streams[0].options.headers.Authorization).toBe('Bearer jwt');
  await streams[0].options.onopen(eventStream);
  for (const joined of [cursors, updates, draft])
    expect(joined.opened).toHaveBeenCalledWith(expect.objectContaining({ userId: 7 }));
});

test('routes each message to the members of its channel, a draft message to its own draft', async () => {
  const hub = new CollaborateHub(
    layer,
    vi.fn(async () => session({ denied: undefined })),
    subscribe
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

test('reconnects without a member that left, closes with the last one, and renews every four minutes', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  await streams[0].options.onopen(eventStream);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const updates = member('updates');
  hub.join(updates);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen(eventStream);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen(eventStream);
  await vi.advanceTimersByTimeAsync(240_000);
  expect(cursors.denied).toHaveBeenCalledOnce();
  expect(streams[0].options.signal.aborted).toBe(true);
  await vi.advanceTimersByTimeAsync(300_000);
  expect(fetcher).toHaveBeenCalledTimes(3);
});

test('backs off and retries an unavailable session, an invalid stream and a closed or failed stream', async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response('{}', { status: 503 }))
    .mockImplementation(async () => session());
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscribe).toHaveBeenCalledOnce();
  const { options } = streams[0];
  await expect(options.onopen({ ok: false, headers: new Headers() })).rejects.toThrow('stream unavailable');
  await expect(options.onopen({ ok: true, headers: new Headers({ 'content-type': 'text/html' }) })).rejects.toThrow(
    'stream unavailable'
  );
  expect(() => options.onclose()).toThrow('closed');
  const failure = new Error('network');
  expect(() => options.onerror(failure)).toThrow(failure);
  await options.onopen(eventStream);
  expect(hub.failures).toBe(0);
});

test('a failed subscription reports the members closed and tries again', async () => {
  const fetcher = vi.fn(async () => session());
  subscribe.mockRejectedValueOnce(new Error('stream failed'));
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  hub.join(cursors);
  // A member may follow the stream without hearing of its state.
  hub.join({ channel: 'updates', receive: vi.fn() });
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  expect(subscribe).toHaveBeenCalledTimes(2);
  await streams[0].options.onopen(eventStream);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  expect(streams[0].url).toBe('http://localhost/mercure?match=cursors');
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  const leaveCursors = hub.join(cursors);
  hub.join(member('updates'));
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen(eventStream);
  // The renewal subscribes again: the first stream still delivers until the second opens.
  await vi.advanceTimersByTimeAsync(240_000);
  expect(streams).toHaveLength(2);
  expect(streams[0].options.signal.aborted).toBe(false);
  await streams[1].options.onopen(eventStream);
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
  await streams[3].options.onopen(eventStream);
  expect(streams[1].options.signal.aborted).toBe(true);
});

test('retries a replacement that fails while the open stream goes on carrying the topics', async () => {
  const fetcher = vi.fn(async () => session());
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen(eventStream);
  subscribe.mockRejectedValueOnce(new Error('stream failed'));
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).not.toHaveBeenCalled();
  expect(streams[0].options.signal.aborted).toBe(false);
  await vi.advanceTimersByTimeAsync(1000);
  await streams[1].options.onopen(eventStream);
  expect(streams[0].options.signal.aborted).toBe(true);
  // The open stream failing while a newer connection is under way leaves the report to that one,
  // which reports the loss before its opening: what was published in between is missed.
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[1].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).not.toHaveBeenCalled();
  const opened = cursors.opened.mock.calls.length;
  await streams[2].options.onopen(eventStream);
  expect(cursors.closed).toHaveBeenCalledOnce();
  expect(cursors.opened).toHaveBeenCalledTimes(opened + 1);
  expect(cursors.closed.mock.invocationCallOrder[0]).toBeLessThan(cursors.opened.mock.invocationCallOrder.at(-1));
  await streams[2].options.onopen(eventStream);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
  const cursors = member('cursors');
  hub.join(cursors);
  await vi.advanceTimersByTimeAsync(0);
  await streams[0].options.onopen(eventStream);
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[0].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  streams[1].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  expect(cursors.closed).toHaveBeenCalledOnce();
  await vi.advanceTimersByTimeAsync(1000);
  await streams[2].options.onopen(eventStream);
  expect(cursors.closed).toHaveBeenCalledOnce();
  // Closing forgets a loss no stream reported yet.
  hub.reconnect();
  await vi.advanceTimersByTimeAsync(0);
  streams[2].fail(new Error('network'));
  await vi.advanceTimersByTimeAsync(0);
  hub.close();
  hub.connect();
  await vi.advanceTimersByTimeAsync(0);
  await streams.at(-1).options.onopen(eventStream);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
  const hub = new CollaborateHub(layer, fetcher, subscribe);
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
