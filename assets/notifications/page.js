// The page of all notifications (templates/notifications/_page.html.twig).
//
// The server renders the newest rows and, when older ones exist, a last row naming where they are
// (`.collaborate-notification-more[data-next]`, holding a button): the script asks for them when that row
// comes into view or its button is pressed, and puts them in its place. It tells the server what the reader
// marks read, unread or clears, one row or all of them, and tells the bell (bell.js) through the document
// (`collaborate:notifications-changed`, `origin: 'page'`), which tells it in return when the hub said the
// list changed elsewhere (`origin: 'bell'`, `heard`).
import { backgroundFetch } from '../hub.js';
import { fetchFragment, formatDates, leaves, parseFragment, postChange, settingsOf } from './bell.js';

const ROW = '.collaborate-notification-row';
const MORE = '.collaborate-notification-more';

// How far under the window the older notifications are asked for.
export const MARGIN = '0px 0px 200px 0px';

// What a button of the toolbar tells the server, without an id.
const ALL = { readAll: 'read', unreadAll: 'unread', clearAll: 'clear' };
const ONE = ['read', 'unread', 'clear'];

const buttons = (names, within = '') => names.map((name) => `${within}[data-action="${name}"]`).join(', ');

/** The rows a fragment holds, with the row naming the next ones -- or null for anything else (a sign-in page). */
export function parseRows(html) {
  if (typeof html !== 'string') return null;
  const nodes = parseFragment(html);
  const isRow = (node) => (node instanceof Element ? node.matches(`${ROW}, ${MORE}`) : node.textContent.trim() === '');
  return nodes.every(isRow) ? nodes.filter((node) => node instanceof Element) : null;
}

/**
 * Whether the reader confirms clearing them all, as the button asks (`data-confirm`): the host answers it
 * when it listens to `collaborate:notifications-confirm` and sets `detail.answer` (a boolean or a promise of
 * one), the browser's own dialog does otherwise. A button that asks nothing is not confirmed.
 */
export async function confirmed(button) {
  const message = button.dataset.confirm;
  if (!message) return true;
  const detail = { message, answer: undefined };
  document.dispatchEvent(new CustomEvent('collaborate:notifications-confirm', { detail }));
  if (detail.answer !== undefined) return Boolean(await detail.answer);
  return window.confirm(message);
}

/** Loads the older notifications as the reader scrolls, and marks them read, unread or cleared. */
export function initPage(
  root,
  { fetcher = backgroundFetch, navigate = (url) => location.assign(url), Observer = window.IntersectionObserver } = {}
) {
  const settings = settingsOf(root, 'data-collaborate-notifications-page');
  const list = root.querySelector('.collaborate-notification-list');
  if (!list) return null;
  const empty = root.querySelector('.collaborate-notifications__empty');
  // Where the keys go on from when what they were on is gone (tabindex="-1" in the template).
  const heading = root.querySelector('.collaborate-notifications-page__title');
  const hrefs = { read: settings.read, unread: settings.unread, clear: settings.clear };
  let stopped = false;

  // The requests of the page the server has not answered: the listings, and what the reader did.
  let loads = 0;
  let posts = 0;
  // The listing asked for last: an older one answered after it is dropped.
  let turn = 0;

  /** Whether the listing holds the unread notifications only: one that is read leaves it. */
  const isUnreadOnly = () => new URL(list.dataset.src, location.href).searchParams.get('unread') === '1';

  // Nothing is said to be missing while older notifications are still to be asked for.
  function showEmpty() {
    if (empty) empty.hidden = list.querySelector(`${ROW}, ${MORE}`) !== null;
  }

  /** Gives the focus to a row: to its button of an action when it shows one, to the first thing it offers otherwise -- or to the title. */
  function focusRow(row, action = null) {
    const control =
      (action ? row?.querySelector(`[data-action="${action}"]:not([hidden])`) : null) ??
      row?.querySelector('a, button:not([hidden])');
    (control ?? heading)?.focus();
  }

  /** Remembers what holds the focus in the list, and answers what gives it back once the rows were written again. */
  function keepFocus() {
    const focused = document.activeElement;
    const row = focused.closest(ROW);
    if (!row || !list.contains(row)) return () => {};
    return () => {
      const again = [...list.querySelectorAll(ROW)].find((candidate) => candidate.dataset.id === row.dataset.id);
      focusRow(again, focused.dataset.action);
    };
  }

  async function fetchRows(url) {
    loads += 1;
    try {
      return parseRows(await fetchFragment(url, fetcher));
    } finally {
      loads -= 1;
    }
  }

  async function post(url, id = null) {
    posts += 1;
    try {
      return await postChange(url, id, fetcher);
    } finally {
      posts -= 1;
    }
  }

  /** Tells the bell and the host what is left. */
  function tell(answer) {
    document.dispatchEvent(
      new CustomEvent('collaborate:notifications-changed', {
        detail: { unread: answer.unread, total: answer.total ?? null, origin: 'page', heard: false },
      })
    );
  }

  // A function declaration: watch and loadMore name each other.
  async function loadMore() {
    const sentinel = list.querySelector(MORE);
    // One listing at a time.
    if (!sentinel || loads > 0) return;
    const rows = await fetchRows(sentinel.dataset.next);
    // A failure leaves the row where it is: scrolling to it again asks again.
    if (!rows || stopped || !sentinel.isConnected) return;
    // The keys were on the button that leaves: they go on from the first of the rows it brought.
    const focused = sentinel.contains(document.activeElement);
    sentinel.replaceWith(...rows);
    formatDates(list);
    if (focused) focusRow(rows[0]);
    showEmpty();
    watch();
  }

  const observer = Observer
    ? new Observer(
        (entries) => {
          if (entries.some((entry) => entry.isIntersecting)) loadMore();
        },
        { rootMargin: MARGIN }
      )
    : null;

  /** Watches the row naming the next notifications; its button asks where a browser cannot. */
  function watch() {
    observer?.disconnect();
    const sentinel = list.querySelector(MORE);
    if (sentinel) observer?.observe(sentinel);
  }

  /** Asks for the first notifications of the listing again, and shows them in place of the list. */
  async function reload() {
    turn += 1;
    const mine = turn;
    const rows = await fetchRows(list.dataset.src);
    if (!rows || stopped || mine !== turn) return;
    const restore = keepFocus();
    list.replaceChildren(...rows);
    formatDates(list);
    restore();
    showEmpty();
    watch();
  }

  function mark(row, unread) {
    const shown = row.querySelector(buttons([unread ? 'read' : 'unread']));
    row.dataset.unread = String(unread);
    row.querySelector(buttons([unread ? 'unread' : 'read'])).hidden = true;
    shown.hidden = false;
    // The button that was pressed is gone.
    shown.focus();
  }

  function drop(row) {
    // The button that was pressed leaves with its row: the keys go on from the same button of the row that
    // follows, of the one before when it was the last.
    if (row.contains(document.activeElement)) {
      const neighbour = [row.nextElementSibling, row.previousElementSibling].find((sibling) => sibling?.matches(ROW));
      focusRow(neighbour, document.activeElement.dataset.action);
    }
    row.remove();
    showEmpty();
    // The older ones, when the last row shown left.
    if (!list.querySelector(ROW)) loadMore();
  }

  /** Marks a notification read or unread, or clears it, once the server knows. */
  async function actOnRow(action, row) {
    const answer = await post(hrefs[action], row.dataset.id);
    if (!answer || stopped) return;
    if (action === 'clear' || (action === 'read' && isUnreadOnly())) drop(row);
    else mark(row, action === 'unread');
    tell(answer);
  }

  /** Marks every notification of the reader read or unread, or clears them all once confirmed. */
  async function actOnAll(action, button) {
    if (action === 'clearAll' && !(await confirmed(button))) return;
    const answer = await post(hrefs[ALL[action]]);
    if (!answer || stopped) return;
    tell(answer);
    if (action === 'clearAll') {
      // An older listing still to be answered is dropped.
      turn += 1;
      list.replaceChildren();
      showEmpty();
      watch();
      return;
    }
    await reload();
  }

  /**
   * Keeps a button from being pressed again while the server is told. It is not disabled, which would take the
   * focus away from it: it says so (aria-disabled), and answers nothing meanwhile.
   */
  async function press(button, work) {
    if (button.getAttribute('aria-disabled') === 'true') return;
    button.setAttribute('aria-disabled', 'true');
    try {
      await work();
    } finally {
      button.removeAttribute('aria-disabled');
    }
  }

  /** Follows the link of an unread row once the server knows it read, as the bell does. */
  async function follow(link, row) {
    await post(hrefs.read, row.dataset.id);
    if (!stopped) navigate(link.getAttribute('href'));
  }

  function click(event) {
    const one = event.target.closest(buttons(ONE, `${ROW} `));
    if (one && list.contains(one)) {
      press(one, () => actOnRow(one.dataset.action, one.closest(ROW)));
      return;
    }
    const all = event.target.closest(buttons(Object.keys(ALL)));
    if (all) {
      press(all, () => actOnAll(all.dataset.action, all));
      return;
    }
    // Also where the row is watched: a listing that failed is asked for again.
    if (event.target.closest(MORE)) {
      loadMore();
      return;
    }
    const link = event.target.closest(`${ROW}[data-unread="true"] a[href]`);
    if (link && leaves(event)) {
      event.preventDefault();
      follow(link, link.closest(ROW));
    }
  }

  // The bell was told by the hub: the reader did something on another page, or was told something.
  function heard(event) {
    if (event.detail?.origin === 'bell' && event.detail.heard && loads + posts === 0) reload();
  }

  function stop() {
    stopped = true;
    observer?.disconnect();
    root.removeEventListener('click', click);
    document.removeEventListener('collaborate:notifications-changed', heard);
  }

  root.addEventListener('click', click);
  document.addEventListener('collaborate:notifications-changed', heard);
  formatDates(list);
  showEmpty();
  watch();

  return { stop, reload, loadMore };
}

const started = new WeakSet();

/** Starts every page of notifications that has not started yet. */
export function startPages() {
  for (const root of document.querySelectorAll('[data-collaborate-notifications-page]')) {
    if (started.has(root)) continue;
    started.add(root);
    initPage(root);
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startPages);
else startPages();
