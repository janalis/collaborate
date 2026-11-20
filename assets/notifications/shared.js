// @ts-check
// What the bell (bell.js), the page of all notifications (page.js) and the topics list (topics.js) share. No side
// effect: bell.js starts every bell of the page when it loads, so the other two import from here.
import { backgroundFetch, isAccepted } from '../http.js';
import { settingsOf } from '../settings.js';

export { settingsOf };

/**
 * Writes every date of a notification in the language of the page, as the reader reads dates.
 *
 * @param {ParentNode} scope
 * @param {string | undefined} [lang]
 */
export function formatDates(scope, lang = document.documentElement.lang || undefined) {
  let format;
  try {
    format = new Intl.DateTimeFormat(lang, { dateStyle: 'long', timeStyle: 'short' });
  } catch {
    // A language the browser does not know.
    format = new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeStyle: 'short' });
  }
  for (const time of scope.querySelectorAll('time.collaborate-notification__date[datetime]')) {
    const date = new Date(/** @type {string} */ (time.getAttribute('datetime')));
    if (!Number.isNaN(date.getTime())) time.textContent = format.format(date);
  }
}

/**
 * The nodes of an HTML fragment, its scripts inert.
 *
 * @param {string} html
 */
export function parseFragment(html) {
  const template = document.createElement('template');
  template.innerHTML = html;
  return [...template.content.childNodes];
}

/**
 * A fragment the server rendered for a script, or null when it answered anything else (a sign-in page).
 *
 * @param {string} url
 * @param {typeof fetch} [fetcher]
 */
export async function fetchFragment(url, fetcher = backgroundFetch) {
  try {
    const response = await fetcher(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!isAccepted(response)) return null;
    return await response.text();
  } catch {
    return null;
  }
}

/**
 * Tells the server what was read, unread or cleared: one notification by its id, all of them without one.
 * Clearing takes the settings' `clearToken`. Answers the counts `{unread, total}`, or null when the server
 * could not be told.
 *
 * @param {string} url
 * @param {string | null} [id]
 * @param {typeof fetch} [fetcher]
 * @param {string | null} [token]
 * @returns {Promise<{unread: number, total?: number} | null>}
 */
export async function postChange(url, id = null, fetcher = backgroundFetch, token = null) {
  const body = new URLSearchParams();
  if (id) body.set('id', id);
  /** @type {Record<string, string>} */
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (token) headers['X-CSRF-Token'] = token;
  try {
    const response = await fetcher(url, { method: 'POST', credentials: 'same-origin', headers, body });
    if (!isAccepted(response)) return null;
    const answer = await response.json();
    return Number.isInteger(answer?.unread) ? answer : null;
  } catch {
    return null;
  }
}

/**
 * A click that leaves the page for the link, rather than opening it in another tab or window.
 *
 * @param {MouseEvent} event
 */
export const leaves = (event) =>
  event.button === 0 && !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
