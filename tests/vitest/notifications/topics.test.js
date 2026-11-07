import { initTopics } from '../../../assets/notifications/topics.js';

function render({ status = '', label = true } = {}) {
  document.body.innerHTML = `<div class="collaborate-notification-topics">
    <ul>
      <li><label class="collaborate-notification-topic__switch">
        <input type="checkbox" role="switch" name="comments" data-subscribe="/t/comments/subscribe" data-unsubscribe="/t/comments/unsubscribe" checked>
        ${label ? '<span class="collaborate-notification-topic__label"> Comments </span>' : ''}
      </label></li>
      <li><input type="checkbox" name="other"></li>
    </ul>
    <p class="collaborate-notification-topics__status" role="status"${status}></p>
  </div>`;
  const root = document.body.firstElementChild;
  root.setAttribute('data-collaborate-notification-topics', JSON.stringify({ topicsToken: 'topics-token' }));
  return root;
}

const SENTENCES =
  ' data-subscribed="Told of %topic%" data-unsubscribed="No more %topic%" data-failed="%topic% unchanged"';
const input = () => document.querySelector('input[role="switch"]');
const status = () => document.querySelector('.collaborate-notification-topics__status');
const flip = () => {
  input().checked = !input().checked;
  input().dispatchEvent(new Event('change', { bubbles: true }));
};
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

test('posts each change with the token of the topics, and says it was saved', async () => {
  const fetcher = vi.fn(async () => Response.json({ subscribed: true }));
  const topics = initTopics(render({ status: SENTENCES }), { fetcher });
  flip();
  await settle();
  expect(fetcher).toHaveBeenCalledWith('/t/comments/unsubscribe', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-Token': 'topics-token', 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(input().checked).toBe(false);
  expect(status().textContent).toBe('No more Comments');
  flip();
  await settle();
  expect(fetcher.mock.calls[1][0]).toBe('/t/comments/subscribe');
  expect(status().textContent).toBe('Told of Comments');
  // A checkbox that is no switch of a topic posts nothing.
  document.querySelector('input[name="other"]').dispatchEvent(new Event('change', { bubbles: true }));
  topics.stop();
  flip();
  await settle();
  expect(fetcher).toHaveBeenCalledTimes(2);
});

test('moves the switch back when the server refused, or could not be told', async () => {
  const answers = [
    () => new Response('', { status: 403 }),
    () => {
      throw new TypeError('offline');
    },
    () => {
      const response = Response.json({});
      Object.defineProperty(response, 'redirected', { value: true });
      return response;
    },
  ];
  for (const answer of answers) {
    initTopics(render({ status: SENTENCES, label: false }), { fetcher: vi.fn(async () => answer()) });
    flip();
    await settle();
    expect(input().checked).toBe(true);
    expect(status().textContent).toBe('comments unchanged');
  }
});

test('lets the latest change of a switch tell, and says nothing without sentences', async () => {
  const answers = [];
  const fetcher = vi.fn(
    () =>
      new Promise((resolve) => {
        answers.push(resolve);
      })
  );
  initTopics(render(), { fetcher });
  flip();
  flip();
  answers[1](Response.json({}));
  await settle();
  answers[0](new Response('', { status: 500 }));
  await settle();
  expect(input().checked).toBe(true);
  expect(status().textContent).toBe('');
});

test('works without a status nor a token', async () => {
  const root = render();
  status().remove();
  root.setAttribute('data-collaborate-notification-topics', '{}');
  const fetcher = vi.fn(async () => Response.json({}));
  initTopics(root, { fetcher });
  flip();
  await settle();
  expect(fetcher.mock.calls[0][1].headers['X-CSRF-Token']).toBe('');
});

describe('starting on its own', () => {
  async function load(readyState) {
    vi.resetModules();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue(readyState);
    const root = render();
    const spy = vi.spyOn(root, 'addEventListener');
    const module = await import('../../../assets/notifications/topics.js');
    return { module, started: () => spy.mock.calls.filter(([type]) => type === 'change').length };
  }

  test('starts every topics list of a parsed document once', async () => {
    const { module, started } = await load('complete');
    expect(started()).toBe(1);
    module.startTopics();
    expect(started()).toBe(1);
  });

  test('waits for the document to be parsed', async () => {
    const { started } = await load('loading');
    expect(started()).toBe(0);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(started()).toBe(1);
  });
});
