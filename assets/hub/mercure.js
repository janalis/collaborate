// @ts-check

/**
 * The page's subscription as a Mercure stream: one `match` parameter per topic, the token as a bearer. The
 * event-source package is loaded with the first stream, so a page on the WebSocket hub never fetches it.
 *
 * Resolves once `signal` aborts; rejects when the stream fails to open, closes or errs.
 *
 * @param {string} url
 * @param {{
 *   signal: AbortSignal,
 *   token: string,
 *   topics: string[],
 *   fetch?: typeof fetch,
 *   onopen: () => void,
 *   onmessage: (message: { data: string }) => void,
 * }} options
 * @returns {Promise<void>}
 */
export async function mercureSource(url, { signal, token, topics, fetch, onopen, onmessage }) {
  const { fetchEventSource } = await import('@microsoft/fetch-event-source');
  if (signal.aborted) return;
  const target = new URL(url, location.href);
  for (const topic of topics) target.searchParams.append('match', topic);
  await fetchEventSource(target.href, {
    fetch,
    signal,
    openWhenHidden: true,
    headers: { Authorization: `Bearer ${token}` },
    onopen: async (opened) => {
      if (!opened.ok || !opened.headers.get('content-type')?.startsWith('text/event-stream'))
        throw new Error('Collaborate hub stream unavailable.');
      onopen();
    },
    onmessage,
    onclose() {
      throw new Error('Collaborate hub stream closed.');
    },
    onerror(error) {
      throw error;
    },
  });
}
