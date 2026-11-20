import { HttpError, isAccepted, isDenied, postJson } from '../../assets/http.js';

const answer = (status, redirected = false) => ({ status, ok: status >= 200 && status < 300, redirected });

test('a JSON request carries the session cookies, the CSRF token and the caller options', async () => {
  const fetcher = vi.fn(async () => answer(204));
  const signal = new AbortController().signal;
  await postJson(fetcher, '/collaborate/join', 'token', { grant: 'g' }, { signal, keepalive: true });
  expect(fetcher).toHaveBeenCalledWith('/collaborate/join', {
    signal,
    keepalive: true,
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'token' },
    body: '{"grant":"g"}',
  });
  // A page without a token sends an empty one, which the server refuses.
  await postJson(fetcher, '/collaborate/join', undefined, {});
  expect(fetcher.mock.calls[1][1].headers['X-CSRF-Token']).toBe('');
});

test('a refusal is a 401, a 403 or a redirect; an acceptance a success answered by the endpoint itself', () => {
  expect([200, 401, 403, 404, 500].map((status) => isDenied(answer(status)))).toEqual([
    false,
    true,
    true,
    false,
    false,
  ]);
  expect(isDenied(answer(200, true))).toBe(true);
  expect([200, 204, 404].map((status) => isAccepted(answer(status)))).toEqual([true, true, false]);
  expect(isAccepted(answer(200, true))).toBe(false);
  const error = new HttpError('Collaborate join: 409', 409);
  expect([error.message, error.status, error instanceof Error]).toEqual(['Collaborate join: 409', 409, true]);
});
