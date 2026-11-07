// The service worker of web push: a classic script, which PushController serves at `push.worker` after a line
// setting `self.collaborateNotifications = {read, title, body, icon}` (the url that reads a notification, and
// what a push without a payload says).
//
// A push said while a page of the origin has the focus is shown in that page: it is posted it, and its bell
// tells the host (bell.js), which shows a toast. Otherwise -- every page closed, hidden, or behind another
// window -- the system shows it, and the pages left open hear of it through the hub, their bells counting it
// without a toast. A notification is never shown twice: in the page while its reader looks at it, else by the
// system.
//
// It imports nothing and exports nothing, so that it also loads as a module (its tests import it, with a
// stubbed `self`): what a worker scope has it reads through `self`, the standard constructors (URL) aside.

const settings = () => self.collaborateNotifications || {};

const sameOrigin = (client) => {
  try {
    return new URL(client.url).origin === self.location.origin;
  } catch {
    return false;
  }
};

/** The pages of the origin, whether this worker controls them yet or not. */
const windows = async () =>
  (await self.clients.matchAll({ type: 'window', includeUncontrolled: true })).filter(sameOrigin);

/** What a push says: its payload, or what the server says of a push without one. */
function payloadOf(event) {
  const { title, body, icon } = settings();
  let data = null;
  try {
    data = event.data ? event.data.json() : null;
  } catch {
    data = null;
  }
  const given = data && typeof data === 'object' ? data : {};
  return {
    id: given.id ?? null,
    title: given.title || title || '',
    body: given.body || body || '',
    url: given.url ?? null,
    icon: given.icon || icon || undefined,
    tag: given.tag || undefined,
  };
}

async function received(event) {
  const notification = payloadOf(event);
  const focused = (await windows()).filter((client) => client.focused);
  for (const client of focused)
    client.postMessage({
      type: 'collaborate:notification',
      id: notification.id,
      body: notification.body,
      url: notification.url,
    });
  if (focused.length > 0) return;
  await self.registration.showNotification(notification.title, {
    body: notification.body,
    icon: notification.icon,
    tag: notification.tag,
    data: { id: notification.id, url: notification.url },
  });
}

/** Reads the notification clicked, then shows what it is about: in a page already there, else in a new one. */
async function clicked(data) {
  const read = settings().read;
  if (data.id !== null && data.id !== undefined && read) {
    try {
      await self.fetch(read, {
        method: 'POST',
        credentials: 'include',
        body: new URLSearchParams({ id: String(data.id) }),
      });
    } catch {
      // Unread still: the page shows it so.
    }
  }
  const target = new URL(data.url || '/', self.location.origin).href;
  const open = await windows();
  const there = open.find((client) => client.url === target);
  if (there) return there.focus();
  for (const client of open) {
    try {
      // Only a page this worker controls can be navigated; another answers null or throws.
      const navigated = await client.navigate(target);
      if (navigated) return navigated.focus();
    } catch {
      // The next page, or a new one.
    }
  }
  return self.clients.openWindow(target);
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  event.waitUntil(received(event));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clicked(event.notification.data || {}));
});
