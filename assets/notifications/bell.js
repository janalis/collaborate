// The bell of the notifications (templates/notifications/bell.html.twig), on every page that renders it.
//
// The server renders the latest notifications in the bell's menu, and tells the hub when the user's list
// changed -- never what: the bell asks for its list again (`settings.src`), counts what is unread, and tells
// the host of each notification newer than what it had shown (`collaborate:notification`, which the host
// shows as it shows its toasts). The service worker of web push tells an open page the same way.
//
// The page of all notifications (page.js) and the bell tell each other through the document:
// `collaborate:notifications-changed`, whose `origin` says who changed something. The page's (`origin:
// 'page'`) gives the bell the count it was answered; the bell's (`origin: 'bell'`) tells the host the
// counts after any change, and the page whether the list changed elsewhere (`heard`).
import { backgroundFetch, hubFor } from '../hub.js';

// Notices of the hub arriving together ask for the list once.
export const REFRESH_DELAY = 250;

// Above which the bell stops counting, unless the settings say otherwise.
export const MAX_COUNT = 99;

const ITEM = '.collaborate-notification';
const UNREAD = `${ITEM}[data-unread="true"]`;
const HEADER = '.collaborate-notifications__header';

// The notifications the host was told of, once each whatever told the page (hub, service worker, bells).
const told = new Set();

/** The JSON settings an element carries in an attribute; a malformed one reads as none. */
export function settingsOf(element, attribute) {
  try {
    const settings = JSON.parse(element?.getAttribute(attribute) || '{}');
    return settings && typeof settings === 'object' && !Array.isArray(settings) ? settings : {};
  } catch {
    return {};
  }
}

/** Writes every date of a notification in the language of the page, as the reader reads dates. */
export function formatDates(scope, lang = document.documentElement.lang || undefined) {
  let format;
  try {
    format = new Intl.DateTimeFormat(lang, { dateStyle: 'long', timeStyle: 'short' });
  } catch {
    // A language the browser does not know.
    format = new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeStyle: 'short' });
  }
  for (const time of scope.querySelectorAll('time.collaborate-notification__date[datetime]')) {
    const date = new Date(time.getAttribute('datetime'));
    if (!Number.isNaN(date.getTime())) time.textContent = format.format(date);
  }
}

/** The nodes of an HTML fragment, its scripts inert. */
export function parseFragment(html) {
  const template = document.createElement('template');
  template.innerHTML = html;
  return [...template.content.childNodes];
}

/** A fragment the server rendered for a script, or null when it answered anything else (a sign-in page). */
export async function fetchFragment(url, fetcher = backgroundFetch) {
  try {
    const response = await fetcher(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok || response.redirected) return null;
    return await response.text();
  } catch {
    return null;
  }
}

/**
 * Tells the server what was read, unread or cleared: one notification by its id, all of them without one.
 * Answers the counts `{unread, total}`, or null when the server could not be told.
 */
export async function postChange(url, id = null, fetcher = backgroundFetch) {
  const body = new URLSearchParams();
  if (id) body.set('id', id);
  try {
    const response = await fetcher(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    if (!response.ok || response.redirected) return null;
    const answer = await response.json();
    return Number.isInteger(answer?.unread) ? answer : null;
  } catch {
    return null;
  }
}

/** A click that leaves the page for the link, rather than opening it in another tab or window. */
export const leaves = (event) =>
  event.button === 0 && !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);

/** A web push public key, base64url as the server gives it, as the bytes `pushManager.subscribe()` takes. */
export function decodeKey(value) {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '=')), (char) => char.charCodeAt(0));
}

/** Whether a subscription was made with these key bytes: one made with a key since rotated is not. */
export function sameKey(buffer, key) {
  if (!buffer) return false;
  const bytes = new Uint8Array(buffer);
  return bytes.length === key.length && bytes.every((byte, index) => byte === key[index]);
}

/** The encoding of the payloads this browser decrypts, the standard one if it has it. */
export function pushEncoding(encodings = globalThis.PushManager?.supportedContentEncodings) {
  const known = Array.isArray(encodings) ? encodings : [];
  return known.includes('aes128gcm') ? 'aes128gcm' : (known[0] ?? 'aes128gcm');
}

/** Whether this browser can be sent web pushes, and the server sends them. */
export const pushSupported = (settings) =>
  Boolean(
    settings.push &&
      'serviceWorker' in navigator &&
      'PushManager' in window &&
      typeof globalThis.Notification === 'function'
  );

/**
 * Counts the notifications of a bell, keeps them told of what the hub and the service worker say, marks them
 * read, and switches web push on this device.
 */
export function initBell(
  root,
  {
    hub = hubFor(),
    fetcher = backgroundFetch,
    navigate = (url) => location.assign(url),
    serviceWorker = 'serviceWorker' in navigator ? navigator.serviceWorker : null,
    announced = told,
  } = {}
) {
  const settings = settingsOf(root, 'data-collaborate-notifications');
  const toggle = root.querySelector('.collaborate-notifications__toggle');
  const menu = root.querySelector('.collaborate-notifications__menu');
  const badge = root.querySelector('.collaborate-notifications__count');
  if (!toggle || !menu || !settings.src) return null;
  const maxCount = Number.isInteger(settings.maxCount) ? settings.maxCount : MAX_COUNT;
  // The name of the bell without its count, which the count is added to.
  const label = toggle.dataset.label ?? '';
  const unreadLabel = unreadPattern();

  let timer = null;
  let leave = null;
  let stopped = false;
  // A notice came while the menu was open: the list is asked for once it is closed.
  let owed = false;
  // The list is asked for because of what happened elsewhere, rather than on this very page.
  let heard = false;
  // The newest notification the bell has shown: one newer than it, unread, is news.
  let newest = newestShown();

  /**
   * How the bell says how many are unread, `%count%` standing for the number: the template's own (`data-
   * unread-label`), or the one its server-rendered name shows, or English.
   */
  function unreadPattern() {
    if (toggle.dataset.unreadLabel) return toggle.dataset.unreadLabel;
    const name = toggle.getAttribute('aria-label') ?? '';
    const count = badge?.textContent.trim() ?? '';
    const prefix = `${label}, `;
    if (count && name.startsWith(prefix) && name.includes(count, prefix.length))
      return name.slice(prefix.length).replace(count, '%count%');
    return '%count% unread';
  }

  function newestShown() {
    return Math.max(0, ...[...menu.querySelectorAll(ITEM)].map((item) => Number(item.dataset.id) || 0));
  }

  const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';
  const unread = () => [...menu.querySelectorAll(UNREAD)];
  const header = () => menu.querySelector(HEADER);

  function showCount(count) {
    const text = count > maxCount ? `${maxCount}+` : String(count);
    if (badge) {
      badge.textContent = text;
      badge.hidden = count === 0;
    }
    toggle.setAttribute('aria-label', count === 0 ? label : `${label}, ${unreadLabel.replace('%count%', text)}`);
  }

  /** How many the list says are unread: what its header counts, never less than what it shows. */
  function counted() {
    const value = Number.parseInt(header()?.dataset.unreadCount ?? '', 10);
    return Math.max(Number.isInteger(value) ? value : 0, unread().length);
  }

  function total() {
    const value = Number.parseInt(header()?.dataset.totalCount ?? '', 10);
    return Number.isInteger(value) ? value : null;
  }

  /** Shows what is left unread: as the server counted it when it answered, as the list tells it otherwise. */
  function settle(answer = null) {
    const known = Number.isInteger(answer?.unread);
    const head = header();
    if (head && known) {
      head.dataset.unreadCount = String(answer.unread);
      if (Number.isInteger(answer.total)) head.dataset.totalCount = String(answer.total);
    }
    const count = known ? answer.unread : counted();
    if (count === 0) menu.querySelector('.collaborate-notifications__read-all')?.remove();
    showCount(count);
    return { unread: count, total: Number.isInteger(answer?.total) ? answer.total : total() };
  }

  function changed(counts, wasHeard = false) {
    document.dispatchEvent(
      new CustomEvent('collaborate:notifications-changed', {
        detail: { ...counts, origin: 'bell', heard: wasHeard },
      })
    );
  }

  /** Tells the host of a notification, once whatever told the page of it. */
  function announce(id, text, url) {
    if (announced.has(id)) return;
    announced.add(id);
    document.dispatchEvent(new CustomEvent('collaborate:notification', { detail: { id, text, url } }));
  }

  /** Marks notifications read once the server knows; a request that failed leaves them as they are. */
  async function markRead(items, id = null) {
    const answer = await postChange(settings.read, id, fetcher);
    if (!answer || stopped) return false;
    for (const item of items) item.dataset.unread = 'false';
    changed(settle(answer));
    return true;
  }

  // A function declaration: ask and refresh name each other.
  function ask() {
    if (stopped) return;
    timer ??= setTimeout(refresh, REFRESH_DELAY);
  }

  async function refresh() {
    timer = null;
    // A list never moves under the pointer.
    if (isOpen()) {
      owed = true;
      return;
    }
    const html = await fetchFragment(settings.src, fetcher);
    if (stopped || html === null) return;
    const nodes = parseFragment(html);
    // An expired session answers the sign-in page: the list is left as it is.
    if (!nodes.some((node) => node instanceof Element && node.matches(HEADER))) return;
    // The menu was opened while the list was asked for.
    if (isOpen()) {
      owed = true;
      return;
    }
    const before = newest;
    menu.replaceChildren(...nodes);
    formatDates(menu);
    renderPush();
    newest = Math.max(before, newestShown());
    const counts = settle();
    const wasHeard = heard;
    heard = false;
    // What came since, oldest first: the list is the newest first.
    for (const item of unread()
      .filter((entry) => Number(entry.dataset.id) > before)
      .reverse()) {
      announce(
        item.dataset.id,
        item.querySelector('.collaborate-notification__text')?.textContent.trim() ?? '',
        item.getAttribute('href')
      );
    }
    changed(counts, wasHeard);
  }

  /** Asks for the list because something happened elsewhere: another page, another device, the server. */
  function hear() {
    heard = true;
    ask();
  }

  // The keys go from one item of the menu to the next.
  const items = () => [...menu.querySelectorAll('[role^="menuitem"]:not([hidden])')];

  function keydown(event) {
    const list = items();
    if (!list.length) return;
    const index = list.indexOf(document.activeElement);
    const next = {
      ArrowDown: (index + 1) % list.length,
      ArrowUp: (index - 1 + list.length) % list.length,
      Home: 0,
      End: list.length - 1,
    }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    list[next].focus();
  }

  // Opened under the bell, at its end; the first item takes the focus.
  function toggled(event) {
    const open = event.newState === 'open';
    toggle.setAttribute('aria-expanded', String(open));
    if (!open) {
      if (owed) {
        owed = false;
        ask();
      }
      return;
    }
    const box = toggle.getBoundingClientRect();
    menu.style.top = `${box.bottom + 4}px`;
    menu.style.left = `${Math.max(8, box.right - menu.offsetWidth)}px`;
    items()[0]?.focus();
  }

  function click(event) {
    if (event.target.closest('.collaborate-notifications__read-all')) {
      markRead(unread());
      return;
    }
    if (event.target.closest('.collaborate-notifications__push')) {
      switchPush();
      return;
    }
    const item = event.target.closest(UNREAD);
    if (!item || !menu.contains(item)) return;
    if (item.matches('a[href]') && leaves(event)) {
      // Followed once the server knows it read: the page it leads to then counts it read, which a beacon
      // racing that page's own request could not promise. Followed all the same when the server was not told.
      event.preventDefault();
      markRead([item], item.dataset.id).then(() => {
        if (!stopped) navigate(item.getAttribute('href'));
      });
      return;
    }
    // What stays on the page, the link opened elsewhere included, hears the server's answer.
    markRead([item], item.dataset.id);
  }

  // The page of all notifications read, unread or cleared some: the bell shows the count it was answered.
  function pageChanged(event) {
    if (event.detail?.origin !== 'page') return;
    if (Number.isInteger(event.detail.unread)) settle(event.detail);
    ask();
  }

  // The service worker was pushed a notification while this page is open: the page says it, the hub may not.
  function pushed(event) {
    const data = event.data;
    if (data?.type !== 'collaborate:notification') return;
    hear();
    if (data.id !== undefined && data.id !== null)
      announce(String(data.id), typeof data.body === 'string' ? data.body : '', data.url ?? null);
  }

  // Web push on this device: the switch of the menu, which a new list draws again.
  const push = pushSupported(settings) ? settings.push : null;
  const pushKey = push ? decodeKey(push.key) : null;
  let subscription = null;
  let denied = false;
  let switching = false;

  function renderPush() {
    const button = menu.querySelector('.collaborate-notifications__push');
    const note = menu.querySelector('.collaborate-notifications__push-denied');
    if (button) {
      button.hidden = !push;
      button.setAttribute('aria-checked', String(subscription !== null));
      // Still reached by the keys, which a disabled button is not: the note under it says why.
      if (denied) button.setAttribute('aria-disabled', 'true');
      else button.removeAttribute('aria-disabled');
    }
    if (note) note.hidden = !denied;
  }

  async function postPush(url, data) {
    try {
      const response = await fetcher(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': push.token },
        body: JSON.stringify(data),
      });
      return response.ok && !response.redirected;
    } catch {
      return false;
    }
  }

  /** Attaches a subscription of this browser to the signed-in user; one the server refused is dropped. */
  async function attach(current) {
    const { endpoint, keys } = current.toJSON();
    if (await postPush(push.subscribe, { endpoint, keys, encoding: pushEncoding() })) return current;
    await current.unsubscribe();
    return null;
  }

  async function subscribe(registration) {
    return attach(await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKey }));
  }

  const register = () => serviceWorker.register(push.worker, { scope: '/' });

  async function startPush() {
    renderPush();
    if (Notification.permission === 'denied') {
      denied = true;
      renderPush();
      return;
    }
    if (Notification.permission !== 'granted') return;
    try {
      const registration = await register();
      const existing = await registration.pushManager.getSubscription();
      if (!existing) subscription = null;
      else if (sameKey(existing.options?.applicationServerKey, pushKey))
        // Idempotent: attaches the device again to whoever is signed in now.
        subscription = await attach(existing);
      else {
        // The server's keys were rotated: what the old key encrypts no longer reaches this device.
        await existing.unsubscribe();
        await postPush(push.unsubscribe, { endpoint: existing.endpoint });
        subscription = await subscribe(registration);
      }
    } catch {
      subscription = null;
    }
    if (!stopped) renderPush();
  }

  async function switchPush() {
    if (switching || denied) return;
    switching = true;
    try {
      if (subscription) {
        const { endpoint } = subscription;
        await subscription.unsubscribe();
        subscription = null;
        await postPush(push.unsubscribe, { endpoint });
      } else {
        // Asked from this click alone: a browser refuses a prompt nobody asked for.
        const permission = await Notification.requestPermission();
        if (permission === 'granted') subscription = await subscribe(await register());
        else if (permission === 'denied') denied = true;
      }
    } catch {
      // Nothing changed on the device: the switch says what is.
    }
    switching = false;
    if (!stopped) renderPush();
  }

  function join() {
    if (!hub) return;
    let streamed = false;
    let lost = false;
    leave = hub.join({
      channel: 'notifications',
      receive: () => hear(),
      // The hub keeps no history: what was published while the stream was lost is asked for.
      opened: () => {
        streamed = true;
        if (!lost) return;
        lost = false;
        hear();
      },
      closed: () => {
        lost = streamed;
      },
      denied: () => {
        leave = null;
      },
    });
  }

  // Joined while the page is hidden: a notification still comes to a tab nobody looks at. A page kept in the
  // back/forward cache leaves, and asks again on its return.
  function hide(event) {
    leave?.();
    leave = null;
    if (!event.persisted) stop();
  }

  function show(event) {
    if (!event.persisted || stopped || leave) return;
    join();
    hear();
  }

  function stop() {
    stopped = true;
    clearTimeout(timer);
    leave?.();
    leave = null;
    menu.removeEventListener('click', click);
    menu.removeEventListener('keydown', keydown);
    menu.removeEventListener('toggle', toggled);
    document.removeEventListener('collaborate:notifications-changed', pageChanged);
    serviceWorker?.removeEventListener('message', pushed);
    window.removeEventListener('pagehide', hide);
    window.removeEventListener('pageshow', show);
  }

  menu.addEventListener('click', click);
  menu.addEventListener('keydown', keydown);
  menu.addEventListener('toggle', toggled);
  document.addEventListener('collaborate:notifications-changed', pageChanged);
  serviceWorker?.addEventListener('message', pushed);
  window.addEventListener('pagehide', hide);
  window.addEventListener('pageshow', show);
  formatDates(menu);
  settle();
  join();
  const pushing = push ? startPush() : Promise.resolve();

  return { stop, refresh, hear, pushing };
}

const started = new WeakSet();

/** Starts every bell of the page that has not started yet. */
export function startBells() {
  for (const root of document.querySelectorAll('[data-collaborate-notifications]')) {
    if (started.has(root)) continue;
    started.add(root);
    initBell(root);
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startBells);
else startBells();
