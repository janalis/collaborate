// The service worker is a classic script: it imports and exports nothing, and reads every global through
// `self`. The tests stub `self` with a fake worker scope and import the file afresh, which evaluates it as a
// module (strict, like the 'use strict' it starts with) and counts its coverage.

/** A fake service worker scope: its listeners, its pages, its registration. */
function scope({
  settings = { read: '/n/read', title: 'New notification', body: 'You have one.', icon: '/i.png' },
} = {}) {
  const listeners = {};
  const self = {
    collaborateNotifications: settings,
    location: { origin: 'https://app.example' },
    windows: [],
    addEventListener: (type, listener) => {
      listeners[type] = listener;
    },
    skipWaiting: vi.fn(async () => undefined),
    fetch: vi.fn(async () => new Response(null, { status: 200 })),
    registration: { showNotification: vi.fn(async () => undefined) },
    clients: {
      claim: vi.fn(async () => undefined),
      matchAll: vi.fn(async () => self.windows),
      openWindow: vi.fn(async (url) => ({ url })),
    },
  };
  /** Dispatches an event, and answers what it waits until. */
  self.dispatch = async (type, event = {}) => {
    let waited;
    listeners[type]({ ...event, waitUntil: (promise) => (waited = promise) });
    return waited;
  };
  return self;
}

function page(url, { visibilityState = 'hidden', focused = false, navigate = null } = {}) {
  return {
    url,
    visibilityState,
    focused,
    postMessage: vi.fn(),
    focus: vi.fn(async function focus() {
      return this;
    }),
    navigate: vi.fn(navigate ?? (async () => null)),
  };
}

async function load(self) {
  vi.resetModules();
  vi.stubGlobal('self', self);
  await import('../../../assets/notifications/push-worker.js');
  return self;
}

const push = (data) => ({
  data:
    data === undefined
      ? null
      : {
          json: () => {
            if (typeof data === 'string') return JSON.parse(data);
            return data;
          },
        },
});

afterEach(() => {
  vi.unstubAllGlobals();
});

test('takes over at once', async () => {
  const self = await load(scope());
  await self.dispatch('install');
  expect(self.skipWaiting).toHaveBeenCalledOnce();
  await self.dispatch('activate');
  expect(self.clients.claim).toHaveBeenCalledOnce();
});

test('shows a push as a notification of the system while no page of the origin is open', async () => {
  const self = await load(scope());
  self.windows = [page('https://other.example/'), page('not a url')];
  await self.dispatch(
    'push',
    push({ id: 7, title: 'Seven', body: 'Comment', url: '/files/7', icon: '/7.png', tag: 't7' })
  );
  expect(self.clients.matchAll).toHaveBeenCalledWith({ type: 'window', includeUncontrolled: true });
  expect(self.windows[0].postMessage).not.toHaveBeenCalled();
  expect(self.registration.showNotification).toHaveBeenCalledWith('Seven', {
    body: 'Comment',
    icon: '/7.png',
    tag: 't7',
    data: { id: 7, url: '/files/7' },
  });
});

test('shows a push in the page that has the focus, and by the system otherwise', async () => {
  const self = await load(scope());
  const behind = page('https://app.example/a', { visibilityState: 'visible' });
  const looked = page('https://app.example/b', { visibilityState: 'visible', focused: true });
  self.windows = [behind, looked];
  await self.dispatch('push', push({ id: 7, body: 'Comment', url: '/files/7' }));
  expect(looked.postMessage).toHaveBeenCalledWith({
    type: 'collaborate:notification',
    id: 7,
    body: 'Comment',
    url: '/files/7',
  });
  // The page behind asks for its list again, without a toast.
  expect(behind.postMessage).toHaveBeenCalledWith({ type: 'collaborate:notifications-changed' });
  expect(self.registration.showNotification).not.toHaveBeenCalled();
  // Open, visible or hidden, but without the focus: the system shows it, and every page counts it.
  self.windows = [page('https://app.example/a'), page('https://app.example/c', { visibilityState: 'visible' })];
  await self.dispatch('push', push({ id: 8, title: 'Eight', body: 'B' }));
  for (const client of self.windows)
    expect(client.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'collaborate:notifications-changed' });
  expect(self.registration.showNotification).toHaveBeenCalledOnce();
  expect(self.registration.showNotification).toHaveBeenCalledWith('Eight', expect.objectContaining({ body: 'B' }));
});

test('says what the server says of a push without a payload, or with one it cannot read', async () => {
  const self = await load(scope());
  const generic = [
    'New notification',
    { body: 'You have one.', icon: '/i.png', tag: undefined, data: { id: null, url: null } },
  ];
  for (const event of [push(), push('{'), push(null), push('"text"')]) {
    self.registration.showNotification.mockClear();
    await self.dispatch('push', event);
    expect(self.registration.showNotification).toHaveBeenCalledWith(...generic);
  }
  // Without the settings of the server.
  const bare = await load(scope({ settings: null }));
  await bare.dispatch('push', push());
  expect(bare.registration.showNotification).toHaveBeenCalledWith('', {
    body: '',
    icon: undefined,
    tag: undefined,
    data: { id: null, url: null },
  });
});

describe('a click', () => {
  const click = (data) => {
    const notification = { data, close: vi.fn() };
    return { notification };
  };

  test('reads the notification, then focuses the page already showing it', async () => {
    const self = await load(scope());
    self.windows = [page('https://app.example/other'), page('https://app.example/files/7')];
    const event = click({ id: 7, url: '/files/7' });
    const focused = await self.dispatch('notificationclick', event);
    expect(event.notification.close).toHaveBeenCalledOnce();
    expect(self.fetch).toHaveBeenCalledWith('/n/read', {
      method: 'POST',
      credentials: 'include',
      body: new URLSearchParams({ id: '7' }),
    });
    expect(focused).toBe(self.windows[1]);
    // Read, it is counted so at once by every page left open, after the server knows.
    for (const client of self.windows)
      expect(client.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'collaborate:notifications-changed' });
    expect(self.fetch.mock.invocationCallOrder[0]).toBeLessThan(
      self.windows[0].postMessage.mock.invocationCallOrder[0]
    );
  });

  test('takes a page of the origin there, the next one when one cannot be, a new one otherwise', async () => {
    const self = await load(scope());
    const there = page('https://app.example/files/7');
    self.windows = [
      page('https://app.example/a', {
        navigate: async () => {
          throw new TypeError('not controlled');
        },
      }),
      page('https://app.example/b'),
      page('https://app.example/c', { navigate: async () => there }),
    ];
    expect(await self.dispatch('notificationclick', click({ id: 7, url: '/files/7' }))).toBe(there);
    expect(there.focus).toHaveBeenCalledOnce();
    self.windows = [page('https://app.example/b')];
    expect(await self.dispatch('notificationclick', click({ url: '/files/7' }))).toEqual({
      url: 'https://app.example/files/7',
    });
    expect(self.fetch).toHaveBeenCalledOnce();
  });

  test('opens the home page of a notification leading nowhere, read even when the server could not be told', async () => {
    const self = await load(scope());
    self.fetch.mockRejectedValue(new TypeError('offline'));
    expect(await self.dispatch('notificationclick', click({ id: 3 }))).toEqual({ url: 'https://app.example/' });
    expect(self.fetch).toHaveBeenCalledOnce();
    const event = { notification: { data: null, close: vi.fn() } };
    expect(await self.dispatch('notificationclick', event)).toEqual({ url: 'https://app.example/' });
    // Without the url that reads it, nothing is read.
    const bare = await load(scope({ settings: { read: '' } }));
    await bare.dispatch('notificationclick', click({ id: 3 }));
    expect(bare.fetch).not.toHaveBeenCalled();
  });
});
