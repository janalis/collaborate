// @ts-check
// Other editors on the page, drawn beside the header's own avatar. Fed by `cursors.js`.
import { popoverMenu } from '../popover-menu.js';

export const MAX_SHOWN = 5;
// On a narrow window the menu's button shows one peer, the others counted (the stylesheet tells which is which).
export const NARROW_SHOWN = 1;
// A narrow window, as the stylesheet tells it (`_layer.scss`).
export const NARROW_QUERY = '(max-width: 575.98px)';

/**
 * A peer as the presence knows them: their tabs (when each was last heard, by client), how they are drawn, and
 * where they are.
 *
 * @typedef {object} PresentUser
 * @property {Map<string, number>} clients
 * @property {number} moved when their pointer last moved
 * @property {boolean} idle
 * @property {string | null} file the record they are at
 * @property {import('./geometry.js').Popup | null} popup
 * @property {string} label
 * @property {string} color the colour the server gives them
 * @property {string | null} avatar
 * @property {string} shown the colour they are drawn in on this page
 */

// The server attaches the URL; anything that would load from another origin is dropped all the same.
/**
 * @param {unknown} value
 * @param {string} [base]
 */
export function avatarUrl(value, base = location.href) {
  if (typeof value !== 'string' || value === '') return null;
  try {
    const url = new URL(value, base);
    return url.origin === new URL(base).origin ? url.href : null;
  } catch {
    return null;
  }
}

/**
 * The initial of a name: its first character as a reader sees one (an accent written apart, an emoji of
 * several), in capital unless that makes it two (ß).
 *
 * @param {string} label
 */
export function initialOf(label) {
  /** @param {string} text */
  const graphemes = (text) => [...new Intl.Segmenter().segment(text)].map(({ segment }) => segment);
  const [first = ''] = graphemes(label.normalize('NFC'));
  const capital = first.toUpperCase();
  return graphemes(capital).length > 1 ? first : capital;
}

// How far apart two colours of the list are, the list being a circle of hues.
/**
 * @param {number} a
 * @param {number} b
 * @param {number} count
 */
const apart = (a, b, count) => {
  const distance = Math.abs(a - b);
  return Math.min(distance, count - distance);
};

/**
 * The colour a peer is drawn in on this page: the one the server gives them, unless a peer who came before has
 * it or one beside it in the list (pink and red look alike); then the one farthest from the others' colours, the
 * first of them after their own.
 *
 * @param {Map<unknown, { shown: string }>} users
 * @param {string} color
 * @param {string[]} colors
 */
export function distinctColor(users, color, colors) {
  const own = colors.indexOf(color);
  if (own < 0) return color;
  const taken = [...users.values()].map((user) => colors.indexOf(user.shown));
  /** @param {number} index */
  const gap = (index) => Math.min(colors.length, ...taken.map((other) => apart(index, other, colors.length)));
  if (gap(own) > 1) return color;
  let best = own;
  for (let step = 1; step < colors.length; step++) {
    const index = (own + step) % colors.length;
    if (gap(index) > gap(best)) best = index;
  }
  return colors[best];
}

let menus = 0;

// `maxShown` comes from the layer's `data-config.maxAvatars`; past it, a `+n` chip names the rest. `options`
// carries the rest of the layer's configuration: `presence: 'menu'` makes the peers the button of a menu that
// finds them, `distinctColors` keeps neighbours' colours apart, `idle` (milliseconds) says a still peer idle.
/**
 * @param {() => Iterable<HTMLElement>} [containers]
 * @param {number} [maxShown]
 * @param {{ presence?: string, distinctColors?: boolean, colors?: string[], idle?: number, messages?: Record<string, string> }} [options]
 */
export function createPresence(
  containers = () => /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('[data-collaborate-presence]')),
  maxShown = MAX_SHOWN,
  { presence = 'avatars', distinctColors = false, colors = [], idle = 0, messages = {} } = {}
) {
  /** @type {Map<number, PresentUser>} */
  const users = new Map();
  // What the cursors tell of a peer, and do for them (`cursors.js` binds them).
  /** @type {{ describe: (user: any) => string, choose: (userId: number) => void, toggle: () => void, shown: () => boolean, change: () => void }} */
  const hooks = {
    describe: () => '',
    choose: () => {},
    toggle: () => {},
    shown: () => true,
    change: () => {},
  };
  /** @type {Map<HTMLElement, () => void>} */
  const bound = new Map();
  let disposed = false;
  // Which of the menu's stacks shows, the narrow or the wide one: its button is named after what it shows.
  const narrow = presence === 'menu' && typeof matchMedia === 'function' ? matchMedia(NARROW_QUERY) : null;

  /**
   * @param {PresentUser} user
   * @param {number} now
   */
  const isIdle = (user, now) => idle > 0 && now - user.moved > idle;

  /**
   * @param {number} userId
   * @param {PresentUser} user
   * @param {string} className
   */
  function avatar(userId, user, className) {
    const item = document.createElement('span');
    item.className = className;
    item.dataset.userId = /** @type {string} */ (/** @type {unknown} */ (userId));
    item.dataset.color = user.shown;
    item.title = user.label;
    if (user.avatar) {
      const image = document.createElement('img');
      image.src = user.avatar;
      image.alt = user.label;
      item.appendChild(image);
    } else {
      item.textContent = presence === 'menu' ? initialOf(user.label) : user.label.charAt(0).toUpperCase();
      item.setAttribute('role', 'img');
      item.setAttribute('aria-label', user.label);
    }
    return item;
  }

  /**
   * @param {[number, PresentUser][]} hidden
   * @param {string} className
   */
  function counted(hidden, className) {
    const more = document.createElement('span');
    more.className = className;
    more.textContent = `+${hidden.length}`;
    more.title = hidden.map(([, user]) => user.label).join(', ');
    return more;
  }

  /**
   * @param {HTMLElement} container
   * @param {[number, PresentUser][]} present
   */
  function renderAvatars(container, present) {
    container.replaceChildren();
    for (const [userId, user] of present.slice(0, maxShown))
      container.appendChild(avatar(userId, user, 'collaborate-presence__user'));
    const hidden = present.slice(maxShown);
    if (hidden.length) container.appendChild(counted(hidden, 'collaborate-presence__more'));
  }

  /**
   * The button, the menu, and what they do, made once: an open menu stays open as peers come and go.
   *
   * @param {HTMLElement} container
   */
  function frame(container) {
    /** @type {HTMLButtonElement | null} */
    let toggle = container.querySelector(':scope > .collaborate-presence__toggle');
    if (!toggle) {
      menus++;
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.id = `collaborate-presence-${menus}`;
      toggle.className = 'collaborate-presence__toggle';
      toggle.setAttribute('aria-haspopup', 'menu');
      toggle.setAttribute('aria-expanded', 'false');
      const menu = document.createElement('div');
      menu.id = `collaborate-presence-menu-${menus}`;
      menu.className = 'collaborate-presence__menu';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-labelledby', toggle.id);
      menu.setAttribute('popover', 'auto');
      toggle.setAttribute('popovertarget', menu.id);
      const header = document.createElement('span');
      header.className = 'collaborate-presence__header';
      header.setAttribute('role', 'presentation');
      header.textContent = container.getAttribute('aria-label') ?? '';
      const divider = document.createElement('hr');
      divider.className = 'collaborate-presence__divider';
      divider.setAttribute('role', 'presentation');
      const pointers = document.createElement('button');
      pointers.type = 'button';
      pointers.className = 'collaborate-presence__item collaborate-presence__pointers';
      pointers.setAttribute('role', 'menuitemcheckbox');
      pointers.textContent = messages.showPointers ?? 'Show pointers';
      menu.append(header, divider, pointers);
      container.replaceChildren(toggle, menu);
    }
    // The button's sibling, as made above.
    const menu = /** @type {HTMLElement} */ (toggle.nextElementSibling);
    if (!disposed && !bound.has(container)) {
      /** @param {MouseEvent} event */
      const click = (event) => {
        const item = /** @type {HTMLElement | null} */ (
          /** @type {Element} */ (event.target).closest('.collaborate-presence__item')
        );
        if (!item || !menu.contains(item)) return;
        if (item.classList.contains('collaborate-presence__pointers')) hooks.toggle();
        else hooks.choose(Number(item.dataset.userId));
        // An item chosen closes the menu: the focus goes back to its button.
        if (menu.matches?.(':popover-open')) menu.hidePopover();
        toggle.focus();
      };
      container.addEventListener('click', click);
      // The menu's items: the peers, then the pointers' switch.
      const unbind = popoverMenu(toggle, menu);
      bound.set(container, () => {
        container.removeEventListener('click', click);
        unbind();
      });
    }
    return { toggle, menu };
  }

  /**
   * A peer's entry in the menu: their initial, their name, and where they are; kept, the focus may be on it.
   *
   * @param {HTMLElement} menu
   * @param {number} userId
   * @param {PresentUser} user
   * @param {number} now
   */
  function entry(menu, userId, user, now) {
    let item = [
      .../** @type {NodeListOf<HTMLButtonElement>} */ (menu.querySelectorAll(':scope > [data-user-id]')),
    ].find((element) => element.dataset.userId === String(userId));
    if (!item) {
      item = document.createElement('button');
      item.type = 'button';
      item.className = 'collaborate-presence__item';
      item.dataset.userId = /** @type {string} */ (/** @type {unknown} */ (userId));
      item.setAttribute('role', 'menuitem');
      const text = document.createElement('span');
      text.className = 'collaborate-presence__text';
      const name = document.createElement('span');
      name.className = 'collaborate-presence__name';
      const where = document.createElement('span');
      where.className = 'collaborate-presence__where';
      text.append(name, where);
      item.append(document.createElement('span'), text);
    }
    const swatch = avatar(userId, user, 'collaborate-presence__swatch');
    swatch.setAttribute('aria-hidden', 'true');
    swatch.removeAttribute('role');
    swatch.removeAttribute('aria-label');
    swatch.removeAttribute('title');
    // The entry's parts, as made above.
    /** @type {Element} */ (item.firstElementChild).replaceWith(swatch);
    /** @type {Element} */ (item.querySelector('.collaborate-presence__name')).textContent = user.label;
    /** @type {Element} */ (item.querySelector('.collaborate-presence__where')).textContent = [
      hooks.describe(user),
      isIdle(user, now) ? (messages.idle ?? 'idle') : '',
    ]
      .filter(Boolean)
      .join(' · ');
    item.dataset.idle = String(isIdle(user, now));
    return item;
  }

  /**
   * Names the menu's button: what its stack shows on this window (initials, the count of the others), then the
   * peers' names. A name holding the visible text is the one speech control finds it by (WCAG 2.5.3).
   *
   * @param {HTMLElement} toggle
   */
  function name(toggle) {
    const hidden = narrow?.matches ? 'collaborate-presence--wide' : 'collaborate-presence--narrow';
    const shown = [...toggle.children]
      .filter((item) => !item.classList.contains(hidden))
      .map((item) => item.textContent)
      .filter(Boolean)
      .join(' ');
    toggle.setAttribute('aria-label', shown ? `${shown}: ${toggle.title}` : toggle.title);
  }

  // The window becoming narrow or wide shows the other stack: the buttons are named again.
  function resized() {
    for (const container of containers()) {
      const toggle = /** @type {HTMLElement | null} */ (
        container.querySelector(':scope > .collaborate-presence__toggle')
      );
      if (toggle) name(toggle);
    }
  }
  narrow?.addEventListener('change', resized);

  /**
   * @param {HTMLElement} container
   * @param {[number, PresentUser][]} present
   * @param {number} now
   */
  function renderMenu(container, present, now) {
    const { toggle, menu } = frame(container);
    // The last peer gone, an open menu closes: the header would hide it open.
    if (!present.length && menu.matches?.(':popover-open')) menu.hidePopover();
    const stack = present
      .slice(0, maxShown)
      .map(([userId, user], index) =>
        avatar(
          userId,
          user,
          index < NARROW_SHOWN ? 'collaborate-presence__user' : 'collaborate-presence__user collaborate-presence--wide'
        )
      );
    if (present.length > NARROW_SHOWN)
      stack.push(counted(present.slice(NARROW_SHOWN), 'collaborate-presence__more collaborate-presence--narrow'));
    if (present.length > maxShown)
      stack.push(counted(present.slice(maxShown), 'collaborate-presence__more collaborate-presence--wide'));
    for (const item of stack) {
      item.setAttribute('aria-hidden', 'true');
      item.removeAttribute('role');
      item.removeAttribute('aria-label');
    }
    // The chips apart, as they are drawn: text run together would read as one word (`ÉB` for É and B).
    toggle.replaceChildren(...stack.flatMap((item, index) => (index ? [' ', item] : [item])));
    const names = present.map(([, user]) => user.label).join(', ');
    toggle.title = names;
    name(toggle);
    const entries = present.map(([userId, user]) => entry(menu, userId, user, now));
    for (const item of /** @type {NodeListOf<HTMLButtonElement>} */ (menu.querySelectorAll(':scope > [data-user-id]')))
      if (!entries.includes(item)) item.remove();
    let next = /** @type {Element} */ (menu.querySelector(':scope > .collaborate-presence__header')).nextSibling;
    for (const item of entries) {
      if (item !== next) menu.insertBefore(item, next);
      next = item.nextSibling;
    }
    /** @type {Element} */ (menu.querySelector('.collaborate-presence__pointers')).setAttribute(
      'aria-checked',
      String(hooks.shown())
    );
  }

  function render(now = Date.now()) {
    const present = [...users.entries()].sort(([a], [b]) => a - b);
    for (const container of containers()) {
      container.hidden = present.length === 0;
      if (presence === 'menu') renderMenu(container, present, now);
      else renderAvatars(container, present);
    }
  }

  /**
   * Whether what is told of where a peer is changed.
   *
   * @param {PresentUser} user
   * @param {string | null} file
   * @param {import('./geometry.js').Popup | null} popup
   */
  const placed = (user, file, popup) =>
    user.file !== file || user.popup?.kind !== popup?.kind || user.popup?.key !== popup?.key;

  /**
   * A page of a peer said something: they are here, in that page, as of now (on a record, in a popup, their
   * pointer moving).
   *
   * @param {{
   *   userId: number,
   *   clientId: string,
   *   label: string,
   *   color: string,
   *   avatar?: unknown,
   *   file?: string | null,
   *   popup?: import('./geometry.js').Popup | null,
   *   moving?: boolean,
   * }} seen
   * @param {number} [now]
   */
  function see(
    { userId, clientId, label, color, avatar, file = null, popup = null, moving = false },
    now = Date.now()
  ) {
    const known = users.get(userId);
    // Completed below, with what they look like.
    const user =
      known || /** @type {PresentUser} */ ({ clients: new Map(), moved: now, idle: false, file: null, popup: null });
    const changed = !known || user.label !== label || user.color !== color || user.avatar !== avatarUrl(avatar);
    // Where a peer is shows in the menu alone; the avatars stay as they are, and only the marks move.
    const moved = placed(user, file, popup);
    user.clients.set(clientId, now);
    if (!known || user.color !== color) {
      // Picked among the others.
      users.delete(userId);
      user.shown = distinctColors ? distinctColor(users, color, colors) : color;
    }
    if (moving) user.moved = now;
    Object.assign(user, { label, color, avatar: avatarUrl(avatar), file, popup });
    users.set(userId, user);
    const wasIdle = user.idle;
    user.idle = isIdle(user, now);
    if (changed || wasIdle !== user.idle || (moved && presence === 'menu')) render(now);
    if (changed || wasIdle !== user.idle || moved) hooks.change();
  }

  /**
   * @param {number} userId
   * @param {string} clientId
   */
  function leave(userId, clientId) {
    const user = users.get(userId);
    if (!user?.clients.delete(clientId) || user.clients.size) return;
    users.delete(userId);
    render();
    hooks.change();
  }

  // A client counts as gone `ttl` milliseconds after its last event was received; a peer who became idle
  // meanwhile is said to be.
  /**
   * @param {number} now
   * @param {number} ttl
   */
  function expire(now, ttl) {
    let changed = false;
    for (const [userId, user] of users) {
      for (const [clientId, seen] of user.clients) if (now - seen > ttl) user.clients.delete(clientId);
      if (!user.clients.size) changed = users.delete(userId);
      else if (isIdle(user, now) !== user.idle) {
        user.idle = !user.idle;
        changed = true;
      }
    }
    if (changed) {
      render(now);
      hooks.change();
    }
  }

  function clear() {
    users.clear();
    render();
    hooks.change();
  }

  /**
   * The colour a peer is drawn in on this page, or null for one who is not here.
   *
   * @param {number} userId
   */
  const colorOf = (userId) => users.get(userId)?.shown ?? null;

  /**
   * What the cursors tell of the peers and do for them.
   *
   * @param {Partial<typeof hooks>} next
   */
  function bind(next) {
    Object.assign(hooks, next);
  }

  /** Lets go of the containers: the page stops sharing. */
  function dispose() {
    disposed = true;
    narrow?.removeEventListener('change', resized);
    for (const release of bound.values()) release();
    bound.clear();
  }

  return { see, leave, expire, clear, render, colorOf, bind, dispose, users };
}
