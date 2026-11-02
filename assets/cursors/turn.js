// One tab of a browser at a time shares its cursor over HTTP/1.1 (`cursors.share_stream`): a browser opens six
// connections to a site, all its tabs together, and each open stream holds one. Over HTTP/2 or 3 they share
// one connection, and every tab streams.
export const LOCK = 'collaborate-cursors';

/** Whether the page came over a connection every request of the site shares (HTTP/2, HTTP/3). */
export function isMultiplexed() {
  return ['h2', 'h3'].includes(performance.getEntriesByType?.('navigation')[0]?.nextHopProtocol);
}

/**
 * The turn of a page among the tabs of its browser: `take()` resolves once the page holds it, `give()` lets the
 * next tab have it. A page that does not need one (a multiplexed connection, a browser without Web Locks) holds
 * it at once.
 */
export function createTurn(locks = navigator.locks, multiplexed = isMultiplexed) {
  let turn = null;

  function take() {
    if (multiplexed() || !locks) return Promise.resolve();
    if (!turn) {
      const waiting = new AbortController();
      const current = { given: false, release: () => {} };
      current.give = () => {
        current.given = true;
        waiting.abort();
        current.release();
      };
      current.ready = new Promise((granted, lost) => {
        current.held = locks.request(LOCK, { signal: waiting.signal }, async () => {
          // A turn that comes once it was given up is let go of at once.
          if (current.given) return;
          granted();
          await new Promise((release) => {
            current.release = release;
          });
        });
        // A turn the browser failed to give is asked for again by the next stream.
        current.held.catch((error) => {
          if (turn === current) turn = null;
          lost(error);
        });
      });
      turn = current;
    }
    return turn.ready;
  }

  function give() {
    turn?.give();
    turn = null;
  }

  return { take, give };
}
