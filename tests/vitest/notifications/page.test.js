import { confirmed, initPage, MARGIN, parseRows } from '../../../assets/notifications/page.js';

const SETTINGS = { read: '/n/read', unread: '/n/unread', clear: '/n/clear', maxCount: 99 };

/** A row, as `_rows.html.twig` renders it. */
function row({ id, unread = true, url = null }) {
  const text = url
    ? `<a class="collaborate-notification__text" href="${url}">Notification ${id}</a>`
    : `<span class="collaborate-notification__text">Notification ${id}</span>`;
  return `<li class="collaborate-notification-row" data-id="${id}" data-unread="${unread}">
    <span class="collaborate-notification__unread"></span>
    ${text}
    <time class="collaborate-notification__date" datetime="2026-11-05T10:30:00+00:00">2026-11-05 10:30</time>
    <span class="collaborate-notification-row__actions">
      <button type="button" class="collaborate-notifications__button" data-action="read"${unread ? '' : ' hidden'}>Mark as read</button>
      <button type="button" class="collaborate-notifications__button" data-action="unread"${unread ? ' hidden' : ''}>Mark as unread</button>
      <button type="button" class="collaborate-notifications__button" data-action="clear" aria-label="Clear"><span aria-hidden="true">×</span></button>
    </span>
  </li>`;
}

const more = (next) =>
  `<li class="collaborate-notification-more" data-next="${next}"><button type="button" class="collaborate-notifications__button">Older notifications</button></li>`;

const rows = (entries, next = null) => entries.map(row).join('\n') + (next ? more(next) : '');

function render(entries = [], { next = null, src = '/n/all', confirm = null } = {}) {
  document.body.innerHTML = `<div class="collaborate-notifications-page">
    <h1 class="collaborate-notifications-page__title" tabindex="-1">Notifications</h1>
    <div class="collaborate-notifications-page__actions">
      <button type="button" data-action="readAll">Mark all as read</button>
      <button type="button" data-action="unreadAll">Mark all as unread</button>
      <button type="button" data-action="clearAll"${confirm ? ` data-confirm="${confirm}"` : ''}>Clear all</button>
    </div>
    <ul class="collaborate-notification-list" data-src="${src}">${rows(entries, next)}</ul>
    <p class="collaborate-notifications__empty"${entries.length ? ' hidden' : ''}>No notification</p>
  </div>`;
  const root = document.body.firstElementChild;
  root.setAttribute('data-collaborate-notifications-page', JSON.stringify(SETTINGS));
  return root;
}

function server(routes = {}) {
  return vi.fn(async (url, options = {}) => {
    const route = routes[`${options.method ?? 'GET'} ${url}`];
    if (route === undefined) throw new Error(`Unexpected ${options.method ?? 'GET'} ${url}`);
    return typeof route === 'function' ? route(url, options) : route.clone();
  });
}

const html = (body) => new Response(body, { headers: { 'Content-Type': 'text/html' } });
const counts = (unread, total = unread) => Response.json({ unread, total });

/** An answer the test gives when it wants. */
function later() {
  let resolve;
  const promise = new Promise((done) => {
    resolve = done;
  });
  return { route: () => promise, resolve };
}

class Observer {
  static last = null;
  constructor(callback, options) {
    this.callback = callback;
    this.options = options;
    this.observed = [];
    Observer.last = this;
  }
  observe(element) {
    this.observed.push(element);
  }
  disconnect() {
    this.observed = [];
  }
  intersect(isIntersecting = true) {
    this.callback(this.observed.map((target) => ({ target, isIntersecting })));
  }
}

const running = [];

function start(root, options = {}) {
  const fetcher = options.fetcher ?? server();
  const navigate = vi.fn();
  const page = initPage(root, { fetcher, navigate, Observer, ...options });
  if (page) running.push(page);
  return { page, fetcher, navigate };
}

const list = () => document.querySelector('.collaborate-notification-list');
const empty = () => document.querySelector('.collaborate-notifications__empty');
const heading = () => document.querySelector('.collaborate-notifications-page__title');
const rowOf = (id) => list().querySelector(`[data-id="${id}"]`);
const button = (id, action) => rowOf(id).querySelector(`[data-action="${action}"]`);
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function record(type) {
  const events = [];
  const listener = (event) => events.push(event.detail);
  document.addEventListener(type, listener);
  onTestFinished(() => document.removeEventListener(type, listener));
  return events;
}

afterEach(() => {
  for (const page of running.splice(0)) page.stop();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

test('reads the rows of a fragment, and nothing of another page', () => {
  expect(parseRows(null)).toBeNull();
  expect(parseRows('<form class="login"></form>')).toBeNull();
  expect(parseRows(`${rows([{ id: 1 }], '/next')}\n`).map((node) => node.className)).toEqual([
    'collaborate-notification-row',
    'collaborate-notification-more',
  ]);
  expect(parseRows('')).toEqual([]);
});

test('starts nothing without its list, and formats its dates', () => {
  document.body.innerHTML = '<div data-collaborate-notifications-page></div>';
  expect(initPage(document.body.firstElementChild)).toBeNull();
  const root = render([{ id: 1 }]);
  start(root);
  expect(rowOf(1).querySelector('time').textContent).not.toBe('2026-11-05 10:30');
  expect(empty().hidden).toBe(true);
});

test('says there is none once every row left, never while older ones are still to be asked for', () => {
  start(render([], { next: '/n/all?before=1' }));
  expect(empty().hidden).toBe(true);
  start(render([]));
  expect(empty().hidden).toBe(false);
});

describe('older notifications', () => {
  test('are asked for as their row comes into view, and put in its place', async () => {
    const fetcher = server({ 'GET /n/all?before=2': html(rows([{ id: 1 }], '/n/all?before=1')) });
    start(render([{ id: 2 }], { next: '/n/all?before=2' }), { fetcher });
    expect(Observer.last.options).toEqual({ rootMargin: MARGIN });
    Observer.last.intersect(false);
    expect(fetcher).not.toHaveBeenCalled();
    Observer.last.intersect();
    // One listing at a time.
    Observer.last.intersect();
    await vi.waitFor(() => expect(rowOf(1)).not.toBeNull());
    expect(fetcher).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls[0][1].headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
    expect(rowOf(1).querySelector('time').textContent).not.toBe('2026-11-05 10:30');
    expect(Observer.last.observed).toEqual([list().querySelector('.collaborate-notification-more')]);
  });

  test('are asked for by the button where the browser cannot watch, the keys going on from the first row', async () => {
    const fetcher = server({ 'GET /n/all?before=2': html(rows([{ id: 1, url: '/one' }])) });
    start(render([{ id: 2 }], { next: '/n/all?before=2' }), { fetcher, Observer: undefined });
    const older = list().querySelector('.collaborate-notification-more button');
    older.focus();
    older.click();
    await vi.waitFor(() => expect(rowOf(1)).not.toBeNull());
    expect(document.activeElement).toBe(rowOf(1).querySelector('a'));
    expect(list().querySelector('.collaborate-notification-more')).toBeNull();
  });

  test('stay to be asked for again when the server failed, and are dropped once their row is gone', async () => {
    const answer = later();
    const fetcher = server({ 'GET /n/all?before=2': () => new Response('', { status: 500 }) });
    const { page } = start(render([{ id: 2 }], { next: '/n/all?before=2' }), { fetcher });
    await page.loadMore();
    expect(list().querySelector('.collaborate-notification-more')).not.toBeNull();
    fetcher.mockImplementation(answer.route);
    const loading = page.loadMore();
    list().querySelector('.collaborate-notification-more').remove();
    answer.resolve(html(rows([{ id: 1 }])));
    await loading;
    expect(rowOf(1)).toBeNull();
    // Nothing to ask for.
    await page.loadMore();
    expect(fetcher).toHaveBeenCalledTimes(2);
  });
});

describe('one row', () => {
  test('is marked read then unread once the server knows, the keys staying on the button shown', async () => {
    const fetcher = server({ 'POST /n/read': counts(0, 1), 'POST /n/unread': counts(1, 1) });
    const changed = record('collaborate:notifications-changed');
    start(render([{ id: 1 }]), { fetcher });
    button(1, 'read').click();
    expect(button(1, 'read').getAttribute('aria-disabled')).toBe('true');
    // Pressed again while the server is told: nothing more.
    button(1, 'read').click();
    await vi.waitFor(() => expect(rowOf(1).dataset.unread).toBe('false'));
    expect(fetcher).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('id=1');
    expect(button(1, 'read').hidden).toBe(true);
    expect(button(1, 'read').hasAttribute('aria-disabled')).toBe(false);
    expect(document.activeElement).toBe(button(1, 'unread'));
    expect(changed).toEqual([{ unread: 0, total: 1, origin: 'page', heard: false }]);
    button(1, 'unread').click();
    await vi.waitFor(() => expect(rowOf(1).dataset.unread).toBe('true'));
    expect(document.activeElement).toBe(button(1, 'read'));
  });

  test('leaves the unread listing once read, and is cleared, the keys going to the row beside', async () => {
    const fetcher = server({ 'POST /n/read': counts(1), 'POST /n/clear': () => Response.json({ unread: 0 }) });
    const changed = record('collaborate:notifications-changed');
    start(render([{ id: 3 }, { id: 2 }, { id: 1 }], { src: '/n/all?unread=1' }), { fetcher });
    button(2, 'read').focus();
    button(2, 'read').click();
    await vi.waitFor(() => expect(rowOf(2)).toBeNull());
    expect(document.activeElement).toBe(button(1, 'read'));
    button(1, 'clear').focus();
    button(1, 'clear').click();
    await vi.waitFor(() => expect(rowOf(1)).toBeNull());
    expect(document.activeElement).toBe(button(3, 'clear'));
    expect(changed.at(-1)).toEqual({ unread: 0, total: null, origin: 'page', heard: false });
    // Cleared without the focus on it.
    heading().focus();
    button(3, 'clear').click();
    await vi.waitFor(() => expect(rowOf(3)).toBeNull());
    expect(document.activeElement).toBe(heading());
    expect(empty().hidden).toBe(false);
  });

  test('gives the focus to the title when the last row left, and asks for the older ones', async () => {
    const fetcher = server({ 'POST /n/clear': counts(0), 'GET /n/all?before=1': html(rows([{ id: 0 }])) });
    start(render([{ id: 1 }], { next: '/n/all?before=1' }), { fetcher });
    button(1, 'clear').focus();
    button(1, 'clear').click();
    await vi.waitFor(() => expect(rowOf(0)).not.toBeNull());
    expect(document.activeElement).toBe(heading());
  });

  test('stays as it is when the server could not be told, or once the page stopped', async () => {
    const answer = later();
    const fetcher = server({ 'POST /n/read': () => new Response('', { status: 500 }), 'POST /n/clear': answer.route });
    const { page } = start(render([{ id: 1 }]), { fetcher });
    button(1, 'read').click();
    await settle();
    expect(rowOf(1).dataset.unread).toBe('true');
    button(1, 'clear').click();
    page.stop();
    answer.resolve(counts(0));
    await settle();
    expect(rowOf(1)).not.toBeNull();
  });

  test('is read before its link is followed, and followed all the same when the server was not told', async () => {
    const fetcher = server({ 'POST /n/read': () => new Response('', { status: 500 }) });
    const { navigate } = start(
      render([
        { id: 1, url: '/one' },
        { id: 2, url: '/two', unread: false },
      ]),
      { fetcher }
    );
    const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    rowOf(1).querySelector('a').dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/one'));
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('id=1');
    // A read one, or one opened elsewhere, is the browser's.
    let prevented = null;
    document.body.addEventListener('click', (event) => {
      prevented = event.defaultPrevented;
      event.preventDefault();
    });
    rowOf(2)
      .querySelector('a')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    expect(prevented).toBe(false);
    rowOf(1)
      .querySelector('a')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, metaKey: true }));
    expect(prevented).toBe(false);
    // Elsewhere on the page.
    heading().click();
    expect(fetcher).toHaveBeenCalledOnce();
  });

  test('follows nothing once the page stopped, and follows with the location of the page by default', async () => {
    const answer = later();
    const { page, navigate } = start(render([{ id: 1, url: '/one' }]), {
      fetcher: server({ 'POST /n/read': answer.route }),
    });
    rowOf(1)
      .querySelector('a')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    page.stop();
    answer.resolve(counts(0));
    await settle();
    expect(navigate).not.toHaveBeenCalled();
    const root = render([{ id: 1, url: '#one' }]);
    running.push(initPage(root, { fetcher: server({ 'POST /n/read': counts(0) }) }));
    rowOf(1)
      .querySelector('a')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await vi.waitFor(() => expect(location.hash).toBe('#one'));
    location.hash = '';
  });
});

describe('all of them', () => {
  test('are marked read, then the listing is asked for again with the focus kept on its row', async () => {
    const fetcher = server({
      'POST /n/read': counts(0, 2),
      'GET /n/all': html(
        rows([
          { id: 2, unread: false },
          { id: 1, unread: false },
        ])
      ),
    });
    const changed = record('collaborate:notifications-changed');
    start(render([{ id: 2 }, { id: 1 }]), { fetcher });
    button(1, 'clear').focus();
    document.querySelector('[data-action="readAll"]').click();
    await vi.waitFor(() => expect(rowOf(1).dataset.unread).toBe('false'));
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('');
    expect(document.activeElement).toBe(button(1, 'clear'));
    expect(changed).toEqual([{ unread: 0, total: 2, origin: 'page', heard: false }]);
  });

  test('are marked unread, the keys going to the title when their row left the listing', async () => {
    const fetcher = server({ 'POST /n/unread': counts(1), 'GET /n/all': html(rows([{ id: 9 }])) });
    start(render([{ id: 1, unread: false }]), { fetcher });
    button(1, 'unread').focus();
    document.querySelector('[data-action="unreadAll"]').click();
    await vi.waitFor(() => expect(rowOf(9)).not.toBeNull());
    expect(document.activeElement).toBe(heading());
  });

  test('are cleared once confirmed, dropping a listing still to be answered', async () => {
    const listing = later();
    const fetcher = server({ 'POST /n/clear': counts(0, 0), 'GET /n/all': listing.route });
    const asked = [];
    const answer = (event) => {
      asked.push(event.detail.message);
      event.detail.answer = Promise.resolve(asked.length > 1);
    };
    document.addEventListener('collaborate:notifications-confirm', answer);
    onTestFinished(() => document.removeEventListener('collaborate:notifications-confirm', answer));
    const { page } = start(render([{ id: 1 }], { confirm: 'Sure?' }), { fetcher });
    const clearAll = document.querySelector('[data-action="clearAll"]');
    clearAll.click();
    await settle();
    expect(rowOf(1)).not.toBeNull();
    const reloading = page.reload();
    clearAll.click();
    await vi.waitFor(() => expect(empty().hidden).toBe(false));
    expect(asked).toEqual(['Sure?', 'Sure?']);
    expect(list().children).toHaveLength(0);
    listing.resolve(html(rows([{ id: 1 }])));
    await reloading;
    expect(list().children).toHaveLength(0);
  });

  test('change nothing when the server could not be told', async () => {
    const fetcher = server({ 'POST /n/read': () => new Response('', { status: 500 }) });
    start(render([{ id: 1 }]), { fetcher });
    document.querySelector('[data-action="readAll"]').click();
    await settle();
    expect(fetcher).toHaveBeenCalledOnce();
    expect(rowOf(1).dataset.unread).toBe('true');
  });
});

describe('the confirmation', () => {
  test('is the browser dialog unless the host answers, and none for a button that asks nothing', async () => {
    const button = document.createElement('button');
    expect(await confirmed(button)).toBe(true);
    button.dataset.confirm = 'Sure?';
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(await confirmed(button)).toBe(false);
    expect(confirm).toHaveBeenCalledWith('Sure?');
    const answer = (event) => {
      event.detail.answer = 1;
    };
    document.addEventListener('collaborate:notifications-confirm', answer);
    expect(await confirmed(button)).toBe(true);
    document.removeEventListener('collaborate:notifications-confirm', answer);
  });
});

describe('what the bell heard', () => {
  test('asks for the listing again, unless the page is waiting for the server', async () => {
    const pending = later();
    const fetcher = server({ 'GET /n/all': html(rows([{ id: 2 }, { id: 1 }])), 'POST /n/read': pending.route });
    start(render([{ id: 1 }]), { fetcher });
    const tell = (detail) => document.dispatchEvent(new CustomEvent('collaborate:notifications-changed', { detail }));
    tell({ origin: 'page', heard: true });
    tell({ origin: 'bell', heard: false });
    tell(null);
    expect(fetcher).not.toHaveBeenCalled();
    button(1, 'read').click();
    tell({ origin: 'bell', heard: true });
    expect(fetcher).toHaveBeenCalledOnce();
    pending.resolve(counts(0));
    await vi.waitFor(() => expect(button(1, 'read').hidden).toBe(true));
    // The focus elsewhere stays there.
    heading().focus();
    tell({ origin: 'bell', heard: true });
    await vi.waitFor(() => expect(rowOf(2)).not.toBeNull());
    expect(document.activeElement).toBe(heading());
  });

  test('leaves the listing as it is when the server answers anything else, or after the page stopped', async () => {
    const fetcher = server({ 'GET /n/all': html('<form class="login"></form>') });
    const { page } = start(render([{ id: 1 }], { src: '' }), { fetcher: server({ 'GET ': html('<p>') }) });
    await page.reload();
    expect(rowOf(1)).not.toBeNull();
    const other = start(render([{ id: 1 }]), { fetcher });
    await other.page.reload();
    expect(rowOf(1)).not.toBeNull();
    const answer = later();
    fetcher.mockImplementation(answer.route);
    const reloading = other.page.reload();
    other.page.stop();
    answer.resolve(html(rows([{ id: 5 }])));
    await reloading;
    expect(rowOf(5)).toBeNull();
  });
});

test('works without an empty note or a title', async () => {
  const root = render([{ id: 1 }]);
  empty().remove();
  heading().remove();
  const fetcher = server({ 'POST /n/clear': counts(0) });
  start(root, { fetcher });
  button(1, 'clear').focus();
  button(1, 'clear').click();
  await vi.waitFor(() => expect(rowOf(1)).toBeNull());
});

describe('starting on its own', () => {
  async function load(readyState) {
    vi.resetModules();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue(readyState);
    render([{ id: 1 }]);
    const spy = vi.spyOn(document, 'addEventListener');
    const module = await import('../../../assets/notifications/page.js');
    const pages = () => spy.mock.calls.filter(([type]) => type === 'collaborate:notifications-changed').length;
    return { module, pages };
  }

  test('starts every page of a parsed document once', async () => {
    const { module, pages } = await load('complete');
    expect(pages()).toBe(1);
    module.startPages();
    expect(pages()).toBe(1);
  });

  test('waits for the document to be parsed', async () => {
    const { pages } = await load('loading');
    expect(pages()).toBe(0);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(pages()).toBe(1);
  });
});
