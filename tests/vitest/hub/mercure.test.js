const mocks = vi.hoisted(() => ({ fetchEventSource: vi.fn() }));
vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: mocks.fetchEventSource }));

import { mercureSource } from '../../../assets/hub/mercure.js';

const eventStream = { ok: true, headers: new Headers({ 'content-type': 'text/event-stream' }) };
const subscribe = (overrides = {}) => {
  const controller = new AbortController();
  const options = {
    signal: controller.signal,
    token: 'jwt',
    topics: ['cursors', 'draft:a'],
    fetch: vi.fn(),
    onopen: vi.fn(),
    onmessage: vi.fn(),
    ...overrides,
  };
  return { controller, options, done: mercureSource('/.well-known/mercure', options) };
};

beforeEach(() => {
  mocks.fetchEventSource.mockReset();
  mocks.fetchEventSource.mockResolvedValue(undefined);
});

test('subscribes to every topic with the token as a bearer, through the given fetch, hidden or not', async () => {
  const { options, done } = subscribe();
  await done;
  expect(mocks.fetchEventSource).toHaveBeenCalledOnce();
  const [url, init] = mocks.fetchEventSource.mock.calls[0];
  expect(url).toBe('http://localhost/.well-known/mercure?match=cursors&match=draft%3Aa');
  expect(init).toMatchObject({
    fetch: options.fetch,
    signal: options.signal,
    openWhenHidden: true,
    headers: { Authorization: 'Bearer jwt' },
    onmessage: options.onmessage,
  });
});

test('keeps an absolute hub URL and its own query', async () => {
  const controller = new AbortController();
  await mercureSource('https://hub.example/mercure?x=1', {
    signal: controller.signal,
    token: 't',
    topics: ['a'],
    onopen: vi.fn(),
    onmessage: vi.fn(),
  });
  expect(mocks.fetchEventSource.mock.calls[0][0]).toBe('https://hub.example/mercure?x=1&match=a');
});

test('opens on an event stream only, and fails on a closed or failed one', async () => {
  const { options, done } = subscribe();
  await done;
  const init = mocks.fetchEventSource.mock.calls[0][1];
  await expect(init.onopen({ ok: false, headers: new Headers() })).rejects.toThrow('stream unavailable');
  await expect(init.onopen({ ok: true, headers: new Headers({ 'content-type': 'text/html' }) })).rejects.toThrow(
    'stream unavailable'
  );
  expect(options.onopen).not.toHaveBeenCalled();
  await init.onopen(eventStream);
  expect(options.onopen).toHaveBeenCalledOnce();
  expect(options.onopen).toHaveBeenCalledWith();
  expect(() => init.onclose()).toThrow('closed');
  const failure = new Error('network');
  expect(() => init.onerror(failure)).toThrow(failure);
});

test('rejects as the stream does', async () => {
  mocks.fetchEventSource.mockRejectedValueOnce(new Error('network'));
  await expect(subscribe().done).rejects.toThrow('network');
});

test('opens nothing once aborted while the package loads', async () => {
  const controller = new AbortController();
  controller.abort();
  await expect(
    mercureSource('/mercure', {
      signal: controller.signal,
      token: 't',
      topics: [],
      onopen: vi.fn(),
      onmessage: vi.fn(),
    })
  ).resolves.toBeUndefined();
  expect(mocks.fetchEventSource).not.toHaveBeenCalled();
});
