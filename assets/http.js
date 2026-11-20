// @ts-check
// Captured when the module is evaluated, before any module of the bundle that imports it: collaboration traffic
// is background traffic, and a host that wraps `window.fetch` (a loading indicator, say) must load easyadmin.js
// before installing its wrapper. Every module sends with this one. No other side effect: any module may import it.

export const backgroundFetch = window.fetch.bind(window);

/** A failed answer, with its status: a caller tells a conflict from a full draft or a busy server. */
export class HttpError extends Error {
  /**
   * @param {string} message
   * @param {number} status
   */
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

/**
 * A JSON request the way every endpoint of the bundle takes one: POSTed with the session's cookies and the CSRF
 * token the page was given.
 *
 * @param {typeof fetch} fetcher
 * @param {string} url
 * @param {string | undefined} csrf
 * @param {unknown} body
 * @param {RequestInit} [init] the rest: a signal, keepalive
 */
export function postJson(fetcher, url, csrf, body, init = {}) {
  return fetcher(url, {
    ...init,
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf ?? '' },
    body: JSON.stringify(body),
  });
}

/**
 * Whether the server refused who asked: a 401 or a 403, or a redirect, which is to its sign-in page.
 *
 * @param {Response} response
 */
export function isDenied(response) {
  return response.redirected || response.status === 401 || response.status === 403;
}

/**
 * Whether the endpoint itself answered with success, not a sign-in page it was redirected to.
 *
 * @param {Response} response
 */
export function isAccepted(response) {
  return response.ok && !response.redirected;
}
