// Other editors on the page, drawn beside the header's own avatar. Fed by `cursors.js`.
export const MAX_SHOWN = 5;

// The server attaches the URL; anything that would load from another origin is dropped all the same.
export function avatarUrl(value, base = location.href) {
  if (typeof value !== 'string' || value === '') return null;
  try {
    const url = new URL(value, base);
    return url.origin === new URL(base).origin ? url.href : null;
  } catch {
    return null;
  }
}

// `maxShown` comes from the layer's `data-config.maxAvatars`; past it, a `+n` chip names the rest.
export function createPresence(
  containers = () => document.querySelectorAll('[data-collaborate-presence]'),
  maxShown = MAX_SHOWN
) {
  const users = new Map();

  function render() {
    const present = [...users.entries()].sort(([a], [b]) => a - b);
    for (const container of containers()) {
      container.replaceChildren();
      container.hidden = present.length === 0;
      for (const [userId, user] of present.slice(0, maxShown)) {
        const item = document.createElement('span');
        item.className = 'collaborate-presence__user';
        item.dataset.userId = userId;
        item.dataset.color = user.color;
        item.title = user.label;
        if (user.avatar) {
          const image = document.createElement('img');
          image.src = user.avatar;
          image.alt = user.label;
          item.appendChild(image);
        } else {
          item.textContent = user.label.charAt(0).toUpperCase();
          item.setAttribute('role', 'img');
          item.setAttribute('aria-label', user.label);
        }
        container.appendChild(item);
      }
      const hidden = present.slice(maxShown);
      if (hidden.length) {
        const more = document.createElement('span');
        more.className = 'collaborate-presence__more';
        more.textContent = `+${hidden.length}`;
        more.title = hidden.map(([, user]) => user.label).join(', ');
        container.appendChild(more);
      }
    }
  }

  function see({ userId, clientId, label, color, avatar }, now = Date.now()) {
    const user = users.get(userId) || { clients: new Map() };
    const changed =
      !users.has(userId) || user.label !== label || user.color !== color || user.avatar !== avatarUrl(avatar);
    user.clients.set(clientId, now);
    Object.assign(user, { label, color, avatar: avatarUrl(avatar) });
    users.set(userId, user);
    if (changed) render();
  }

  function leave(userId, clientId) {
    const user = users.get(userId);
    if (!user?.clients.delete(clientId) || user.clients.size) return;
    users.delete(userId);
    render();
  }

  // A client counts as gone `ttl` milliseconds after its last event was received.
  function expire(now, ttl) {
    let changed = false;
    for (const [userId, user] of users) {
      for (const [clientId, seen] of user.clients) if (now - seen > ttl) user.clients.delete(clientId);
      if (!user.clients.size) changed = users.delete(userId);
    }
    if (changed) render();
  }

  function clear() {
    users.clear();
    render();
  }

  return { see, leave, expire, clear, users };
}
