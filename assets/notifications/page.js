// @ts-check
// The page of all notifications (templates/notifications/_page.html.twig).
//
// The server renders the newest rows and, when older ones exist, a last row naming where they are
// (`.collaborate-notification-more[data-next]`, holding a button): the script asks for them when that row
// comes into view or its button is pressed, and puts them in its place. It tells the server what the reader
// marks read, unread or clears, one row or all of them, and tells the bell (bell.js) through the document
// (`collaborate:notifications-changed`, `origin: 'page'`), which tells it in return when the hub said the
// list changed elsewhere (`origin: 'bell'`, `heard`).
import { backgroundFetch } from '../http.js';
import { fetchFragment, formatDates, leaves, parseFragment, postChange, settingsOf } from './shared.js';

const ROW = '.collaborate-notification-row';
const MORE = '.collaborate-notification-more';

// How far under the window the older notifications are asked for.
export const MARGIN = '0px 0px 200px 0px';

// What a button of the toolbar tells the server, without an id.
/** @type {Record<string, string>} */
const ALL = { readAll: 'read', unreadAll: 'unread', clearAll: 'clear' };
const ONE = ['read', 'unread', 'clear'];

/**
 * @param {string[]} names
 * @param {string} [within]
 */
const buttons = (names, within = '') => names.map((name) => `${within}[data-action="${name}"]`).join(', ');

/**
 * The rows a fragment holds, with the row naming the next ones -- or null for anything else (a sign-in page).
 *
 * @param {string | null} html
 */
export function parseRows(html) {
  if (typeof html !== 'string') return null;
  const nodes = parseFragment(html);
  /** @param {ChildNode} node */
  const isRow = (node) =>
    node instanceof Element ? node.matches(`${ROW}, ${MORE}`) : /** @type {string} */ (node.textContent).trim() === '';
  return nodes.every(isRow) ? nodes.filter((node) => node instanceof Element) : null;
}

/**
 * Whether the reader confirms clearing them all, as the button asks (`data-confirm`): the host answers it
 * when it listens to `collaborate:notifications-confirm` and sets `detail.answer` (a boolean or a promise of
 * one), the browser's own dialog does otherwise. A button that asks nothing is not confirmed.
 *
 * @param {HTMLElement} button
 */
export async function confirmed(button) {
  const message = button.dataset.confirm;
  if (!message) return true;
  /** @type {{ message: string, answer: boolean | Promise<boolean> | undefined }} */
  const detail = { message, answer: undefined };
  document.dispatchEvent(new CustomEvent('collaborate:notifications-confirm', { detail }));
  if (detail.answer !== undefined) return Boolean(await detail.answer);
  return window.confirm(message);
}

/**
 * Loads the older notifications as the reader scrolls, and marks them read, unread or cleared.
 *
 * @param {HTMLElement} root
 * @param {{
 *   fetcher?: typeof fetch,
 *   navigate?: (url: string) => void,
 *   Observer?: typeof IntersectionObserver | undefined,
 * }} [options]
 */
export function initPage(
  root,
  { fetcher = backgroundFetch, navigate = (url) => location.assign(url), Observer = window.IntersectionObserver } = {}
) {
  const settings = settingsOf(root, 'data-collaborate-notifications-page');
  // Typed as found: a page without it does not start, which the line below checks.
  const list = /** @type {HTMLElement} */ (root.querySelector('.collaborate-notification-list'));
  if (!list) return null;
  const empty = /** @type {HTMLElement | null} */ (root.querySelector('.collaborate-notifications__empty'));
  // Where the keys go on from when what they were on is gone (tabindex="-1" in the template).
  const heading = /** @type {HTMLElement | null} */ (root.querySelector('.collaborate-notifications-page__title'));
  /** @type {Record<string, string>} */
  const hrefs = { read: settings.read, unread: settings.unread, clear: settings.clear };
  let stopped = false;

  // The requests of the page the server has not answered: the listings, and what the reader did.
  let loads = 0;
  let posts = 0;
  // The listing asked for last: an older one answered after it is dropped.
  let turn = 0;

  /** Whether the listing holds the unread notifications only: one that is read leaves it. */
  const isUnreadOnly = () =>
    new URL(/** @type {string} */ (list.dataset.src), location.href).searchParams.get('unread') === '1';

  // Nothing is said to be missing while older notifications are still to be asked for.
  function showEmpty() {
    if (empty) empty.hidden = list.querySelector(`${ROW}, ${MORE}`) !== null;
  }

  /** Gives the focus to a row: to its button of an action when it shows one, to the first thing it offers otherwise -- or to the title. */
  /**
   * @param {Element | null | undefined} row
   * @param {string | null} [action]
   */
  function focusRow(row, action = null) {
    const control =
      (action ? row?.querySelector(`[data-action="${action}"]:not([hidden])`) : null) ??
      row?.querySelector('a, button:not([hidden])');
    /** @type {HTMLElement | null | undefined} */ (control ?? heading)?.focus();
  }

  /** Remembers what holds the focus in the list, and answers what gives it back once the rows were written again. */
  function keepFocus() {
    const focused = /** @type {HTMLElement} */ (document.activeElement);
    const row = /** @type {HTMLElement | null} */ (focused.closest(ROW));
    if (!row || !list.contains(row)) return () => {};
    return () => {
      const again = [.../** @type {NodeListOf<HTMLElement>} */ (list.querySelectorAll(ROW))].find(
        (candidate) => candidate.dataset.id === row.dataset.id
      );
      focusRow(again, focused.dataset.action);
    };
  }

  /** @param {string} url */
  async function fetchRows(url) {
    loads += 1;
    try {
      return parseRows(await fetchFragment(url, fetcher));
    } finally {
      loads -= 1;
    }
  }

  /**
   * @param {string} url
   * @param {string | null} [id]
   */
  async function post(url, id = null) {
    posts += 1;
    try {
      return await postChange(url, id, fetcher, url === hrefs.clear ? settings.clearToken : null);
    } finally {
      posts -= 1;
    }
  }

  /**
   * Tells the bell and the host what is left.
   *
   * @param {{ unread: number, total?: number | null }} answer
   */
  function tell(answer) {
    document.dispatchEvent(
      new CustomEvent('collaborate:notifications-changed', {
        detail: { unread: answer.unread, total: answer.total ?? null, origin: 'page', heard: false },
      })
    );
  }

  // A function declaration: watch and loadMore name each other.
  async function loadMore() {
    const sentinel = /** @type {HTMLElement | null} */ (list.querySelector(MORE));
    // One listing at a time.
    if (!sentinel || loads > 0) return;
    const rows = await fetchRows(/** @type {string} */ (sentinel.dataset.next));
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
    const rows = await fetchRows(/** @type {string} */ (list.dataset.src));
    if (!rows || stopped || mine !== turn) return;
    const restore = keepFocus();
    list.replaceChildren(...rows);
    formatDates(list);
    restore();
    showEmpty();
    watch();
  }

  /**
   * @param {HTMLElement} row
   * @param {boolean} unread
   */
  function mark(row, unread) {
    const shown = /** @type {HTMLElement} */ (row.querySelector(buttons([unread ? 'read' : 'unread'])));
    row.dataset.unread = String(unread);
    /** @type {HTMLElement} */ (row.querySelector(buttons([unread ? 'unread' : 'read']))).hidden = true;
    shown.hidden = false;
    // The button that was pressed is gone.
    shown.focus();
  }

  /** @param {HTMLElement} row */
  function drop(row) {
    // The button that was pressed leaves with its row: the keys go on from the same button of the row that
    // follows, of the one before when it was the last.
    if (row.contains(document.activeElement)) {
      const neighbour = [row.nextElementSibling, row.previousElementSibling].find((sibling) => sibling?.matches(ROW));
      focusRow(neighbour, /** @type {HTMLElement} */ (document.activeElement).dataset.action);
    }
    row.remove();
    showEmpty();
    // The older ones, when the last row shown left.
    if (!list.querySelector(ROW)) loadMore();
  }

  /**
   * Marks a notification read or unread, or clears it, once the server knows.
   *
   * @param {string} action
   * @param {HTMLElement} row
   */
  async function actOnRow(action, row) {
    const answer = await post(hrefs[action], row.dataset.id);
    if (!answer || stopped) return;
    if (action === 'clear' || (action === 'read' && isUnreadOnly())) drop(row);
    else mark(row, action === 'unread');
    tell(answer);
  }

  /**
   * Marks every notification of the reader read or unread, or clears them all once confirmed.
   *
   * @param {string} action
   * @param {HTMLElement} button
   */
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
   *
   * @param {HTMLElement} button
   * @param {() => Promise<void>} work
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

  /**
   * Follows the link of an unread row once the server knows it read, as the bell does.
   *
   * @param {Element} link
   * @param {HTMLElement} row
   */
  async function follow(link, row) {
    await post(hrefs.read, row.dataset.id);
    if (!stopped) navigate(/** @type {string} */ (link.getAttribute('href')));
  }

  /** @param {MouseEvent} event */
  function click(event) {
    const one = /** @type {HTMLElement | null} */ (
      /** @type {Element} */ (event.target).closest(buttons(ONE, `${ROW} `))
    );
    if (one && list.contains(one)) {
      press(one, () =>
        actOnRow(/** @type {string} */ (one.dataset.action), /** @type {HTMLElement} */ (one.closest(ROW)))
      );
      return;
    }
    const all = /** @type {HTMLElement | null} */ (
      /** @type {Element} */ (event.target).closest(buttons(Object.keys(ALL)))
    );
    if (all) {
      press(all, () => actOnAll(/** @type {string} */ (all.dataset.action), all));
      return;
    }
    // Also where the row is watched: a listing that failed is asked for again.
    if (/** @type {Element} */ (event.target).closest(MORE)) {
      loadMore();
      return;
    }
    const link = /** @type {Element} */ (event.target).closest(`${ROW}[data-unread="true"] a[href]`);
    if (link && leaves(event)) {
      event.preventDefault();
      follow(link, /** @type {HTMLElement} */ (link.closest(ROW)));
    }
  }

  // The bell was told by the hub: the reader did something on another page, or was told something.
  /** @param {Event} event */
  function heard(event) {
    if (
      /** @type {CustomEvent} */ (event).detail?.origin === 'bell' &&
      /** @type {CustomEvent} */ (event).detail.heard &&
      loads + posts === 0
    )
      reload();
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
    initPage(/** @type {HTMLElement} */ (root));
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startPages);
else startPages();
