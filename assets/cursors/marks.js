// @ts-check
// What a page draws of its peers besides their pointers and carets, each a host option: the records they point
// at or have a popup of, marked with their initials (`cursors.markers`); an arrow at the edge of a pane for a
// pointer scrolled out of it (`cursors.edges`); and, in a popup of a record, who else has it open
// (`cursors.popups`).
import { capturePopup, findRecord, offscreenAnchor, samePopup, seen } from './geometry.js';
import { initialOf } from './presence.js';

// How many peers a record is marked with, the others counted.
export const MARKED = 3;

/** Fills the placeholders of a sentence: `%name%` and the like. */
export function format(message, parameters) {
  return Object.entries(parameters).reduce((text, [name, value]) => text.replaceAll(`%${name}%`, value), message ?? '');
}

/**
 * @param {HTMLElement} layer
 * @param {ReturnType<typeof import('./presence.js').createPresence>} presence
 * @param {{ markers?: boolean, edges?: string | null, popups?: Record<string, { where?: string, line?: string }>, messages?: Record<string, string> }} [options]
 */
export function createMarks(layer, presence, { markers = false, edges = null, popups = {}, messages = {} } = {}) {
  const views = new Map();
  const kinds = Object.keys(popups);

  /** Where the arrow of a pointer scrolled out of its pane goes, or null: a pointer seen needs none. */
  function edgeOf(peer, visible) {
    return !visible && edges ? offscreenAnchor(peer.anchor, edges) : null;
  }

  function drawEdge(peer, visible) {
    placeEdge(peer, edgeOf(peer, visible));
  }

  /** Draws the arrow `edgeOf()` measured, or hides it. */
  function placeEdge(peer, edge) {
    if (!edge) {
      if (peer.edge) peer.edge.hidden = true;
      return;
    }
    if (!peer.edge) {
      peer.edge = document.createElement('div');
      peer.edge.className = 'collaborate-edge';
      peer.edge.dataset.userId = peer.userId;
      layer.appendChild(peer.edge);
    }
    const label = presence.users.get(peer.userId)?.label ?? peer.label;
    peer.edge.dataset.side = edge.side;
    peer.edge.dataset.color = presence.colorOf(peer.userId) ?? peer.color;
    peer.edge.textContent = initialOf(label);
    peer.edge.title = label;
    peer.edge.style.transform = `translate3d(${edge.x}px, ${edge.y}px, 0)`;
    peer.edge.hidden = false;
  }

  function dropEdge(peer) {
    peer.edge?.remove();
    peer.edge = null;
  }

  /** Where a record is, read before any marker is drawn: a write between two reads lays the page out again. */
  function measureMarker(key) {
    const record = findRecord(key);
    const box = record && seen(record) && record.getBoundingClientRect();
    if (!box) return null;
    // The peers at the end of a row's first cell, where it leaves room, or in the corner of a tile.
    const cell = record.localName === 'tr' && record.querySelector(':scope > td');
    return { box, row: !!cell, end: (cell ? cell.getBoundingClientRect().right : box.right) - box.left };
  }

  /** A record marked with the peers on it, where the page shows it. */
  function drawMarker(key, ids, place) {
    let view = views.get(key);
    if (!view) {
      const element = document.createElement('div');
      element.className = 'collaborate-marker';
      const bar = document.createElement('span');
      bar.className = 'collaborate-marker__bar';
      const readers = document.createElement('span');
      readers.className = 'collaborate-marker__users';
      element.append(bar, readers);
      layer.appendChild(element);
      view = { element, bar, readers, drawn: '' };
      views.set(key, view);
    }
    view.element.hidden = !place;
    if (!place) return;
    const { box } = place;
    view.element.style.transform = `translate3d(${box.left}px, ${box.top}px, 0)`;
    view.element.style.width = `${box.width}px`;
    view.element.style.height = `${box.height}px`;
    view.element.dataset.shape = place.row ? 'row' : 'tile';
    view.element.style.setProperty('--marker-end', `${place.end}px`);
    const colors = ids.map((id) => presence.colorOf(id));
    const drawn = JSON.stringify(ids.map((id, index) => [colors[index], presence.users.get(id).label]));
    if (drawn === view.drawn) return;
    view.drawn = drawn;
    view.element.dataset.color = colors[0];
    view.bar.dataset.color = colors[0];
    const initials = ids.slice(0, MARKED).map((id, index) => {
      const initial = document.createElement('span');
      initial.className = 'collaborate-marker__user';
      initial.dataset.color = colors[index];
      initial.textContent = initialOf(presence.users.get(id).label);
      return initial;
    });
    if (ids.length > MARKED) {
      const more = document.createElement('span');
      more.className = 'collaborate-marker__more';
      more.textContent = `+${ids.length - MARKED}`;
      initials.push(more);
    }
    view.readers.replaceChildren(...initials);
    view.element.title = ids.map((id) => presence.users.get(id).label).join(', ');
  }

  /** Marks the records peers point at or have a popup of, and unmarks the others. */
  function drawMarkers() {
    if (!markers) return;
    const records = new Map();
    for (const [id, user] of [...presence.users].sort(([a], [b]) => a - b))
      for (const key of new Set([user.popup?.key, user.file]))
        if (key) records.set(key, [...(records.get(key) ?? []), id]);
    for (const [key, view] of views) {
      if (records.has(key)) continue;
      view.element.remove();
      views.delete(key);
    }
    const places = new Map([...records.keys()].map((key) => [key, measureMarker(key)]));
    for (const [key, ids] of records) drawMarker(key, ids, places.get(key));
  }

  function clear() {
    for (const view of views.values()) view.element.remove();
    views.clear();
  }

  /**
   * Says in a popup who else has it open, in the host's placeholder (`[data-collaborate-popup-presence]` in the
   * popup's dialog) or at the top of the popup.
   */
  function drawPopupLine(active) {
    const mine = active ? capturePopup(kinds) : null;
    const open = mine && document.querySelector(`[data-collaborate-popup="${CSS.escape(mine.kind)}"]`);
    const container = open?.closest('dialog, .modal') ?? open;
    for (const line of /** @type {NodeListOf<HTMLElement>} */ (
      document.querySelectorAll('[data-collaborate-popup-line]')
    )) {
      if (container?.contains(line)) continue;
      // A placeholder of the host is emptied, a line of the bundle removed.
      if (line.hasAttribute('data-collaborate-popup-presence')) {
        line.replaceChildren();
        line.removeAttribute('data-collaborate-popup-line');
        delete line.dataset.said;
      } else line.remove();
    }
    if (!mine || !container) return false;
    let line = /** @type {HTMLElement | null} */ (container.querySelector('[data-collaborate-popup-line]'));
    if (!line) {
      // There and empty a frame before anything is said in it: a status that comes with its text is read by no
      // screen reader.
      line = container.querySelector('[data-collaborate-popup-presence]');
      if (!line) {
        line = document.createElement('p');
        line.className = 'collaborate-popup-presence';
        open.prepend(line);
      }
      line.setAttribute('role', 'status');
      line.setAttribute('data-collaborate-popup-line', '');
      return true;
    }
    const others = [...presence.users].filter(([, user]) => samePopup(user.popup, mine)).sort(([a], [b]) => a - b);
    const said = others.length
      ? format(popups[mine.kind]?.line ?? messages.line, { names: others.map(([, user]) => user.label).join(', ') })
      : '';
    // Emptied rather than hidden, for the same reason: one shown with its text is not read either.
    if ((line.dataset.said ?? '') === said) return false;
    line.dataset.said = said;
    const initials = others.map(([id, user]) => {
      const initial = document.createElement('span');
      initial.className = 'collaborate-popup-presence__swatch';
      initial.dataset.color = presence.colorOf(id);
      initial.textContent = initialOf(user.label);
      initial.setAttribute('aria-hidden', 'true');
      return initial;
    });
    const text = document.createElement('span');
    text.textContent = said;
    line.replaceChildren(...(said ? [...initials, text] : []));
    return false;
  }

  /** Points an element out a moment: a peer found on the page. */
  function pulse(element, duration) {
    element.classList.add('collaborate-pulse');
    setTimeout(() => element.classList.remove('collaborate-pulse'), duration);
  }

  return { edgeOf, placeEdge, drawEdge, dropEdge, drawMarkers, drawPopupLine, clear, pulse, views };
}
