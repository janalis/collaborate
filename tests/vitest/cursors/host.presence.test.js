import {
  createPresence,
  distinctColor,
  initialOf,
  NARROW_QUERY,
  NARROW_SHOWN,
} from '../../../assets/cursors/presence.js';

const COLORS = ['blue', 'indigo', 'violet', 'purple', 'pink', 'red', 'orange', 'amber'];
const messages = { showPointers: 'Pointers', idle: 'away' };
let container;
let presence;
let hooks;
const peer = (userId, overrides = {}) => ({
  userId,
  clientId: `client-${userId}`,
  label: `User ${userId}`,
  color: 'blue',
  ...overrides,
});
const menu = () => container.querySelector('.collaborate-presence__menu');
const toggle = () => container.querySelector('.collaborate-presence__toggle');
const entries = () => [...menu().querySelectorAll(':scope > [data-user-id]')];

beforeEach(() => {
  document.body.innerHTML = '<div data-collaborate-presence aria-label="Also here" hidden></div>';
  container = document.querySelector('[data-collaborate-presence]');
  presence = createPresence(undefined, 3, {
    presence: 'menu',
    distinctColors: true,
    colors: COLORS,
    idle: 60000,
    messages,
  });
  hooks = {
    describe: vi.fn((user) => (user.file ? `on ${user.file}` : '')),
    choose: vi.fn(),
    toggle: vi.fn(),
    shown: vi.fn(() => true),
    change: vi.fn(),
  };
  presence.bind(hooks);
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

test('the initial of a name is its first character as a reader sees one', () => {
  expect(initialOf('élise')).toBe('É');
  expect(initialOf('élise')).toBe('É');
  expect(initialOf('👩‍💻 dev')).toBe('👩‍💻');
  expect(initialOf('ßa')).toBe('ß');
  expect(initialOf('')).toBe('');
});

test('a colour beside one already drawn is replaced by the farthest from them all', () => {
  const users = new Map();
  expect(distinctColor(users, 'red', COLORS)).toBe('red');
  users.set(1, { shown: 'red' });
  expect(distinctColor(users, 'blue', COLORS)).toBe('blue');
  // Pink is beside red: the farthest from red, across the circle.
  expect(distinctColor(users, 'pink', COLORS)).toBe('indigo');
  expect(distinctColor(users, 'red', COLORS)).toBe('indigo');
  expect(distinctColor(users, 'green', COLORS)).toBe('green');
  users.set(2, { shown: 'blue' });
  expect(distinctColor(users, 'red', COLORS)).toBe('violet');
});

test('the peers are the button of a menu that lists them with where they are', () => {
  presence.see(peer(2, { label: 'bob', file: 'a' }), 0);
  presence.see(peer(1, { label: 'élise', color: 'indigo' }), 0);
  expect(container.hidden).toBe(false);
  const button = toggle();
  expect(button.getAttribute('aria-haspopup')).toBe('menu');
  expect(button.getAttribute('popovertarget')).toBe(menu().id);
  // Named after what it shows, then who: a wide window shows both initials.
  expect(button.getAttribute('aria-label')).toBe('É B: élise, bob');
  expect(button.title).toBe('élise, bob');
  expect([...button.children].map((item) => item.textContent)).toEqual(['É', 'B', '+1']);
  // The first peer shows on a narrow window, the others are counted there.
  expect(button.children[0].className).toBe('collaborate-presence__user');
  expect(button.children[1].className).toContain('collaborate-presence--wide');
  expect(button.children[2].className).toContain('collaborate-presence--narrow');
  expect(NARROW_SHOWN).toBe(1);
  for (const item of button.children) expect(item.getAttribute('aria-hidden')).toBe('true');
  expect(menu().getAttribute('role')).toBe('menu');
  expect(menu().getAttribute('popover')).toBe('auto');
  expect(menu().querySelector('.collaborate-presence__header').textContent).toBe('Also here');
  expect(entries().map((entry) => entry.querySelector('.collaborate-presence__name').textContent)).toEqual([
    'élise',
    'bob',
  ]);
  expect(entries()[1].querySelector('.collaborate-presence__where').textContent).toBe('on a');
  expect(entries()[1].querySelector('.collaborate-presence__swatch').getAttribute('aria-hidden')).toBe('true');
  const pointers = menu().querySelector('.collaborate-presence__pointers');
  expect(pointers.textContent).toBe('Pointers');
  expect(pointers.getAttribute('aria-checked')).toBe('true');
  expect(hooks.change).toHaveBeenCalled();
});

test('an entry is kept where it is, the focus may be on it, and a peer gone takes theirs away', () => {
  presence.see(peer(3), 0);
  presence.see(peer(1), 0);
  const kept = entries()[1];
  presence.see(peer(2), 0);
  expect(entries().map((entry) => entry.dataset.userId)).toEqual(['1', '2', '3']);
  expect(entries()[2]).toBe(kept);
  presence.leave(2, 'client-2');
  expect(entries().map((entry) => entry.dataset.userId)).toEqual(['1', '3']);
  const frame = toggle();
  presence.see(peer(4, { avatar: '/four.png' }), 0);
  expect(toggle()).toBe(frame);
  expect(entries()[2].querySelector('img').src).toBe('http://localhost/four.png');
});

test('a peer still for the configured time is said idle', () => {
  presence.see(peer(1, { moving: true }), 0);
  expect(entries()[0].dataset.idle).toBe('false');
  presence.see(peer(1), 60000);
  expect(entries()[0].dataset.idle).toBe('false');
  hooks.change.mockClear();
  presence.see(peer(1), 60001);
  expect(entries()[0].dataset.idle).toBe('true');
  expect(entries()[0].querySelector('.collaborate-presence__where').textContent).toBe('away');
  expect(hooks.change).toHaveBeenCalledOnce();
  presence.see(peer(1, { moving: true }), 70000);
  expect(entries()[0].dataset.idle).toBe('false');
  // Expiry says it as well, and only once.
  hooks.change.mockClear();
  presence.expire(130001, 100000);
  expect(entries()[0].dataset.idle).toBe('true');
  presence.expire(130002, 100000);
  expect(hooks.change).toHaveBeenCalledOnce();
  // Without an idle time, nobody is.
  presence = createPresence(undefined, 3, { presence: 'menu' });
  presence.see(peer(1), 0);
  presence.expire(10 ** 9, 10 ** 10);
  expect(presence.users.get(1).idle).toBe(false);
});

test('a peer who moves to another record or popup is said again', () => {
  presence.see(peer(1), 0);
  hooks.change.mockClear();
  presence.see(peer(1, { file: 'a' }), 0);
  presence.see(peer(1, { file: 'a', popup: { kind: 'share', key: 'a' } }), 0);
  presence.see(peer(1, { file: 'a', popup: { kind: 'share', key: 'b' } }), 0);
  presence.see(peer(1, { file: 'a', popup: { kind: 'metadata', key: 'b' } }), 0);
  expect(hooks.change).toHaveBeenCalledTimes(4);
  presence.see(peer(1, { file: 'a', popup: { kind: 'metadata', key: 'b' } }), 0);
  expect(hooks.change).toHaveBeenCalledTimes(4);
});

test('peers keep their distinct colours, picked among the others as they come', () => {
  presence.see(peer(1, { color: 'red' }), 0);
  presence.see(peer(2, { color: 'pink' }), 0);
  expect(presence.colorOf(1)).toBe('red');
  expect(presence.colorOf(2)).toBe('indigo');
  expect(presence.colorOf(3)).toBeNull();
  // A colour the list does not hold is kept.
  presence.see(peer(3, { color: 'teal' }), 0);
  expect(presence.colorOf(3)).toBe('teal');
  // Without distinct colours, each is the server's.
  presence = createPresence(undefined, 3, { colors: COLORS });
  presence.see(peer(1, { color: 'red' }), 0);
  presence.see(peer(2, { color: 'pink' }), 0);
  expect(presence.colorOf(2)).toBe('pink');
});

test('a peer chosen in the menu is found, the switch toggles the pointers, and the menu closes', () => {
  presence.see(peer(1), 0);
  const list = menu();
  list.matches = vi.fn(() => true);
  list.hidePopover = vi.fn();
  const focus = vi.spyOn(toggle(), 'focus');
  entries()[0].querySelector('.collaborate-presence__name').click();
  expect(hooks.choose).toHaveBeenCalledWith(1);
  expect(list.hidePopover).toHaveBeenCalledOnce();
  expect(focus).toHaveBeenCalled();
  list.matches = vi.fn(() => false);
  list.querySelector('.collaborate-presence__pointers').click();
  expect(hooks.toggle).toHaveBeenCalledOnce();
  expect(list.hidePopover).toHaveBeenCalledOnce();
  // A click elsewhere does nothing.
  toggle().click();
  list.querySelector('.collaborate-presence__header').click();
  expect(hooks.choose).toHaveBeenCalledOnce();
  expect(hooks.toggle).toHaveBeenCalledOnce();
});

test('the menu opens under its button, its first item focused, and the arrows go from item to item', () => {
  presence.see(peer(1), 0);
  presence.see(peer(2), 0);
  const list = menu();
  toggle().getBoundingClientRect = () => ({ bottom: 40, right: 300 });
  Object.defineProperty(list, 'offsetWidth', { configurable: true, value: 200 });
  const opened = new Event('toggle');
  opened.newState = 'open';
  list.dispatchEvent(opened);
  expect(toggle().getAttribute('aria-expanded')).toBe('true');
  expect(list.style.top).toBe('44px');
  expect(list.style.left).toBe('100px');
  const items = [...list.querySelectorAll('[role^="menuitem"]')];
  expect(document.activeElement).toBe(items[0]);
  const key = (name) => {
    const event = new KeyboardEvent('keydown', { key: name, cancelable: true });
    list.dispatchEvent(event);
    return event.defaultPrevented;
  };
  expect(key('ArrowDown')).toBe(true);
  expect(document.activeElement).toBe(items[1]);
  key('End');
  expect(document.activeElement).toBe(items[2]);
  key('ArrowDown');
  expect(document.activeElement).toBe(items[0]);
  key('ArrowUp');
  expect(document.activeElement).toBe(items[2]);
  key('Home');
  expect(document.activeElement).toBe(items[0]);
  expect(key('a')).toBe(false);
  // Too close to the start of the window, it stays in it.
  Object.defineProperty(list, 'offsetWidth', { configurable: true, value: 400 });
  list.dispatchEvent(opened);
  expect(list.style.left).toBe('8px');
  const closed = new Event('toggle');
  closed.newState = 'closed';
  list.dispatchEvent(closed);
  expect(toggle().getAttribute('aria-expanded')).toBe('false');
});

test('the last peer gone closes an open menu; a page that stops lets go of the menu', () => {
  presence.see(peer(1), 0);
  const list = menu();
  list.matches = vi.fn(() => true);
  list.hidePopover = vi.fn();
  presence.clear();
  expect(list.hidePopover).toHaveBeenCalledOnce();
  expect(container.hidden).toBe(true);
  presence.dispose();
  presence.see(peer(1), 0);
  menu().querySelector('[data-user-id]').click();
  expect(hooks.choose).not.toHaveBeenCalled();
});

test('a menu without a label, and hooks left unbound, still render', () => {
  container.removeAttribute('aria-label');
  presence = createPresence(undefined, 3, { presence: 'menu' });
  presence.see(peer(1, { file: 'a' }), 0);
  expect(menu().querySelector('.collaborate-presence__header').textContent).toBe('');
  expect(menu().querySelector('.collaborate-presence__pointers').textContent).toBe('Show pointers');
  expect(entries()[0].querySelector('.collaborate-presence__where').textContent).toBe('');
  entries()[0].click();
  menu().querySelector('.collaborate-presence__pointers').click();
  presence.dispose();
});

test('idle without a message reads idle', () => {
  presence = createPresence(undefined, 3, { presence: 'menu', idle: 10 });
  presence.see(peer(1), 0);
  presence.see(peer(1), 100);
  expect(entries()[0].querySelector('.collaborate-presence__where').textContent).toBe('idle');
});

test('past the avatars the menu shows, the others are counted on a wide window too', () => {
  for (let id = 1; id <= 5; id++) presence.see(peer(id, { color: COLORS[id] }), 0);
  const counters = [...toggle().querySelectorAll('.collaborate-presence__more')].map((more) => [
    more.textContent,
    more.className,
  ]);
  expect(counters).toEqual([
    ['+4', 'collaborate-presence__more collaborate-presence--narrow'],
    ['+2', 'collaborate-presence__more collaborate-presence--wide'],
  ]);
});

test("the menu's button is named after the stack its window shows, and again when the window changes", () => {
  const listeners = new Set();
  const query = {
    matches: true,
    addEventListener: vi.fn((_type, listener) => listeners.add(listener)),
    removeEventListener: vi.fn((_type, listener) => listeners.delete(listener)),
  };
  const matchMedia = vi.fn(() => query);
  vi.stubGlobal('matchMedia', matchMedia);
  presence = createPresence(undefined, 3, { presence: 'menu' });
  expect(matchMedia).toHaveBeenCalledWith(NARROW_QUERY);
  expect(NARROW_QUERY).toBe('(max-width: 575.98px)');
  presence.see(peer(1, { label: 'élise' }), 0);
  presence.see(peer(2, { label: 'bob' }), 0);
  presence.see(peer(3, { label: 'carl', avatar: '/three.png' }), 0);
  // Narrow: the first peer, the others counted.
  expect(toggle().getAttribute('aria-label')).toBe('É +2: élise, bob, carl');
  query.matches = false;
  for (const listener of listeners) listener();
  // Wide: the initials, a picture showing none.
  expect(toggle().getAttribute('aria-label')).toBe('É B: élise, bob, carl');
  presence.dispose();
  expect(query.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function));
  expect(listeners.size).toBe(0);
});

test('a button showing pictures alone is named after the peers, and a container without one is passed', () => {
  presence.see(peer(1, { label: 'élise', avatar: '/one.png' }), 0);
  expect(toggle().getAttribute('aria-label')).toBe('élise');
  document.body.insertAdjacentHTML('beforeend', '<div data-collaborate-presence hidden></div>');
  const listeners = new Set();
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: (_type, listener) => listeners.add(listener),
    removeEventListener: () => {},
  }));
  presence = createPresence(undefined, 3, { presence: 'menu' });
  for (const listener of listeners) expect(() => listener()).not.toThrow();
});
