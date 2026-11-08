// @ts-check

// How long the hub has to acknowledge a subscription, the first one or a renewal.
export const ACK_TIMEOUT = 10_000;

/**
 * @typedef {{
 *   url: string,
 *   renew: (token: string, signal?: AbortSignal) => Promise<void>,
 *   send: (message: object) => boolean,
 * }} WebSocketHandle
 * @typedef {Error & { code?: number }} WebSocketFailure
 */

/** The hub's WebSocket URL: a relative one is the page's origin, and http(s) maps to ws(s). */
export function websocketUrl(url, base = location.href) {
  const target = new URL(url, base);
  if (target.protocol === 'http:') target.protocol = 'ws:';
  else if (target.protocol === 'https:') target.protocol = 'wss:';
  target.hash = '';
  return target.href;
}

/**
 * @param {string} message
 * @param {number} [code]
 * @returns {WebSocketFailure}
 */
const failure = (message, code = undefined) => Object.assign(new Error(message), { code });

/**
 * The page's subscription as a WebSocket to the bundled hub. The token goes in the first frame, never in the
 * URL; the stream counts as open once the hub acknowledges it. Notices come as text frames holding the notice
 * JSON; a frame with a top-level `hub` key is the hub's own answer.
 *
 * Resolves once `signal` aborts, closing the socket normally; rejects with the close code as `code` when the
 * socket closes, or when the first subscription is refused (4401) or not acknowledged in time.
 *
 * `onopen` receives a handle whose `renew(token)` subscribes again on the same socket: the hub replaces the
 * topics at once. It rejects on a refusal or an acknowledgement that does not come, the socket staying open.
 * Its `send(message)` sends a frame of the page's own (a cursor event), returning whether it left.
 *
 * @param {string} url
 * @param {{
 *   signal: AbortSignal,
 *   token: string,
 *   onopen: (handle: WebSocketHandle) => void,
 *   onmessage: (message: { data: string }) => void,
 *   WebSocket?: typeof WebSocket,
 * }} options
 * @returns {Promise<void>}
 */
export function websocketSource(url, { signal, token, onopen, onmessage, WebSocket: Socket = globalThis.WebSocket }) {
  return new Promise((resolve, reject) => {
    if (signal.aborted) {
      resolve();
      return;
    }
    let socket;
    try {
      socket = new Socket(websocketUrl(url));
    } catch (error) {
      // A URL no socket can open (another scheme, mixed content): retrying soon would not help.
      reject(failure(`Collaborate hub stream unavailable: ${error.message}`, 1008));
      return;
    }
    /** @type {Map<number, { resolve: () => void, reject: (error: Error) => void }>} */
    const acks = new Map();
    let sequence = 0;
    let opened = false;
    let settled = false;

    /** @param {WebSocketFailure} [error] */
    const finish = (error = undefined) => {
      if (settled) return;
      settled = true;
      signal.removeEventListener('abort', abort);
      for (const ack of [...acks.values()]) ack.reject(error ?? failure('Collaborate hub stream closed.', 1000));
      if (error) reject(error);
      else resolve();
    };
    /** @param {WebSocketFailure} error */
    const fail = (error) => {
      socket.close(1000);
      finish(error);
    };
    function abort() {
      socket.close(1000);
      finish();
    }

    /**
     * @param {string} token
     * @param {AbortSignal} [cancel]
     * @returns {Promise<void>}
     */
    const subscribe = (token, cancel = undefined) =>
      new Promise((acknowledged, refused) => {
        if (settled || cancel?.aborted) {
          refused(failure('Collaborate hub stream closed.', 1000));
          return;
        }
        const id = ++sequence;
        const done = () => {
          clearTimeout(timer);
          acks.delete(id);
          cancel?.removeEventListener('abort', cancelled);
        };
        const cancelled = () => {
          done();
          refused(failure('Collaborate hub subscription cancelled.'));
        };
        const timer = setTimeout(() => {
          done();
          refused(failure('Collaborate hub subscription unacknowledged.'));
        }, ACK_TIMEOUT);
        acks.set(id, {
          resolve: () => {
            done();
            acknowledged();
          },
          reject: (error) => {
            done();
            refused(error);
          },
        });
        cancel?.addEventListener('abort', cancelled, { once: true });
        // A socket already closing drops it: its close event, or the deadline, refuses the subscription.
        socket.send(JSON.stringify({ hub: 'subscribe', id, token }));
      });

    /** @param {{ hub: unknown, id?: unknown }} control */
    const answer = (control) => {
      const ack = acks.get(/** @type {number} */ (control.id));
      if (!ack) return;
      if (control.hub === 'subscribed') ack.resolve();
      else if (control.hub === 'error') ack.reject(failure('Collaborate hub subscription refused.', 4401));
    };

    /**
     * Sends a frame of the page's own on the subscribed socket, without waiting for any answer.
     *
     * @param {object} message
     * @returns {boolean} whether the frame left: never before the subscription stands, nor once the socket closes
     */
    const send = (message) => {
      if (!opened || settled || socket.readyState !== 1) return false;
      socket.send(JSON.stringify(message));
      return true;
    };

    signal.addEventListener('abort', abort, { once: true });
    socket.onopen = () => {
      subscribe(token).then(() => {
        opened = true;
        onopen({ url, renew: subscribe, send });
      }, fail);
    };
    socket.onmessage = (event) => {
      if (typeof event.data !== 'string') return;
      let data = null;
      try {
        data = JSON.parse(event.data);
      } catch {
        // Not the hub's: the notice's reader decides.
      }
      if (data && typeof data === 'object' && !Array.isArray(data) && 'hub' in data) answer(data);
      else if (opened) onmessage({ data: event.data });
    };
    socket.onclose = (event) => finish(failure('Collaborate hub stream closed.', event.code));
  });
}
