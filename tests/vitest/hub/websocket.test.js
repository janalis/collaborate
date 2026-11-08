import { ACK_TIMEOUT, websocketSource, websocketUrl } from '../../../assets/hub/websocket.js';

/** A socket the test drives: it opens, answers and closes when told. */
class FakeSocket {
  static sockets = [];

  constructor(url) {
    this.url = url;
    this.sent = [];
    this.closed = null;
    this.readyState = 1;
    FakeSocket.sockets.push(this);
  }

  send(data) {
    this.sent.push(JSON.parse(data));
  }

  close(code) {
    this.closed = code;
  }

  open() {
    this.onopen?.({});
  }

  frame(data) {
    this.onmessage?.({ data: typeof data === 'string' ? data : JSON.stringify(data) });
  }

  drop(code) {
    this.onclose?.({ code });
  }
}

let controller;
let options;
const socket = () => FakeSocket.sockets.at(-1);
const connect = (url = '/collaborate-hub', overrides = {}) => {
  options = {
    signal: controller.signal,
    token: 'first',
    onopen: vi.fn(),
    onmessage: vi.fn(),
    WebSocket: FakeSocket,
    ...overrides,
  };
  const done = websocketSource(url, options);
  done.catch(() => {});
  return done;
};
const settled = async (promise) => {
  const outcome = { state: 'pending' };
  promise.then(
    (value) => Object.assign(outcome, { state: 'resolved', value }),
    (error) => Object.assign(outcome, { state: 'rejected', error })
  );
  await vi.advanceTimersByTimeAsync(0);
  return outcome;
};
const acknowledge = (hub = 'subscribed', id = socket().sent.at(-1).id, extra = {}) =>
  socket().frame({ hub, id, ...extra });

beforeEach(() => {
  vi.useFakeTimers();
  FakeSocket.sockets = [];
  controller = new AbortController();
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

test.each([
  ['/collaborate-hub', 'http://localhost/page', 'ws://localhost/collaborate-hub'],
  ['/collaborate-hub', 'https://app.example/page', 'wss://app.example/collaborate-hub'],
  ['hub?x=1#part', 'https://app.example/admin/page', 'wss://app.example/admin/hub?x=1'],
  ['https://hub.example/ws', 'http://localhost/', 'wss://hub.example/ws'],
  ['http://hub.example:8080/ws', 'https://app.example/', 'ws://hub.example:8080/ws'],
  ['wss://hub.example/ws', 'http://localhost/', 'wss://hub.example/ws'],
  ['ws://hub.example/ws', 'https://app.example/', 'ws://hub.example/ws'],
])('maps %s on %s to %s', (url, base, expected) => {
  expect(websocketUrl(url, base)).toBe(expected);
});

test('maps against the page by default, and connects to the mapped URL', async () => {
  expect(websocketUrl('/hub')).toBe('ws://localhost/hub');
  connect('/collaborate-hub');
  expect(socket().url).toBe('ws://localhost/collaborate-hub');
});

test('uses the browser WebSocket unless given one', () => {
  vi.stubGlobal('WebSocket', FakeSocket);
  websocketSource('/hub', { signal: controller.signal, token: 't', onopen: vi.fn(), onmessage: vi.fn() });
  expect(socket().url).toBe('ws://localhost/hub');
});

test('subscribes with the token in the first frame, and opens only once the hub acknowledges it', async () => {
  const done = connect();
  expect(socket().sent).toEqual([]);
  socket().open();
  expect(socket().sent).toEqual([{ hub: 'subscribe', id: 1, token: 'first' }]);
  expect(options.onopen).not.toHaveBeenCalled();
  // An answer to no pending subscription, or one of no known kind, changes nothing.
  socket().frame({ hub: 'subscribed', id: 99 });
  socket().frame({ hub: 'later', id: 1 });
  socket().frame({ hub: 'subscribed' });
  await vi.advanceTimersByTimeAsync(0);
  expect(options.onopen).not.toHaveBeenCalled();
  acknowledge();
  await vi.advanceTimersByTimeAsync(0);
  expect(options.onopen).toHaveBeenCalledOnce();
  const [handle] = options.onopen.mock.calls[0];
  expect(handle.url).toBe('/collaborate-hub');
  expect(handle.renew).toBeTypeOf('function');
  expect((await settled(done)).state).toBe('pending');
});

test('fails when the first subscription is not acknowledged in time', async () => {
  const done = connect();
  socket().open();
  await vi.advanceTimersByTimeAsync(ACK_TIMEOUT - 1);
  expect((await settled(done)).state).toBe('pending');
  await vi.advanceTimersByTimeAsync(1);
  const outcome = await settled(done);
  expect(outcome.state).toBe('rejected');
  expect(outcome.error.message).toContain('unacknowledged');
  expect(outcome.error.code).toBeUndefined();
  expect(socket().closed).toBe(1000);
  expect(options.onopen).not.toHaveBeenCalled();
});

test('fails with 4401 when the first subscription is refused', async () => {
  const done = connect();
  socket().open();
  acknowledge('error');
  const outcome = await settled(done);
  expect(outcome.state).toBe('rejected');
  expect(outcome.error.code).toBe(4401);
  expect(socket().closed).toBe(1000);
});

test('fails with 1008 when the hub refuses a user with too many connections', async () => {
  const done = connect();
  socket().open();
  acknowledge('error', undefined, { code: 1008 });
  const outcome = await settled(done);
  expect(outcome.state).toBe('rejected');
  expect(outcome.error.code).toBe(1008);
});

test('hands notices to the reader, and keeps the hub answers and binary frames from it', async () => {
  connect();
  socket().open();
  // Nothing is read before the subscription stands.
  socket().frame({ channel: 'cursors', early: true });
  acknowledge();
  await vi.advanceTimersByTimeAsync(0);
  socket().frame('{"channel":"cursors","kind":"move"}');
  socket().frame('not json');
  socket().frame('[1]');
  socket().frame('null');
  socket().frame({ hub: 'subscribed', id: 1 });
  socket().onmessage({ data: new ArrayBuffer(2) });
  expect(options.onmessage.mock.calls.map(([message]) => message)).toEqual([
    { data: '{"channel":"cursors","kind":"move"}' },
    { data: 'not json' },
    { data: '[1]' },
    { data: 'null' },
  ]);
});

test.each([[1001], [1006], [1008], [1013], [4401]])('rejects with the close code %s', async (code) => {
  const done = connect();
  socket().open();
  acknowledge();
  await vi.advanceTimersByTimeAsync(0);
  socket().drop(code);
  const outcome = await settled(done);
  expect(outcome.state).toBe('rejected');
  expect(outcome.error.code).toBe(code);
});

test('a socket that closes before its first acknowledgement rejects with its code', async () => {
  const done = connect();
  socket().open();
  socket().drop(1006);
  const outcome = await settled(done);
  expect(outcome.error.code).toBe(1006);
  expect(options.onopen).not.toHaveBeenCalled();
});

test('a socket that cannot be created fails as a client error', async () => {
  class Refusing {
    constructor() {
      throw new DOMException('The URL is invalid.', 'SyntaxError');
    }
  }
  const outcome = await settled(connect('/hub', { WebSocket: Refusing }));
  expect(outcome.state).toBe('rejected');
  expect(outcome.error.code).toBe(1008);
  expect(outcome.error.message).toContain('The URL is invalid.');
});

test('resolves on abort, closing the socket normally, and ignores its close event then', async () => {
  const done = connect();
  socket().open();
  acknowledge();
  await vi.advanceTimersByTimeAsync(0);
  controller.abort();
  expect(socket().closed).toBe(1000);
  socket().drop(1000);
  expect(await settled(done)).toMatchObject({ state: 'resolved', value: undefined });
});

test('an abort while the first subscription waits resolves, and opens nothing', async () => {
  const done = connect();
  socket().open();
  controller.abort();
  expect(await settled(done)).toMatchObject({ state: 'resolved' });
  acknowledge();
  await vi.advanceTimersByTimeAsync(0);
  expect(options.onopen).not.toHaveBeenCalled();
});

test('an aborted signal opens no socket', async () => {
  controller.abort();
  expect(await settled(connect())).toMatchObject({ state: 'resolved' });
  expect(FakeSocket.sockets).toEqual([]);
});

describe('renewal on the open socket', () => {
  let done;
  let handle;

  beforeEach(async () => {
    done = connect();
    socket().open();
    acknowledge();
    await vi.advanceTimersByTimeAsync(0);
    [[handle]] = options.onopen.mock.calls;
  });

  test('subscribes again with the new token and resolves on its acknowledgement', async () => {
    const renewal = handle.renew('second');
    expect(socket().sent.at(-1)).toEqual({ hub: 'subscribe', id: 2, token: 'second' });
    expect((await settled(renewal)).state).toBe('pending');
    acknowledge();
    expect(await settled(renewal)).toMatchObject({ state: 'resolved' });
    expect(options.onopen).toHaveBeenCalledOnce();
    expect((await settled(done)).state).toBe('pending');
  });

  test('rejects on a refusal, the socket staying open', async () => {
    const renewal = handle.renew('second');
    acknowledge('error');
    const outcome = await settled(renewal);
    expect(outcome.state).toBe('rejected');
    expect(outcome.error.code).toBe(4401);
    expect(socket().closed).toBeNull();
    expect((await settled(done)).state).toBe('pending');
  });

  test('rejects when no acknowledgement comes in time', async () => {
    const renewal = handle.renew('second');
    renewal.catch(() => {});
    await vi.advanceTimersByTimeAsync(ACK_TIMEOUT);
    expect((await settled(renewal)).error.message).toContain('unacknowledged');
    // A late answer finds nothing waiting.
    acknowledge('subscribed', 2);
    expect((await settled(done)).state).toBe('pending');
  });

  test('rejects when the socket closes meanwhile, and refuses any renewal after', async () => {
    const renewal = handle.renew('second');
    socket().drop(1006);
    expect((await settled(renewal)).error.code).toBe(1006);
    expect((await settled(handle.renew('third'))).error.code).toBe(1000);
  });

  test('rejects once its own signal aborts, and when given one already aborted', async () => {
    const cancel = new AbortController();
    const renewal = handle.renew('second', cancel.signal);
    cancel.abort();
    expect((await settled(renewal)).error.message).toContain('cancelled');
    acknowledge('subscribed', 2);
    expect((await settled(handle.renew('third', cancel.signal))).state).toBe('rejected');
    expect(socket().sent).toHaveLength(2);
    // Acknowledged before its signal aborts, a renewal stands.
    const later = new AbortController();
    const kept = handle.renew('fourth', later.signal);
    acknowledge();
    later.abort();
    expect((await settled(kept)).state).toBe('resolved');
  });

  test('sends frames of its own on the subscribed socket, until it closes', async () => {
    expect(handle.send({ hub: 'cursor', event: { kind: 'move' } })).toBe(true);
    expect(socket().sent.at(-1)).toEqual({ hub: 'cursor', event: { kind: 'move' } });
    socket().readyState = 2;
    expect(handle.send({ hub: 'cursor' })).toBe(false);
    socket().readyState = 1;
    socket().drop(1006);
    expect(handle.send({ hub: 'cursor' })).toBe(false);
    expect(socket().sent).toHaveLength(2);
  });

  test('rejects a pending renewal as closed when the stream is aborted', async () => {
    const renewal = handle.renew('second');
    controller.abort();
    expect((await settled(renewal)).error.code).toBe(1000);
    expect((await settled(done)).state).toBe('resolved');
  });
});
