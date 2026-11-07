// @ts-check
import { fetchEventSource } from '@microsoft/fetch-event-source';

// Captured when the module is evaluated, before any module of the bundle that imports it: collaboration traffic
// is background traffic, and a host that wraps `window.fetch` (a loading indicator, say) must load easyadmin.js
// before installing its wrapper. Every module sends with this one.
export const backgroundFetch = window.fetch.bind(window);
const hubs = new WeakMap();

/** The layer's `data-config` JSON: an absent or malformed one reads as no settings, each module keeping its defaults. */
export function layerConfig(layer) {
  try {
    const config = JSON.parse(layer?.dataset.config || '{}');
    return config && typeof config === 'object' && !Array.isArray(config) ? config : {};
  } catch {
    return {};
  }
}

/**
 * One Mercure subscription per page carries the topics of every module on it: a browser keeps six
 * HTTP/1.1 connections per origin, and each open stream holds one. Mercure does not name an event's
 * topic, so each payload names its `channel` (and a draft's payload its `draft`).
 *
 * A member is `{ channel, draft?, grant?(full), receive(message, data), opened?(session), closed?(), denied?() }`.
 */
export class CollaborateHub {
  /**
   * @param {HTMLElement} layer
   * @param {typeof fetch} [fetcher]
   * @param {typeof fetchEventSource} [subscribe]
   */
  constructor(layer, fetcher = backgroundFetch, subscribe = fetchEventSource) {
    this.layer = layer;
    this.fetcher = fetcher;
    this.subscribe = subscribe;
    this.members = new Set();
    this.failures = 0;
  }

  /** Adds a member and reconnects with its topic; the returned function removes it. */
  join(member) {
    this.members.add(member);
    this.reconnect();
    return () => {
      if (!this.members.delete(member)) return;
      // The last member closes the stream at once: a hidden or departing page holds no connection.
      if (this.members.size) this.reconnect();
      else this.close();
    };
  }

  close() {
    clearTimeout(this.joining);
    clearTimeout(this.renewTimer);
    clearTimeout(this.retryTimer);
    this.controller?.abort();
    this.streaming?.abort();
    this.streaming = null;
    this.lost = false;
  }

  // Modules of one page join on the same task: they share the next connection.
  reconnect() {
    clearTimeout(this.joining);
    this.joining = setTimeout(() => this.connect(), 0);
  }

  /**
   * Mercure here keeps no history: an event published while no stream is open is lost. The open stream
   * therefore stays until the one replacing it opens; an event both carry is delivered twice, which every
   * member tolerates (saved views refresh once a second, a draft replays, cursors drop older sequences).
   */
  async connect(full = false) {
    clearTimeout(this.joining);
    clearTimeout(this.renewTimer);
    clearTimeout(this.retryTimer);
    if (this.controller !== this.streaming) this.controller?.abort();
    if (!this.members.size) {
      this.close();
      return;
    }
    const controller = new AbortController();
    this.controller = controller;
    const { signal } = controller;
    const members = [...this.members];
    const drafts = members.filter((member) => member.channel === 'draft');
    try {
      const response = await this.fetcher(this.layer.dataset.sessionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.layer.dataset.csrf },
        body: JSON.stringify({
          grant: this.layer.dataset.grant,
          updates: members.some((member) => member.channel === 'updates'),
          // The user's own notifications topic is granted to a page that asks for it alone.
          notifications: members.some((member) => member.channel === 'notifications'),
          drafts: drafts.map((member) => member.grant(full)),
        }),
      });
      if (signal.aborted) return;
      if ([401, 403, 404].includes(response.status) || response.redirected) {
        // A page grant past its hours, or a CSRF token a new session replaced, renews once from the page.
        if (!this.renewed) {
          const renewed = await this.renew();
          if (signal.aborted) return;
          if (renewed) {
            this.renewed = true;
            this.connect(full);
            return;
          }
        }
        // The account lost its page: every member stops following it.
        this.renewed = false;
        this.members.clear();
        this.close();
        for (const member of members) member.denied?.();
        return;
      }
      if (!response.ok) throw new Error('Collaborate hub session unavailable.');
      this.renewed = false;
      const session = await response.json();
      if (signal.aborted) return;
      // A signature this server no longer knows is sent in full once.
      if (session.resend?.length && !full) {
        this.connect(true);
        return;
      }
      for (const index of session.denied || []) {
        this.members.delete(drafts[index]);
        drafts[index]?.denied?.();
      }
      const url = new URL(session.url, location.href);
      for (const topic of session.topics) url.searchParams.append('match', topic);
      // A page grant outlives the stream's token: the session is asked again before the token expires.
      this.renewTimer = setTimeout(() => this.connect(), layerConfig(this.layer).renew ?? 240_000);
      await this.subscribe(url.href, {
        fetch: this.fetcher,
        signal,
        openWhenHidden: true,
        headers: { Authorization: `Bearer ${session.token}` },
        onopen: async (opened) => {
          if (!opened.ok || !opened.headers.get('content-type')?.startsWith('text/event-stream'))
            throw new Error('Collaborate hub stream unavailable.');
          this.failures = 0;
          if (this.streaming !== controller) this.streaming?.abort();
          this.streaming = controller;
          const lost = this.lost;
          this.lost = false;
          for (const member of members) {
            if (!this.members.has(member)) continue;
            if (lost) member.closed?.();
            member.opened?.(session);
          }
        },
        onmessage: (message) => this.route(message),
        onclose() {
          throw new Error('Collaborate hub stream closed.');
        },
        onerror(error) {
          throw error;
        },
      });
    } catch {
      if (signal.aborted) return;
      const streamed = this.streaming === controller;
      if (streamed) this.streaming = null;
      // A newer connection is under way: it reports for the members, this stream's loss included. Only a
      // stream that was open is left running for a newer connection; any other was aborted by it.
      if (this.controller !== controller) {
        this.lost = true;
        return;
      }
      // The stream this one was to replace still carries its topics: retry without reporting a loss.
      if (!this.streaming) {
        this.lost = false;
        for (const member of members) if (this.members.has(member)) member.closed?.();
      }
      this.retryTimer = setTimeout(() => this.connect(), Math.min(30_000, 1000 * 2 ** Math.min(this.failures++, 5)));
    }
  }

  /** Reads a fresh grant and CSRF token from the page itself: the grant names its route. */
  async renew() {
    try {
      // The header has the server put back the flash messages this render reads.
      const response = await this.fetcher(location.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Collaborate-Renew': '1' },
      });
      if (!response.ok || response.redirected) return false;
      const fresh = /** @type {HTMLElement | null} */ (
        new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-collaborate-layer]')
      );
      if (!fresh?.dataset.grant || !fresh.dataset.csrf) return false;
      this.layer.dataset.grant = fresh.dataset.grant;
      this.layer.dataset.csrf = fresh.dataset.csrf;
      return true;
    } catch {
      return false;
    }
  }

  route(message) {
    let data;
    try {
      data = JSON.parse(message.data);
    } catch {
      return;
    }
    for (const member of this.members) {
      // A notice naming several drafts names none: every draft session hears it.
      if (member.channel === data?.channel && (!data.draft || data.draft === member.draft))
        member.receive(message, data);
    }
  }
}

/**
 * The hub of the page's collaboration layer, which carries its grant, its CSRF token and the session URL.
 *
 * @param {HTMLElement | null} [layer]
 * @param {typeof fetch} [fetcher]
 * @param {typeof fetchEventSource} [subscribe]
 * @returns {CollaborateHub | null}
 */
export function hubFor(
  layer = /** @type {HTMLElement | null} */ (document.querySelector('[data-collaborate-layer]')),
  fetcher = undefined,
  subscribe = undefined
) {
  if (!layer?.dataset.sessionUrl) return null;
  if (!hubs.has(layer)) hubs.set(layer, new CollaborateHub(layer, fetcher, subscribe));
  return hubs.get(layer);
}
