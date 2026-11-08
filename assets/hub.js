// @ts-check
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
 * A transport's source: subscribes to `url` and delivers its notices until `signal` aborts (it then resolves),
 * rejecting when the stream fails. `onopen` runs once the stream is open, with a handle that renews the
 * subscription in place when the transport can (the WebSocket hub can, a Mercure stream cannot). A rejection
 * may name the close code that ended the stream as `code`.
 *
 * @typedef {{
 *   url: string,
 *   renew?: (token: string, signal?: AbortSignal) => Promise<void>,
 *   send?: (message: object) => boolean,
 * }} SourceHandle
 * @typedef {{
 *   signal: AbortSignal,
 *   token: string,
 *   topics: string[],
 *   fetch: typeof fetch,
 *   onopen: (handle?: SourceHandle) => void,
 *   onmessage: (message: { data: string }) => void,
 * }} SourceOptions
 * @typedef {(url: string, options: SourceOptions) => Promise<void>} Source
 * @typedef {Record<string, () => Promise<Source>>} Sources
 * @typedef {{ controller: AbortController, members: any[] }} Attempt
 */

/**
 * Each transport's module, loaded once its session names it (or, after the first, while the session is asked).
 *
 * @type {Sources}
 */
export const sources = {
  mercure: () => import('./hub/mercure.js').then((module) => module.mercureSource),
  websocket: () => import('./hub/websocket.js').then((module) => module.websocketSource),
};

// The longest wait between two attempts; a client error (close code 1008) waits it at once.
const MAX_BACKOFF = 30_000;
// Handshakes failing in a row before the console names the likely cause.
const HINTED_HANDSHAKES = 3;

/**
 * One subscription per page carries the topics of every module on it: a browser keeps six HTTP/1.1
 * connections per origin, and each open stream holds one. The hub does not name a notice's topic, so each
 * payload names its `channel` (and a draft's payload its `draft`). The session answer names the transport:
 * a Mercure stream, or a WebSocket to the bundled hub.
 *
 * A member is `{ channel, draft?, grant?(full), receive(message, data), opened?(session), closed?(), denied?() }`.
 */
export class CollaborateHub {
  /**
   * @param {HTMLElement} layer
   * @param {typeof fetch} [fetcher]
   * @param {Sources} [transports]
   */
  constructor(layer, fetcher = backgroundFetch, transports = sources) {
    this.layer = layer;
    this.fetcher = fetcher;
    this.sources = transports;
    /** @type {Record<string, Promise<Source>>} */
    this.loading = {};
    this.members = new Set();
    this.failures = 0;
    this.handshakes = 0;
    /** @type {AbortController | null} */
    this.controller = null;
    /** @type {AbortController | null} */
    this.streaming = null;
    /**
     * The open socket, while one is: renewed in place, and carrying the page's cursor events once its session
     * says the hub takes them.
     *
     * @type {{
     *   attempt: Attempt,
     *   transport: string,
     *   url: string,
     *   renew: (token: string, signal?: AbortSignal) => Promise<void>,
     *   send?: (message: object) => boolean,
     *   cursorEvents?: boolean,
     * } | null}
     */
    this.live = null;
    /** @type {string | undefined} */
    this.transport = undefined;
    // Browsers keep a socket's pings to themselves: a renewal is how the page learns a socket still answers,
    // checked when the device comes back online and when the page shows again.
    const probe = () => {
      if (this.live && !document.hidden) this.reconnect();
    };
    window.addEventListener('online', probe);
    document.addEventListener('visibilitychange', probe);
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
    this.live = null;
    this.lost = false;
  }

  // Modules of one page join on the same task: they share the next connection.
  reconnect() {
    clearTimeout(this.joining);
    this.joining = setTimeout(() => this.connect(), 0);
  }

  /**
   * The hub keeps no history: an event published while no stream is open is lost. The open stream therefore
   * stays until the one replacing it opens, or is renewed in place when its transport can; an event both
   * carry is delivered twice, which every member tolerates (saved views refresh once a second, a draft
   * replays, cursors drop older sequences).
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
    /** @type {Attempt} */
    const attempt = { controller, members };
    /** @type {string | undefined} */
    let transport;
    let opened = false;
    // The transport's module loads while the session is asked: the one of the last session, or both at first.
    for (const name of this.transport ? [this.transport] : Object.keys(this.sources))
      this.#source(name).catch(() => {});
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
      // A server from before the WebSocket hub names no transport: Mercure was its only one.
      transport = session.transport ?? 'mercure';
      this.transport = transport;
      // A page grant outlives the stream's token: the session is asked again before the token expires.
      this.renewTimer = setTimeout(() => this.connect(), layerConfig(this.layer).renew ?? 240_000);
      const live = this.live;
      if (
        live &&
        this.streaming === live.attempt.controller &&
        live.transport === transport &&
        live.url === session.url
      ) {
        // The open socket takes the new token and topics at once; one that refuses them, or no longer answers,
        // is replaced by a new one below.
        try {
          await live.renew(session.token, signal);
          if (signal.aborted) return;
          // The open stream is this connection's own again: its loss is retried as any other.
          this.controller = live.attempt.controller;
          live.attempt.members = members;
          this.#opened(live.attempt, session);
          return;
        } catch {
          if (signal.aborted) return;
        }
      }
      const source = await this.#source(transport);
      if (signal.aborted) return;
      await source(session.url, {
        signal,
        token: session.token,
        topics: session.topics,
        fetch: this.fetcher,
        onopen: (handle) => {
          opened = true;
          this.live = handle?.renew
            ? { attempt, transport, url: handle.url, renew: handle.renew, send: handle.send }
            : null;
          this.#opened(attempt, session);
        },
        onmessage: (message) => this.route(message),
      });
    } catch (error) {
      if (signal.aborted) return;
      if (this.live?.attempt === attempt) this.live = null;
      const streamed = this.streaming === controller;
      if (streamed) this.streaming = null;
      this.#hint(transport, opened, error);
      // A newer connection is under way: it reports for the members, this stream's loss included. Only a
      // stream that was open is left running for a newer connection; any other was aborted by it.
      if (this.controller !== controller) {
        this.lost = true;
        return;
      }
      // The stream this one was to replace still carries its topics: retry without reporting a loss.
      if (!this.streaming) {
        this.lost = false;
        for (const member of attempt.members) if (this.members.has(member)) member.closed?.();
      }
      this.retryTimer = setTimeout(() => this.connect(), this.#backoff(transport, error));
    }
  }

  /** The transport's source, its module loaded once (and again after a failed load). */
  #source(transport) {
    this.loading[transport] ??= Promise.resolve()
      .then(() => {
        const load = this.sources[transport];
        if (!load) throw new Error(`Collaborate hub transport "${transport}" unknown.`);
        return load();
      })
      .catch((error) => {
        delete this.loading[transport];
        throw error;
      });
    return this.loading[transport];
  }

  /**
   * The stream open: it replaces the one it was to, and its members hear of it (of the loss first, when the
   * stream before it failed meanwhile).
   *
   * @param {Attempt} attempt
   * @param {{ cursorEvents?: boolean }} session
   */
  #opened(attempt, session) {
    this.failures = 0;
    this.handshakes = 0;
    this.reauthorized = false;
    if (this.streaming !== attempt.controller) this.streaming?.abort();
    this.streaming = attempt.controller;
    // Each session says anew whether the hub takes the page's cursor events on the socket.
    if (this.live?.attempt === attempt) this.live.cursorEvents = session.cursorEvents === true;
    const lost = this.lost;
    this.lost = false;
    for (const member of attempt.members) {
      if (!this.members.has(member)) continue;
      if (lost) member.closed?.();
      member.opened?.(session);
    }
  }

  /**
   * How long to wait before the next attempt, a random part of it spreading the pages a restart dropped at
   * once. A token the WebSocket hub refused (4401) asks for a new session at once, once in a row; a client
   * error (1008) would fail again soon, and waits the longest.
   *
   * @param {string | undefined} transport
   * @param {{ code?: number } | undefined} error
   */
  #backoff(transport, error) {
    if (transport === 'websocket' && error?.code === 4401 && !this.reauthorized) {
      this.reauthorized = true;
      return 0;
    }
    const delay =
      transport === 'websocket' && error?.code === 1008
        ? MAX_BACKOFF
        : Math.min(MAX_BACKOFF, 1000 * 2 ** Math.min(this.failures, 5));
    this.failures++;
    return delay / 2 + Math.random() * (delay / 2);
  }

  /**
   * A WebSocket that fails its handshake again and again is most often a proxy that does not forward the
   * upgrade: the console says so once.
   *
   * @param {string | undefined} transport
   * @param {boolean} opened
   * @param {{ code?: number } | undefined} error
   */
  #hint(transport, opened, error) {
    if (transport !== 'websocket' || opened || error?.code !== 1006) return;
    if (++this.handshakes !== HINTED_HANDSHAKES || this.hinted) return;
    this.hinted = true;
    console.warn(
      'Collaborate: the WebSocket hub could not be reached several times in a row. A reverse proxy that does not ' +
        'forward WebSocket upgrades (Upgrade and Connection headers) is the usual cause: see ' +
        'docs/guide/websocket-hub.md.'
    );
  }

  /**
   * Sends a frame on the open socket when its session lets the page send there (cursor events): true once it
   * left. Anything else (a Mercure stream, no stream, a socket closing) is false, and the caller posts instead.
   *
   * @param {object} message
   * @returns {boolean}
   */
  send(message) {
    return this.live?.cursorEvents === true && this.live.send?.(message) === true;
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
 * @param {Sources} [transports]
 * @returns {CollaborateHub | null}
 */
export function hubFor(
  layer = /** @type {HTMLElement | null} */ (document.querySelector('[data-collaborate-layer]')),
  fetcher = undefined,
  transports = undefined
) {
  if (!layer?.dataset.sessionUrl) return null;
  if (!hubs.has(layer)) hubs.set(layer, new CollaborateHub(layer, fetcher, transports));
  return hubs.get(layer);
}
