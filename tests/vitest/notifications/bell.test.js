import {
  decodeKey,
  fetchFragment,
  formatDates,
  initBell,
  leaves,
  MAX_COUNT,
  parseFragment,
  postChange,
  pushEncoding,
  pushSupported,
  REFRESH_DELAY,
  sameKey,
  settingsOf,
} from '../../../assets/notifications/bell.js';

const SETTINGS = {
  src: '/notifications',
  page: '/notifications/all',
  topics: '/notifications/topics',
  read: '/notifications/read',
  unread: '/notifications/unread',
  clear: '/notifications/clear',
  topicsToken: 'topics-token',
  maxCount: 99,
  push: null,
};
const PUSH = {
  key: 'AQID',
  worker: '/notifications/push/worker.js',
  subscribe: '/notifications/push/subscribe',
  unsubscribe: '/notifications/push/unsubscribe',
  token: 'push-token',
};

/** An entry of the menu, as `_list.html.twig` renders it. */
function entry({ id, unread = true, url = null, text = `Notification ${id}` }) {
  const tag = url ? 'a' : 'button';
  const target = url ? ` href="${url}"` : ' type="button"';
  return `<${tag} class="collaborate-notification" role="menuitem" data-id="${id}" data-unread="${unread}"${target}>
    <span class="collaborate-notification__unread"><span class="collaborate-visually-hidden">Not read yet: </span></span>
    <span class="collaborate-notification__text"> ${text} </span>
    <time class="collaborate-notification__date" datetime="2026-11-05T10:30:00+00:00">2026-11-05 10:30</time>
  </${tag}>`;
}

/** The content of the menu, as `_list.html.twig` renders it. */
function list(entries = [], { unread = entries.filter((one) => one.unread !== false).length, total = null } = {}) {
  const totalAttribute = total === null ? '' : ` data-total-count="${total}"`;
  return `<div class="collaborate-notifications__header" role="presentation" data-unread-count="${unread}"${totalAttribute}>
      <span>Notifications</span>
      ${unread > 0 ? '<button type="button" class="collaborate-notifications__read-all" role="menuitem">Mark all as read</button>' : ''}
    </div>
    ${entries.map(entry).join('') || '<p class="collaborate-notifications__empty" role="presentation">No notification</p>'}
    <hr class="collaborate-notifications__divider" role="presentation">
    <button type="button" class="collaborate-notifications__push" role="menuitemcheckbox" aria-checked="false" hidden>Notify me on this device</button>
    <p class="collaborate-notifications__push-denied" role="presentation" hidden>Blocked</p>
    <a class="collaborate-notifications__all" role="menuitem" href="/notifications/all">See all notifications</a>`;
}

/** The bell, as `bell.html.twig` renders it. */
function render(entries = [], { settings = SETTINGS, unread, label, unreadLabel, badge = true, content } = {}) {
  const count = unread ?? entries.filter((one) => one.unread !== false).length;
  const shown = count > settings.maxCount ? `${settings.maxCount}+` : String(count);
  document.body.innerHTML = `<div class="collaborate-notifications">
    <button type="button" class="collaborate-notifications__toggle" id="collaborate-notifications-toggle"
      popovertarget="collaborate-notifications-menu" aria-haspopup="menu" aria-expanded="false" data-label="Notifications">
      ${badge ? `<span class="collaborate-notifications__count"${count === 0 ? ' hidden' : ''}>${shown}</span>` : ''}
    </button>
    <div class="collaborate-notifications__menu" id="collaborate-notifications-menu" popover="auto" role="menu">${content ?? list(entries, { unread: count })}</div>
  </div>`;
  const root = document.querySelector('.collaborate-notifications');
  root.setAttribute('data-collaborate-notifications', JSON.stringify(settings));
  const toggle = root.querySelector('.collaborate-notifications__toggle');
  toggle.setAttribute('aria-label', label ?? (count > 0 ? `Notifications, ${shown} unread` : 'Notifications'));
  if (unreadLabel) toggle.dataset.unreadLabel = unreadLabel;
  return root;
}

function fakeHub() {
  const hub = {
    members: [],
    leaves: 0,
    join: vi.fn((member) => {
      hub.members.push(member);
      return () => {
        hub.leaves++;
        hub.members = hub.members.filter((joined) => joined !== member);
      };
    }),
  };
  return hub;
}

/** A server answering the bell's requests: `routes` maps `METHOD url` to a response or a function making one. */
function server(routes = {}) {
  return vi.fn(async (url, options = {}) => {
    const route = routes[`${options.method ?? 'GET'} ${url}`];
    if (route === undefined) throw new Error(`Unexpected ${options.method ?? 'GET'} ${url}`);
    return typeof route === 'function' ? route(url, options) : route.clone();
  });
}

const html = (body) => new Response(body, { headers: { 'Content-Type': 'text/html' } });
const counts = (unread, total = unread) => Response.json({ unread, total });
const redirected = (response) => {
  Object.defineProperty(response, 'redirected', { value: true });
  return response;
};

const running = [];

function start(root, options = {}) {
  const hub = options.hub === undefined ? fakeHub() : options.hub;
  const fetcher = options.fetcher ?? server();
  const navigate = vi.fn();
  const bell = initBell(root, { hub, fetcher, navigate, announced: new Set(), serviceWorker: null, ...options });
  if (bell) running.push(bell);
  return { bell, hub, fetcher, navigate };
}

const menuOf = (root) => root.querySelector('.collaborate-notifications__menu');
const toggleOf = (root) => root.querySelector('.collaborate-notifications__toggle');
const badgeOf = (root) => root.querySelector('.collaborate-notifications__count');
const toggled = (root, newState) =>
  menuOf(root).dispatchEvent(Object.assign(new Event('toggle'), { newState, oldState: newState }));

function record(type) {
  const events = [];
  const listener = (event) => events.push(event.detail);
  document.addEventListener(type, listener);
  onTestFinished(() => document.removeEventListener(type, listener));
  return events;
}

afterEach(() => {
  for (const bell of running.splice(0)) bell.stop();
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  delete navigator.serviceWorker;
  document.body.innerHTML = '';
  document.documentElement.lang = '';
});

describe('helpers', () => {
  test('reads the settings of an attribute, malformed ones as none', () => {
    const element = document.createElement('div');
    expect(settingsOf(null, 'data-x')).toEqual({});
    expect(settingsOf(element, 'data-x')).toEqual({});
    element.setAttribute('data-x', '{"a":1}');
    expect(settingsOf(element, 'data-x')).toEqual({ a: 1 });
    for (const value of ['[1]', 'null', '{']) {
      element.setAttribute('data-x', value);
      expect(settingsOf(element, 'data-x')).toEqual({});
    }
  });

  test('writes the dates in the language of the page, and leaves a date it cannot read', () => {
    const scope = document.createElement('div');
    scope.innerHTML =
      '<time class="collaborate-notification__date" datetime="2026-11-05T10:30:00Z">raw</time>' +
      '<time class="collaborate-notification__date" datetime="soon">raw</time>';
    const [good, bad] = scope.querySelectorAll('time');
    const expected = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' }).format(
      new Date('2026-11-05T10:30:00Z')
    );
    document.documentElement.lang = 'fr';
    formatDates(scope);
    expect(good.textContent).toBe(expected);
    expect(bad.textContent).toBe('raw');
    formatDates(scope, 'not a language!');
    expect(good.textContent).toBe(
      new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeStyle: 'short' }).format(
        new Date('2026-11-05T10:30:00Z')
      )
    );
  });

  test('parses a fragment without running it', () => {
    const nodes = parseFragment('<p>a</p><script>window.ran = true</script>');
    expect(nodes.map((node) => node.nodeName)).toEqual(['P', 'SCRIPT']);
    expect(window.ran).toBeUndefined();
  });

  test('fetches a fragment, and nothing of an error, a redirection or a network failure', async () => {
    const fetcher = vi.fn(async () => html('<p>ok</p>'));
    expect(await fetchFragment('/f', fetcher)).toBe('<p>ok</p>');
    expect(fetcher).toHaveBeenCalledWith('/f', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(await fetchFragment('/f', async () => new Response('', { status: 500 }))).toBeNull();
    expect(await fetchFragment('/f', async () => redirected(html('<p>sign in</p>')))).toBeNull();
    expect(
      await fetchFragment('/f', async () => {
        throw new TypeError('offline');
      })
    ).toBeNull();
  });

  test('posts a change, one notification or all of them, and answers the counts', async () => {
    const fetcher = vi.fn(async () => counts(2, 5));
    expect(await postChange('/read', '7', fetcher)).toEqual({ unread: 2, total: 5 });
    expect(fetcher.mock.calls[0][1]).toMatchObject({ method: 'POST', credentials: 'same-origin' });
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('id=7');
    await postChange('/read', null, fetcher);
    expect(fetcher.mock.calls[1][1].body.toString()).toBe('');
    expect(await postChange('/read', null, async () => new Response('', { status: 403 }))).toBeNull();
    expect(await postChange('/read', null, async () => redirected(counts(1)))).toBeNull();
    expect(await postChange('/read', null, async () => Response.json({ unread: 'x' }))).toBeNull();
    expect(await postChange('/read', null, async () => Response.json(null))).toBeNull();
    expect(await postChange('/read', null, async () => html('<p>'))).toBeNull();
  });

  test('tells a click that leaves the page from one that opens another', () => {
    expect(leaves({ button: 0 })).toBe(true);
    expect(leaves({ button: 1 })).toBe(false);
    for (const key of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey'])
      expect(leaves({ button: 0, [key]: true })).toBe(false);
  });

  test('decodes and compares the keys of web push, and picks the encoding', () => {
    expect([...decodeKey('AQID')]).toEqual([1, 2, 3]);
    expect([...decodeKey('-_8')]).toEqual([251, 255]);
    expect(sameKey(null, decodeKey('AQID'))).toBe(false);
    expect(sameKey(new Uint8Array([1, 2, 3]).buffer, decodeKey('AQID'))).toBe(true);
    expect(sameKey(new Uint8Array([1, 2]).buffer, decodeKey('AQID'))).toBe(false);
    expect(sameKey(new Uint8Array([1, 2, 4]).buffer, decodeKey('AQID'))).toBe(false);
    expect(pushEncoding(['aesgcm', 'aes128gcm'])).toBe('aes128gcm');
    expect(pushEncoding(['aesgcm'])).toBe('aesgcm');
    expect(pushEncoding([])).toBe('aes128gcm');
    expect(pushEncoding(undefined)).toBe('aes128gcm');
    expect(pushEncoding()).toBe('aes128gcm');
  });

  test('offers web push where the server sends it and the browser receives it', () => {
    expect(pushSupported({ push: null })).toBe(false);
    expect(pushSupported({ push: PUSH })).toBe(false);
    navigator.serviceWorker = {};
    expect(pushSupported({ push: PUSH })).toBe(false);
    vi.stubGlobal('PushManager', class {});
    expect(pushSupported({ push: PUSH })).toBe(false);
    vi.stubGlobal('Notification', class {});
    expect(pushSupported({ push: PUSH })).toBe(true);
  });
});

describe('the count', () => {
  test('starts nothing without its toggle, its menu or where to ask', () => {
    render();
    const root = document.querySelector('.collaborate-notifications');
    root.setAttribute('data-collaborate-notifications', '{}');
    expect(initBell(root, { hub: null })).toBeNull();
    document.body.innerHTML = '<div class="collaborate-notifications"></div>';
    expect(initBell(document.body.firstElementChild, { hub: null })).toBeNull();
  });

  test('caps the count, and names the bell with the sentence its server-rendered name shows', async () => {
    vi.useFakeTimers();
    const root = render([{ id: 1 }, { id: 2 }, { id: 3 }], { label: 'Notifications, 3 non lues' });
    const fetcher = server({ 'GET /notifications': html(list([{ id: 4 }], { unread: 120 })) });
    const { hub } = start(root, { fetcher });
    expect(badgeOf(root).textContent).toBe('3');
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications, 3 non lues');
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(badgeOf(root).textContent).toBe('99+');
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications, 99+ non lues');
  });

  test('names the bell with the sentence the template gives, or in English', async () => {
    vi.useFakeTimers();
    const fetcher = server({ 'GET /notifications': html(list([{ id: 4 }])) });
    let root = render([], { unreadLabel: '%count% à lire' });
    let { hub } = start(root, { fetcher });
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications, 1 à lire');
    expect(badgeOf(root).hidden).toBe(false);

    root = render([]);
    ({ hub } = start(root, { fetcher }));
    expect(badgeOf(root).hidden).toBe(true);
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications');
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications, 1 unread');

    // A name that is not the label and the count.
    root = render([{ id: 1 }], { label: 'Bell' });
    ({ hub } = start(root, { fetcher }));
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(toggleOf(root).getAttribute('aria-label')).toBe('Notifications, 1 unread');
  });

  test('counts without a badge, a header or a cap of its own', () => {
    const root = render([{ id: 1 }, { id: 2 }], {
      badge: false,
      settings: { ...SETTINGS, maxCount: undefined },
      content: entry({ id: 1 }) + entry({ id: 2 }),
    });
    delete toggleOf(root).dataset.label;
    toggleOf(root).removeAttribute('aria-label');
    start(root);
    expect(MAX_COUNT).toBe(99);
    expect(toggleOf(root).getAttribute('aria-label')).toBe(', 2 unread');
  });

  test('counts what the list shows when its header says less, or nothing it can read', () => {
    const root = render([{ id: 1 }, { id: 2 }], { unread: 0 });
    menuOf(root).querySelector('.collaborate-notifications__header').dataset.unreadCount = 'many';
    start(root);
    expect(badgeOf(root).textContent).toBe('2');
  });
});

describe('the list', () => {
  test('asks for its list once for the notices arriving together, and tells the host of what came', async () => {
    vi.useFakeTimers();
    document.documentElement.lang = 'en';
    const root = render([{ id: 2 }, { id: 1, unread: false }]);
    const fresh = [{ id: 5, url: '/five' }, { id: 4 }, { id: 3, unread: false }, { id: 2 }, { id: 1, unread: false }];
    const fetcher = server({ 'GET /notifications': html(list(fresh, { total: 9 })) });
    const told = record('collaborate:notification');
    const changed = record('collaborate:notifications-changed');
    const { hub, bell } = start(root, { fetcher });
    expect(hub.members[0].channel).toBe('notifications');
    hub.members[0].receive();
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls[0][1].headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
    expect(menuOf(root).querySelectorAll('.collaborate-notification')).toHaveLength(5);
    expect(menuOf(root).querySelector('time').textContent).not.toBe('2026-11-05 10:30');
    expect(told).toEqual([
      { id: '4', text: 'Notification 4', url: null },
      { id: '5', text: 'Notification 5', url: '/five' },
    ]);
    expect(changed).toEqual([{ unread: 3, total: 9, origin: 'bell', heard: true }]);
    // The same list again tells nothing new; the bell's own refresh is no news from elsewhere.
    await bell.refresh();
    expect(told).toHaveLength(2);
    expect(changed.at(-1)).toEqual({ unread: 3, total: 9, origin: 'bell', heard: false });
  });

  test('tells the host of a notification once, whichever bell or worker saw it', async () => {
    vi.useFakeTimers();
    const root = render([]);
    const fetcher = server({ 'GET /notifications': html(list([{ id: 1, text: '' }])) });
    const told = record('collaborate:notification');
    const announced = new Set(['1']);
    const { hub } = start(root, { fetcher, announced });
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(told).toEqual([]);
    // An entry without its text.
    menuOf(root).replaceChildren();
    announced.clear();
    const { bell } = start(render([]), {
      fetcher: server({
        'GET /notifications': html(
          `${list([])}<button class="collaborate-notification" data-id="2" data-unread="true"></button>`
        ),
      }),
      announced,
    });
    await bell.refresh();
    expect(told).toEqual([{ id: '2', text: '', url: null }]);
  });

  test('leaves its list as it is when the server answers anything else', async () => {
    const root = render([{ id: 1 }]);
    start(root).bell.stop();
    const before = menuOf(root).innerHTML;
    const answers = [
      () => new Response('', { status: 500 }),
      () => redirected(html(list([{ id: 2 }]))),
      () => html('<form class="login"></form>'),
      () => {
        throw new TypeError('offline');
      },
    ];
    for (const answer of answers) {
      const { bell } = start(root, { fetcher: server({ 'GET /notifications': answer }) });
      await bell.refresh();
      expect(menuOf(root).innerHTML).toBe(before);
      bell.stop();
    }
  });

  test('never moves its list under the pointer: an open menu is refreshed once closed', async () => {
    vi.useFakeTimers();
    const root = render([{ id: 1 }]);
    let release;
    const fetcher = server({
      'GET /notifications': () =>
        new Promise((resolve) => {
          release = () => resolve(html(list([{ id: 2 }, { id: 1 }])));
        }),
    });
    const { hub } = start(root, { fetcher });
    toggled(root, 'open');
    expect(toggleOf(root).getAttribute('aria-expanded')).toBe('true');
    expect(menuOf(root).style.top).toBe('4px');
    expect(menuOf(root).style.left).toBe('8px');
    expect(document.activeElement).toBe(menuOf(root).querySelector('.collaborate-notifications__read-all'));
    hub.members[0].receive();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).not.toHaveBeenCalled();
    toggled(root, 'closed');
    expect(toggleOf(root).getAttribute('aria-expanded')).toBe('false');
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
    // Opened while the list was asked for: asked again once closed.
    toggled(root, 'open');
    release();
    await vi.advanceTimersByTimeAsync(0);
    expect(menuOf(root).querySelectorAll('.collaborate-notification')).toHaveLength(1);
    toggled(root, 'closed');
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledTimes(2);
    release();
    await vi.advanceTimersByTimeAsync(0);
    expect(menuOf(root).querySelectorAll('.collaborate-notification')).toHaveLength(2);
    // Closed with nothing owed asks nothing.
    toggled(root, 'closed');
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  test('goes from one item to the next with the keys', () => {
    const root = render([{ id: 1 }, { id: 2 }]);
    start(root);
    const menu = menuOf(root);
    const items = [...menu.querySelectorAll('[role^="menuitem"]:not([hidden])')];
    const press = (key) => {
      const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
      menu.dispatchEvent(event);
      return event;
    };
    expect(press('ArrowDown').defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(items[0]);
    press('ArrowDown');
    expect(document.activeElement).toBe(items[1]);
    press('ArrowUp');
    expect(document.activeElement).toBe(items[0]);
    press('ArrowUp');
    expect(document.activeElement).toBe(items.at(-1));
    press('Home');
    expect(document.activeElement).toBe(items[0]);
    press('End');
    expect(document.activeElement).toBe(items.at(-1));
    expect(press('a').defaultPrevented).toBe(false);
    menu.replaceChildren();
    expect(press('ArrowDown').defaultPrevented).toBe(false);
    toggled(root, 'open');
  });
});

describe('reading', () => {
  test('marks one read once the server knows, and tells the host the counts', async () => {
    const root = render([{ id: 2 }, { id: 1 }]);
    const fetcher = server({ 'POST /notifications/read': counts(1, 2) });
    const changed = record('collaborate:notifications-changed');
    start(root, { fetcher });
    menuOf(root).querySelector('[data-id="2"] .collaborate-notification__text').click();
    await vi.waitFor(() => expect(changed).toHaveLength(1));
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('id=2');
    expect(menuOf(root).querySelector('[data-id="2"]').dataset.unread).toBe('false');
    expect(changed[0]).toEqual({ unread: 1, total: 2, origin: 'bell', heard: false });
    expect(badgeOf(root).textContent).toBe('1');
    // Read already, a notification leading nowhere asks nothing; neither does the header.
    menuOf(root).querySelector('[data-id="2"]').click();
    menuOf(root).querySelector('.collaborate-notifications__header span').click();
    expect(fetcher).toHaveBeenCalledOnce();
  });

  test('marks all of them read, and loses its button once none is left', async () => {
    const root = render([{ id: 2 }, { id: 1 }]);
    const fetcher = server({ 'POST /notifications/read': counts(0, 2) });
    start(root, { fetcher });
    menuOf(root).querySelector('.collaborate-notifications__read-all').click();
    await vi.waitFor(() => expect(badgeOf(root).hidden).toBe(true));
    expect(fetcher.mock.calls[0][1].body.toString()).toBe('');
    expect(menuOf(root).querySelector('[data-unread="true"]')).toBeNull();
    expect(menuOf(root).querySelector('.collaborate-notifications__read-all')).toBeNull();
    expect(menuOf(root).querySelector('.collaborate-notifications__header').dataset).toMatchObject({
      unreadCount: '0',
      totalCount: '2',
    });
  });

  test('leaves them as they are when the server could not be told', async () => {
    const root = render([{ id: 1 }]);
    const fetcher = server({ 'POST /notifications/read': new Response('', { status: 500 }) });
    start(root, { fetcher });
    menuOf(root).querySelector('[data-id="1"]').click();
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    await Promise.resolve();
    expect(menuOf(root).querySelector('[data-id="1"]').dataset.unread).toBe('true');
  });

  test('follows a link once the server knows it read, and all the same when it could not be told', async () => {
    const root = render([
      { id: 2, url: '/two' },
      { id: 1, url: '/one' },
    ]);
    const fetcher = server({
      'POST /notifications/read': (_url, options) =>
        options.body.get('id') === '2' ? counts(1) : new Response('', { status: 500 }),
    });
    const { navigate } = start(root, { fetcher });
    const two = menuOf(root).querySelector('[data-id="2"]');
    const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    two.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(navigate).not.toHaveBeenCalled();
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/two'));
    expect(two.dataset.unread).toBe('false');
    menuOf(root)
      .querySelector('[data-id="1"]')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/one'));
  });

  test('lets a link open elsewhere, and reads it all the same', async () => {
    const root = render([{ id: 1, url: '/one' }]);
    const fetcher = server({ 'POST /notifications/read': counts(0) });
    const { navigate } = start(root, { fetcher });
    let prevented = null;
    // jsdom would follow the link: the test stops it once the bell had its say.
    root.addEventListener('click', (event) => {
      prevented = event.defaultPrevented;
      event.preventDefault();
    });
    const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
    menuOf(root).querySelector('[data-id="1"]').dispatchEvent(click);
    expect(prevented).toBe(false);
    await vi.waitFor(() => expect(badgeOf(root).hidden).toBe(true));
    expect(navigate).not.toHaveBeenCalled();
  });

  test('follows nothing once stopped', async () => {
    const root = render([{ id: 1, url: '/one' }]);
    let answer;
    const fetcher = server({
      'POST /notifications/read': () =>
        new Promise((resolve) => {
          answer = resolve;
        }),
    });
    const { navigate, bell } = start(root, { fetcher });
    menuOf(root)
      .querySelector('[data-id="1"]')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    bell.stop();
    answer(counts(0));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(navigate).not.toHaveBeenCalled();
    expect(menuOf(root).querySelector('[data-id="1"]').dataset.unread).toBe('true');
  });

  test('shows the count the page was answered, and asks for its list again', async () => {
    vi.useFakeTimers();
    const root = render([{ id: 1 }]);
    const fetcher = server({ 'GET /notifications': html(list([{ id: 1, unread: false }])) });
    const changed = record('collaborate:notifications-changed');
    start(root, { fetcher });
    document.dispatchEvent(
      new CustomEvent('collaborate:notifications-changed', { detail: { unread: 0, total: 1, origin: 'page' } })
    );
    expect(badgeOf(root).hidden).toBe(true);
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(changed.at(-1)).toEqual({ unread: 0, total: null, origin: 'bell', heard: false });
    // What the bell itself said, or a page that says no count, changes no count.
    document.dispatchEvent(new CustomEvent('collaborate:notifications-changed', { detail: { origin: 'page' } }));
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledTimes(2);
    document.dispatchEvent(new CustomEvent('collaborate:notifications-changed'));
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });
});

describe('odd cases', () => {
  test('tells nothing of a notification without a numeric id, and takes a count without a total', async () => {
    const root = render([{ id: 'x' }]);
    const told = record('collaborate:notification');
    const { bell } = start(root, { fetcher: server({ 'GET /notifications': html(list([{ id: 'y' }])) }) });
    await bell.refresh();
    expect(told).toEqual([]);
    document.dispatchEvent(
      new CustomEvent('collaborate:notifications-changed', { detail: { unread: 0, origin: 'page' } })
    );
    expect(badgeOf(root).hidden).toBe(true);
    expect(menuOf(root).querySelector('.collaborate-notifications__header').dataset.totalCount).toBeUndefined();
  });

  test('follows a link with the location of the page by default', async () => {
    const root = render([{ id: 1, url: '#one' }]);
    running.push(initBell(root, { hub: null, fetcher: server({ 'POST /notifications/read': counts(0) }) }));
    menuOf(root)
      .querySelector('[data-id="1"]')
      .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await vi.waitFor(() => expect(location.hash).toBe('#one'));
    location.hash = '';
  });
});

describe('the hub and the service worker', () => {
  test('asks again once a lost stream is back, not when it first opens nor when it never opened', async () => {
    vi.useFakeTimers();
    const root = render([]);
    const fetcher = server({ 'GET /notifications': html(list([])) });
    const { hub } = start(root, { fetcher });
    const [member] = hub.members;
    member.closed();
    member.opened();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).not.toHaveBeenCalled();
    member.opened();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).not.toHaveBeenCalled();
    member.closed();
    member.opened();
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  test('stays joined while hidden, leaves for the back/forward cache and comes back from it', async () => {
    vi.useFakeTimers();
    const root = render([]);
    const fetcher = server({ 'GET /notifications': html(list([])) });
    const { hub } = start(root, { fetcher });
    document.dispatchEvent(new Event('visibilitychange'));
    expect(hub.leaves).toBe(0);
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    expect(hub.join).toHaveBeenCalledOnce();
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
    expect(hub.leaves).toBe(1);
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false }));
    expect(hub.join).toHaveBeenCalledOnce();
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    expect(hub.join).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
    // Leaving for good stops it.
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    expect(hub.members).toEqual([]);
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    expect(hub.join).toHaveBeenCalledTimes(2);
  });

  test('stops following a refused hub, and works without one', async () => {
    const root = render([]);
    const { hub, bell } = start(root);
    hub.members[0].denied();
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
    expect(hub.leaves).toBe(0);
    bell.stop();
    const alone = start(render([{ id: 1 }]), { hub: null });
    expect(alone.bell).not.toBeNull();
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    alone.bell.stop();
  });

  test('hears the service worker: asks for its list, and tells the host of the notification once', async () => {
    vi.useFakeTimers();
    const root = render([]);
    const fetcher = server({ 'GET /notifications': html(list([{ id: 7, text: 'Seven' }])) });
    const serviceWorker = new EventTarget();
    const told = record('collaborate:notification');
    start(root, { fetcher, serviceWorker });
    const message = (data) => serviceWorker.dispatchEvent(Object.assign(new Event('message'), { data }));
    message({ type: 'other' });
    message(null);
    // Counted while another page or the system says it: the list again, without a word.
    message({ type: 'collaborate:notifications-changed' });
    message({ type: 'collaborate:notification', id: 7, body: 'Seven', url: '/seven' });
    message({ type: 'collaborate:notification', id: 7, body: 'Seven', url: '/seven' });
    message({ type: 'collaborate:notification', id: 8, body: { html: true } });
    message({ type: 'collaborate:notification', id: null });
    message({ type: 'collaborate:notification' });
    expect(told).toEqual([
      { id: '7', text: 'Seven', url: '/seven' },
      { id: '8', text: '', url: null },
    ]);
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(told).toHaveLength(2);
    message({ type: 'collaborate:notifications-changed' });
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(told).toHaveLength(2);
  });

  test('stops: no notice, no message nor event of a page changes anything', async () => {
    vi.useFakeTimers();
    const root = render([]);
    const fetcher = server({ 'GET /notifications': html(list([{ id: 1 }])) });
    const serviceWorker = new EventTarget();
    const { hub, bell } = start(root, { fetcher, serviceWorker });
    const [member] = hub.members;
    bell.stop();
    member.receive();
    serviceWorker.dispatchEvent(Object.assign(new Event('message'), { data: { type: 'collaborate:notification' } }));
    await vi.advanceTimersByTimeAsync(REFRESH_DELAY);
    expect(fetcher).not.toHaveBeenCalled();
    // A refresh already under way when it stopped.
    let answer;
    const slow = start(render([]), {
      fetcher: server({
        'GET /notifications': () =>
          new Promise((resolve) => {
            answer = resolve;
          }),
      }),
    });
    const refreshing = slow.bell.refresh();
    slow.bell.stop();
    answer(html(list([{ id: 1 }])));
    await refreshing;
    expect(document.querySelectorAll('.collaborate-notification')).toHaveLength(0);
  });

  test('takes the service worker of the browser by default', () => {
    const serviceWorker = Object.assign(new EventTarget(), { register: vi.fn() });
    navigator.serviceWorker = serviceWorker;
    const spy = vi.spyOn(serviceWorker, 'addEventListener');
    initBell(render([]), { hub: null, fetcher: server() });
    expect(spy).toHaveBeenCalledWith('message', expect.any(Function));
  });
});

describe('web push', () => {
  /** A browser that receives pushes: its permission, its service worker, the subscription it already has. */
  function browser({ permission = 'default', asked = 'granted', existing = null, encodings = ['aes128gcm'] } = {}) {
    const subscription = (key, endpoint = 'https://push.example/new') => ({
      endpoint,
      options: { applicationServerKey: key ? new Uint8Array(key).buffer : null },
      toJSON() {
        return { endpoint: this.endpoint, expirationTime: null, keys: { p256dh: 'p256dh', auth: 'auth' } };
      },
      unsubscribe: vi.fn(async () => true),
    });
    const current = existing ? subscription(existing, 'https://push.example/old') : null;
    const pushManager = {
      getSubscription: vi.fn(async () => current),
      subscribe: vi.fn(async (options) => subscription(options.applicationServerKey)),
    };
    const registration = { pushManager };
    // Registered, a worker is still installing the first time: only the active registration (ready) subscribes.
    const serviceWorker = Object.assign(new EventTarget(), {
      register: vi.fn(async () => ({ installing: {} })),
      ready: Promise.resolve(registration),
    });
    navigator.serviceWorker = serviceWorker;
    vi.stubGlobal('PushManager', Object.assign(class {}, { supportedContentEncodings: encodings }));
    const Notification = Object.assign(class {}, {
      permission,
      requestPermission: vi.fn(async () => {
        Notification.permission = asked;
        return asked;
      }),
    });
    vi.stubGlobal('Notification', Notification);
    return { serviceWorker, pushManager, current, Notification };
  }

  const pushRoutes = (overrides = {}) =>
    server({
      'POST /notifications/push/subscribe': Response.json({ subscribed: true }),
      'POST /notifications/push/unsubscribe': Response.json({ subscribed: false }),
      ...overrides,
    });

  const pushStart = async (env, fetcher = pushRoutes(), entries = []) => {
    const root = render(entries, { settings: { ...SETTINGS, push: PUSH } });
    const started = start(root, { fetcher, serviceWorker: env.serviceWorker });
    await started.bell.pushing;
    const button = () => menuOf(root).querySelector('.collaborate-notifications__push');
    const note = () => menuOf(root).querySelector('.collaborate-notifications__push-denied');
    return { ...started, root, button, note };
  };

  const body = (fetcher, call) => JSON.parse(fetcher.mock.calls[call][1].body);

  test('on a device web push reaches, a page without the focus tells the host of nothing: the system shows it', async () => {
    const env = browser({ permission: 'granted', existing: [1, 2, 3] });
    const { button } = await pushStart(env);
    expect(button().getAttribute('aria-checked')).toBe('true');
    const told = record('collaborate:notification');
    const focus = vi.spyOn(document, 'hasFocus').mockReturnValue(false);
    const message = (data) => env.serviceWorker.dispatchEvent(Object.assign(new Event('message'), { data }));
    message({ type: 'collaborate:notification', id: 21, body: 'Behind', url: null });
    expect(told).toEqual([]);
    // Announced once all the same: it is not said when the page gets the focus back.
    focus.mockReturnValue(true);
    message({ type: 'collaborate:notification', id: 21, body: 'Behind', url: null });
    message({ type: 'collaborate:notification', id: 22, body: 'Looked at', url: null });
    expect(told).toEqual([{ id: '22', text: 'Looked at', url: null }]);
  });

  test('stays hidden where web push is off', () => {
    const root = render([]);
    start(root);
    expect(menuOf(root).querySelector('.collaborate-notifications__push').hidden).toBe(true);
  });

  test('shows the switch off until it is asked, and asks nothing on load', async () => {
    const env = browser();
    const { button, note, fetcher } = await pushStart(env);
    expect(button().hidden).toBe(false);
    expect(button().getAttribute('aria-checked')).toBe('false');
    expect(note().hidden).toBe(true);
    expect(env.serviceWorker.register).not.toHaveBeenCalled();
    expect(fetcher).not.toHaveBeenCalled();
  });

  test('says the browser blocks the notifications, and offers nothing then', async () => {
    const env = browser({ permission: 'denied' });
    const { button, note } = await pushStart(env);
    expect(note().hidden).toBe(false);
    expect(button().getAttribute('aria-disabled')).toBe('true');
    button().click();
    expect(env.Notification.requestPermission).not.toHaveBeenCalled();
  });

  test('attaches a subscription of this device to the user again on load', async () => {
    const env = browser({ permission: 'granted', existing: [1, 2, 3], encodings: ['aesgcm'] });
    const { button, fetcher } = await pushStart(env);
    expect(env.serviceWorker.register).toHaveBeenCalledWith(PUSH.worker, { scope: '/' });
    expect(fetcher.mock.calls[0][0]).toBe(PUSH.subscribe);
    expect(fetcher.mock.calls[0][1].headers).toEqual({
      'Content-Type': 'application/json',
      'X-CSRF-Token': 'push-token',
    });
    expect(body(fetcher, 0)).toEqual({
      endpoint: 'https://push.example/old',
      keys: { p256dh: 'p256dh', auth: 'auth' },
      encoding: 'aesgcm',
    });
    expect(button().getAttribute('aria-checked')).toBe('true');
  });

  test('subscribes again when the keys of the server were rotated', async () => {
    const env = browser({ permission: 'granted', existing: [9, 9, 9] });
    const { button, fetcher } = await pushStart(env);
    expect(env.current.unsubscribe).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([PUSH.unsubscribe, PUSH.subscribe]);
    expect(body(fetcher, 0)).toEqual({ endpoint: 'https://push.example/old' });
    expect(env.pushManager.subscribe).toHaveBeenCalledWith({
      userVisibleOnly: true,
      applicationServerKey: decodeKey('AQID'),
    });
    expect(body(fetcher, 1).endpoint).toBe('https://push.example/new');
    expect(button().getAttribute('aria-checked')).toBe('true');
  });

  test('shows the switch off when granted without a subscription, or when the browser fails', async () => {
    let env = browser({ permission: 'granted' });
    let { button } = await pushStart(env);
    expect(button().getAttribute('aria-checked')).toBe('false');
    env = browser({ permission: 'granted', existing: [1, 2, 3] });
    env.serviceWorker.register.mockRejectedValue(new Error('no worker'));
    ({ button } = await pushStart(env));
    expect(button().getAttribute('aria-checked')).toBe('false');
  });

  test('drops a subscription the server refused, or could not be told of', async () => {
    let env = browser({ permission: 'granted', existing: [1, 2, 3] });
    let { button } = await pushStart(
      env,
      pushRoutes({ 'POST /notifications/push/subscribe': new Response('', { status: 403 }) })
    );
    expect(env.current.unsubscribe).toHaveBeenCalledOnce();
    expect(button().getAttribute('aria-checked')).toBe('false');
    env = browser({ permission: 'granted', existing: [1, 2, 3] });
    ({ button } = await pushStart(
      env,
      pushRoutes({
        'POST /notifications/push/subscribe': () => {
          throw new TypeError('offline');
        },
      })
    ));
    expect(env.current.unsubscribe).toHaveBeenCalledOnce();
    env = browser({ permission: 'granted', existing: [1, 2, 3] });
    ({ button } = await pushStart(
      env,
      pushRoutes({ 'POST /notifications/push/subscribe': () => redirected(Response.json({})) })
    ));
    expect(button().getAttribute('aria-checked')).toBe('false');
  });

  test('asks the permission from a click alone, and subscribes once granted', async () => {
    const env = browser();
    const { button, fetcher } = await pushStart(env);
    button().click();
    // A second click while the first is under way does nothing.
    button().click();
    await vi.waitFor(() => expect(button().getAttribute('aria-checked')).toBe('true'));
    expect(env.Notification.requestPermission).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([PUSH.subscribe]);
    // Then off: the browser and the server forget the device.
    button().click();
    await vi.waitFor(() => expect(button().getAttribute('aria-checked')).toBe('false'));
    expect(fetcher.mock.calls.at(-1)[0]).toBe(PUSH.unsubscribe);
    expect(body(fetcher, 1)).toEqual({ endpoint: 'https://push.example/new' });
  });

  test('says the browser blocks them once the reader refused, and does nothing when they decided nothing', async () => {
    let env = browser({ asked: 'denied' });
    let { button, note } = await pushStart(env);
    button().click();
    await vi.waitFor(() => expect(note().hidden).toBe(false));
    expect(button().getAttribute('aria-disabled')).toBe('true');
    env = browser({ asked: 'default' });
    ({ button, note } = await pushStart(env));
    button().click();
    await vi.waitFor(() => expect(env.Notification.requestPermission).toHaveBeenCalledOnce());
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(note().hidden).toBe(true);
    expect(button().getAttribute('aria-checked')).toBe('false');
    expect(env.serviceWorker.register).not.toHaveBeenCalled();
  });

  test('changes nothing when the browser fails while it switches', async () => {
    const env = browser();
    env.Notification.requestPermission.mockRejectedValue(new Error('no prompt'));
    const { button } = await pushStart(env);
    button().click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(button().getAttribute('aria-checked')).toBe('false');
    // It can be pressed again.
    button().click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(env.Notification.requestPermission).toHaveBeenCalledTimes(2);
  });

  test('draws the switch again in a new list, and nothing in a list without it', async () => {
    vi.useFakeTimers();
    const env = browser({ permission: 'granted', existing: [1, 2, 3] });
    const fetcher = pushRoutes({ 'GET /notifications': html(list([{ id: 1 }])) });
    const { bell, button } = await pushStart(env, fetcher);
    await bell.refresh();
    expect(button().hidden).toBe(false);
    expect(button().getAttribute('aria-checked')).toBe('true');
    fetcher.mockImplementation(async () =>
      html('<div class="collaborate-notifications__header" data-unread-count="0"></div>')
    );
    await bell.refresh();
    expect(button()).toBeNull();
  });

  test('draws nothing once stopped while the browser answered', async () => {
    const env = browser({ permission: 'granted', existing: [1, 2, 3] });
    const root = render([], { settings: { ...SETTINGS, push: PUSH } });
    const { bell } = start(root, { fetcher: pushRoutes(), serviceWorker: env.serviceWorker });
    bell.stop();
    await bell.pushing;
    expect(menuOf(root).querySelector('.collaborate-notifications__push').getAttribute('aria-checked')).toBe('false');
    // Nor after a switch.
    const again = await pushStart(browser());
    again.button().click();
    again.bell.stop();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(again.button().getAttribute('aria-checked')).toBe('false');
  });
});

describe('starting on its own', () => {
  async function load(readyState) {
    vi.resetModules();
    vi.spyOn(document, 'readyState', 'get').mockReturnValue(readyState);
    render([{ id: 1 }], { unread: 1 });
    const spy = vi.spyOn(document, 'addEventListener');
    const module = await import('../../../assets/notifications/bell.js');
    return { module, spy };
  }

  test('starts every bell of a parsed page once', async () => {
    const { module, spy } = await load('complete');
    const bells = spy.mock.calls.filter(([type]) => type === 'collaborate:notifications-changed');
    expect(bells).toHaveLength(1);
    module.startBells();
    expect(spy.mock.calls.filter(([type]) => type === 'collaborate:notifications-changed')).toHaveLength(1);
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  });

  test('waits for the page to be parsed', async () => {
    const { spy } = await load('loading');
    expect(spy.mock.calls.filter(([type]) => type === 'collaborate:notifications-changed')).toHaveLength(0);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(spy.mock.calls.filter(([type]) => type === 'collaborate:notifications-changed')).toHaveLength(1);
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  });
});
