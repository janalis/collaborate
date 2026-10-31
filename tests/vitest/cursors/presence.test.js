import { avatarUrl, createPresence, MAX_SHOWN } from '../../../assets/cursors/presence.js';

let strip;
let presence;
const peer = (userId, overrides = {}) => ({
  userId,
  clientId: `client-${userId}`,
  label: `User ${userId}`,
  color: 'teal',
  avatar: null,
  ...overrides,
});

beforeEach(() => {
  document.body.innerHTML = '<div data-collaborate-presence hidden></div><div data-collaborate-presence hidden></div>';
  strip = document.querySelector('[data-collaborate-presence]');
  presence = createPresence();
});

test('keeps avatars on the page origin only', () => {
  expect(avatarUrl('/avatars/a.jpg')).toBe('http://localhost/avatars/a.jpg');
  expect(avatarUrl('http://localhost/media/a.jpg')).toBe('http://localhost/media/a.jpg');
  expect(avatarUrl('//evil.example/a.jpg')).toBeNull();
  expect(avatarUrl('https://evil.example/a.jpg')).toBeNull();
  expect(avatarUrl('http://[')).toBeNull();
  expect(avatarUrl('')).toBeNull();
  expect(avatarUrl(null)).toBeNull();
  expect(avatarUrl(3)).toBeNull();
});

test('draws each user once, in a stable order, in every container', () => {
  presence.see(peer(7, { avatar: '/a/seven.jpg' }));
  presence.see(peer(3, { label: 'élise' }));
  presence.see(peer(7, { clientId: 'other-tab', avatar: '/a/seven.jpg' }));
  for (const container of document.querySelectorAll('[data-collaborate-presence]')) {
    expect(container.hidden).toBe(false);
    expect([...container.children].map((item) => item.dataset.userId)).toEqual(['3', '7']);
  }
  const [initial, picture] = strip.children;
  expect(initial.textContent).toBe('É');
  expect(initial.getAttribute('role')).toBe('img');
  expect(initial.getAttribute('aria-label')).toBe('élise');
  expect(initial.dataset.color).toBe('teal');
  expect(picture.title).toBe('User 7');
  expect(picture.querySelector('img').alt).toBe('User 7');
  expect(picture.querySelector('img').src).toBe('http://localhost/a/seven.jpg');
});

test('redraws only when what is shown changes', () => {
  presence.see(peer(1));
  const first = strip.firstChild;
  presence.see(peer(1));
  expect(strip.firstChild).toBe(first);
  presence.see(peer(1, { color: 'red' }));
  expect(strip.firstChild.dataset.color).toBe('red');
  presence.see(peer(1, { color: 'red', label: 'Renamed' }));
  expect(strip.firstChild.title).toBe('Renamed');
  presence.see(peer(1, { color: 'red', label: 'Renamed', avatar: '/new.jpg' }));
  expect(strip.firstChild.querySelector('img')).not.toBeNull();
});

test('folds users past the limit into a counter naming them', () => {
  for (let userId = 1; userId <= MAX_SHOWN + 2; userId++) presence.see(peer(userId));
  expect(strip.querySelectorAll('.collaborate-presence__user')).toHaveLength(MAX_SHOWN);
  const more = strip.querySelector('.collaborate-presence__more');
  expect(more.textContent).toBe('+2');
  expect(more.title).toBe(`User ${MAX_SHOWN + 1}, User ${MAX_SHOWN + 2}`);
});

test('a user leaves with the last of its tabs', () => {
  presence.see(peer(1));
  presence.see(peer(1, { clientId: 'second' }));
  presence.leave(1, 'client-1');
  expect(presence.users.has(1)).toBe(true);
  presence.leave(1, 'unknown');
  presence.leave(2, 'client-2');
  presence.leave(1, 'second');
  expect(presence.users.has(1)).toBe(false);
  expect(strip.hidden).toBe(true);
});

test('expires tabs by receipt time and clears on demand', () => {
  presence.see(peer(1), 1000);
  presence.see(peer(2), 1000);
  presence.see(peer(2, { clientId: 'fresh' }), 9000);
  presence.expire(10000, 15000);
  expect(presence.users.size).toBe(2);
  presence.expire(17000, 15000);
  expect([...presence.users.keys()]).toEqual([2]);
  expect(presence.users.get(2).clients.size).toBe(1);
  presence.clear();
  expect(presence.users.size).toBe(0);
  expect(strip.hidden).toBe(true);
});

test('shows as many avatars as configured', () => {
  presence = createPresence(undefined, 2);
  for (let userId = 1; userId <= 4; userId++) presence.see(peer(userId));
  expect(strip.querySelectorAll('.collaborate-presence__user')).toHaveLength(2);
  expect(strip.querySelector('.collaborate-presence__more').textContent).toBe('+2');
});
