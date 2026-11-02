import { hubFor, layerConfig } from '../hub.js';
import { captureCaret, focusedField, MAX_CARET_OFFSET, resolveCaret, sameCaret } from './carets.js';
import {
  CURSOR_COLORS,
  CURSOR_SHAPE_NAMES,
  CURSOR_SHAPES,
  captureAnchor,
  capturePopup,
  configureGeometry,
  cursorStyle,
  findRecord,
  interpolate,
  isPopup,
  keyOf,
  pointerShape,
  regionNames,
  resolveAnchor,
  resolvePath,
  sameAnchor,
  samePopup,
  shapeHotspot,
} from './geometry.js';
import { createMarks, format } from './marks.js';
import { createPresence } from './presence.js';
import { createTurn } from './turn.js';

// The SVG box renders at 24 px for 32 viewBox units.
const SCALE = 0.75;
// Past this many peers, a tab sends less often, down to four times the throttle: a page costs the server about
// as many requests a second whatever the number of its peers.
export const CROWD = 3;
// The label of a pointer shows while it moves, and this long after, when pointers go idle (`cursors.idle`).
export const LABEL_DELAY = 2000;
// How long a peer found on the page is pointed out for.
export const PULSE = 1200;
// What the reader chose of the pointers of the others, in every tab (`presence: menu`).
export const POINTERS = 'collaborate.pointers';

const validField = (field) => field === null || (typeof field === 'string' && field !== '' && field.length <= 512);

function validCaret(caret) {
  return (
    caret === null ||
    (typeof caret === 'object' &&
      typeof caret.selector === 'string' &&
      caret.selector.length <= 1024 &&
      regionNames().includes(caret.region) &&
      [caret.start, caret.end].every((offset) => Number.isInteger(offset) && offset >= 0 && offset <= MAX_CARET_OFFSET))
  );
}

/** The room a grant is to: the same one given again (a page that loaded itself again) keeps its stream. */
const roomOf = (grant) => grant?.split('.')[3] ?? null;

function readPointers() {
  try {
    return localStorage.getItem(POINTERS) !== 'hidden';
  } catch {
    return true;
  }
}

function writePointers(shown) {
  try {
    localStorage.setItem(POINTERS, shown ? 'shown' : 'hidden');
  } catch {
    // A browser that keeps nothing forgets the choice with the page.
  }
}

// Captured when the module is evaluated: cursor traffic is background traffic, and a host that wraps
// `window.fetch` (a loading indicator, say) must load easyadmin.js before installing its wrapper.
const backgroundFetch = window.fetch.bind(window);
const instances = new WeakMap();

/** The cursors of a layer, once started: a host enters another place through `enter()`. */
export function cursorsFor(layer = document.querySelector('[data-collaborate-layer]')) {
  return (layer && instances.get(layer)) ?? null;
}

export function initCursors(
  layer = document.querySelector('[data-collaborate-layer]'),
  fetcher = backgroundFetch,
  hub = hubFor(layer, fetcher),
  presence = createPresence(undefined, layerConfig(layer).maxAvatars, layerConfig(layer)),
  turn = createTurn()
) {
  // A layer without an event URL serves the other modules only: cursors are disabled.
  if (!layer?.dataset.eventUrl || layer.dataset.initialized) return null;
  layer.dataset.initialized = 'true';
  const { eventUrl } = layer.dataset;
  const config = layerConfig(layer);
  configureGeometry(config);
  // The minimum time between two events sent, the heartbeat's period, and how long a silent peer stays.
  const throttle = config.throttle ?? 100;
  const heartbeatInterval = config.heartbeat ?? 5000;
  const expiry = config.expiry ?? 15000;
  const colors = Array.isArray(config.colors) ? config.colors : CURSOR_COLORS;
  const idle = config.idle ?? 0;
  const popups = config.popups ?? {};
  const kinds = Object.keys(popups);
  const messages = config.messages ?? {};
  const menu = config.presence === 'menu';
  // Where a reader alone points is told to nobody: a peer who comes says hello, and is answered.
  const silentAlone = config.silentAlone === true;
  const clientId = crypto.randomUUID();
  const peers = new Map();
  const versions = new Map();
  const removers = [];
  const fadeTimers = new Set();
  const marks = createMarks(layer, presence, {
    markers: config.markers === true,
    edges: config.edges ?? null,
    popups,
    messages,
  });
  let userId;
  let sequence = 0;
  let style = cursorStyle('mouse');
  let shape = 'default';
  let anchor = null;
  let caret = null;
  // The shared form control focused here: peers mark its field, a select's or a checkbox's as well.
  let field = null;
  // The popup of a record open here (`cursors.popups`).
  let popup = null;
  // Where the pointer last was; its shape and anchor are measured when an event is sent, not on every move.
  let lastPointer = null;
  let pointerDirty = false;
  let layoutDirty = true;
  let pending = null;
  let pendingHello = null;
  let sending = false;
  let lastSend = -Infinity;
  let sendTimer;
  let touchTimer;
  let leave;
  let animation;
  let stopped = false;
  // The server refused the page's grant: nothing is shared until the host enters a place again.
  let denied = false;
  // The page entered another room: what the stream says until it opens again is of the room it left.
  let entering = false;
  // How many times the page stopped listening: what was being sent before is of another time.
  let paused = 0;
  // Newest server timestamp received: freshness compares server clocks, never the client's own.
  let serverTime = -Infinity;
  // The pointers of the others are drawn, unless the reader chose not to see them.
  let shown = !menu || readPointers();
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  // Above an open modal dialog: a popover of the top layer, raised whenever a dialog opens.
  const topLayer = config.topLayer === true && typeof layer.showPopover === 'function';
  if (topLayer) layer.setAttribute('popover', 'manual');
  if (idle > 0) layer.dataset.labels = 'moving';
  else delete layer.dataset.labels;
  layer.dataset.hidden = String(!shown);
  // Where the menu says who a found peer is: a status of the page, there before its first message.
  const announcer = menu ? document.createElement('div') : null;
  if (announcer) {
    announcer.className = 'collaborate-announce';
    announcer.setAttribute('role', 'status');
    document.body.appendChild(announcer);
  }

  function raise() {
    if (!topLayer || !layer.isConnected) return;
    if (layer.matches(':popover-open')) layer.hidePopover();
    layer.showPopover();
  }
  raise();

  // The grant and CSRF token last refused: the hub renews them on the layer once, a second refusal stops.
  let refused = null;

  async function request(url, body, keepalive = false, grant = layer.dataset.grant) {
    const { csrf } = layer.dataset;
    const response = await fetcher(url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ grant, ...body }),
    });
    // The grant of a room already left (its departure) refuses nothing.
    if (([401, 403].includes(response.status) || response.redirected) && grant === layer.dataset.grant) {
      const credentials = `${grant} ${csrf}`;
      if (!hub || refused === credentials) refuse();
      else if (credentials === `${layer.dataset.grant} ${layer.dataset.csrf}`) {
        refused = credentials;
        // The session the hub requests next is refused alike, renews them, or stops every member.
        hub.reconnect();
      }
    }
    return response;
  }

  async function flush() {
    if (sending || (!pending && !pendingHello) || stopped || denied) return;
    const wait = Math.min(throttle * 4, throttle * Math.max(1, presence.users.size / CROWD));
    const delay = wait - (performance.now() - lastSend);
    if (delay > 0) {
      clearTimeout(sendTimer);
      sendTimer = setTimeout(flush, delay);
      return;
    }
    const event = build(pendingHello || pending);
    if (pendingHello) pendingHello = null;
    else pending = null;
    sending = true;
    lastSend = performance.now();
    const pauses = paused;
    let lost = false;
    try {
      const response = await request(eventUrl, event);
      if (!response.ok) {
        lastSend = performance.now() + 900;
        // What the server refuses it would refuse again: a busy one is asked again.
        lost = response.status === 429 || response.status >= 500;
      }
    } catch {
      lastSend = performance.now() + 900;
      lost = true;
    } finally {
      // Peers hear of a page alone by its hello: one that was lost is sent again behind the backoff, unless the
      // page left meanwhile or has no stream (the stream that opens again says hello itself).
      if (silentAlone && lost && event.kind === 'hello' && pauses === paused && layer.dataset.connected === 'true')
        pendingHello ??= 'hello';
      sending = false;
      if (pending || pendingHello) flush();
    }
  }

  // `state` sends the pointer or caret, or hides them; `reply` answers a hello with them, or a heartbeat.
  function publish(kind) {
    if (stopped || denied || !userId || !navigator.onLine) return;
    if (silentAlone && !presence.users.size && kind !== 'hello' && kind !== 'reply') return;
    if (kind === 'hello') pendingHello = kind;
    else pending = kind;
    flush();
  }

  function capture() {
    if (!pointerDirty || !lastPointer) return;
    pointerDirty = false;
    const { x, y } = lastPointer;
    // After a scroll or a resize, the pointer stands over whatever moved under it.
    const target = lastPointer.target?.isConnected ? lastPointer.target : document.elementFromPoint(x, y);
    shape = pointerShape(target, x, y);
    anchor = captureAnchor(target, x, y);
  }

  function build(kind) {
    capture();
    const visible = anchor || caret || field || popup;
    const resolved = { state: visible ? 'move' : 'hide', reply: visible ? 'move' : 'heartbeat' }[kind] ?? kind;
    const event = { clientId, sequence: ++sequence, kind: resolved, style, shape, anchor, caret, field };
    if (kinds.length) event.popup = popup;
    return event;
  }

  // The pointer leaves; the caret stays while its field keeps focus.
  function hide() {
    anchor = null;
    lastPointer = null;
    pointerDirty = false;
    clearTimeout(touchTimer);
    publish('hide');
  }

  function updateCaret() {
    const next = captureCaret();
    const nextField = focusedField();
    if (sameCaret(next, caret) && nextField === field) return;
    caret = next;
    field = nextField;
    publish('state');
  }

  /** A popup opens on a record, or closes: peers are told, whether or not a pointer moves. */
  function updatePopup() {
    if (!kinds.length) return;
    const next = capturePopup(kinds);
    if (samePopup(next, popup)) return;
    popup = next;
    publish('state');
  }

  function makeCursor(userId) {
    const element = document.createElement('div');
    element.className = 'collaborate-cursor';
    element.dataset.userId = userId;
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 32 36');
    const path = document.createElementNS(icon.namespaceURI, 'path');
    icon.appendChild(path);
    const label = document.createElement('span');
    label.className = 'collaborate-cursor__label';
    element.append(icon, label);
    layer.appendChild(element);
    return { element, icon, path, label, timer: null };
  }

  function makeCaret(userId) {
    const element = document.createElement('div');
    element.className = 'collaborate-caret';
    element.dataset.userId = userId;
    const selection = document.createElement('div');
    selection.className = 'collaborate-caret__selection';
    const bar = document.createElement('div');
    bar.className = 'collaborate-caret__bar';
    const label = document.createElement('span');
    label.className = 'collaborate-caret__label';
    bar.appendChild(label);
    element.append(selection, bar);
    layer.appendChild(element);
    return { element, selection, bar, label, timer: null };
  }

  function dropPointer(peer) {
    const element = peer.cursor?.element;
    clearTimeout(peer.cursor?.timer);
    marks.dropEdge(peer);
    peer.cursor = null;
    peer.point = null;
    peer.to = null;
    if (element?.dataset.style !== 'touch') {
      element?.remove();
      return;
    }
    element.classList.add('collaborate-cursor--fading');
    const timer = setTimeout(() => {
      element.remove();
      fadeTimers.delete(timer);
    }, 200);
    fadeTimers.add(timer);
  }

  function dropCaret(peer) {
    clearTimeout(peer.caretView?.timer);
    peer.caretView?.element.remove();
    peer.caretView = null;
    peer.caret = null;
  }

  function removePeer(id) {
    const peer = peers.get(id);
    if (!peer) return;
    dropPointer(peer);
    dropCaret(peer);
    peers.delete(id);
    markFields();
  }

  // Each peer's focused field, outlined in its colour and named; a replay may have redrawn the field since.
  function markFields() {
    const marked = new Map();
    for (const peer of peers.values()) {
      if (!peer.field) continue;
      for (const form of document.querySelectorAll('form[data-collaborate]')) {
        const found = form.elements.namedItem(peer.field);
        const control = found instanceof RadioNodeList ? found[0] : found;
        const group = control?.closest('.form-group') || control;
        if (!group) continue;
        if (!marked.has(group)) marked.set(group, []);
        marked.get(group).push(peer);
      }
    }
    for (const element of document.querySelectorAll('[data-peer-focus]')) {
      if (!marked.has(element)) {
        element.removeAttribute('data-peer-focus');
        element.removeAttribute('data-peer-focus-label');
      }
    }
    for (const [group, list] of marked) {
      group.dataset.peerFocus = list[0].color;
      group.dataset.peerFocusLabel = list.map((peer) => peer.label).join(', ');
    }
  }

  // The name flag shows while the caret changes, then fades.
  function flagCaret(view) {
    view.element.classList.add('collaborate-caret--labelled');
    clearTimeout(view.timer);
    view.timer = setTimeout(() => view.element.classList.remove('collaborate-caret--labelled'), 2000);
  }

  // The name of a pointer shows while it moves, then fades (`cursors.idle`).
  function flagPointer(view) {
    view.element.classList.add('collaborate-cursor--labelled');
    clearTimeout(view.timer);
    view.timer = setTimeout(() => view.element.classList.remove('collaborate-cursor--labelled'), LABEL_DELAY);
  }

  // The colour of the peer on this page, which the presence saw as the event came.
  const colorOf = (event) => presence.colorOf(event.userId);

  function validAnchor(anchor) {
    return (
      !anchor ||
      (typeof anchor?.selector === 'string' &&
        anchor.selector.length <= 1024 &&
        regionNames().includes(anchor.region) &&
        Number.isFinite(anchor.x) &&
        Number.isFinite(anchor.y) &&
        anchor.x >= 0 &&
        anchor.x <= 1 &&
        anchor.y >= 0 &&
        anchor.y <= 1)
    );
  }

  function receive(message) {
    if (entering) return;
    let event;
    try {
      event = JSON.parse(message.data);
    } catch {
      return;
    }
    if (
      !event ||
      event.userId === userId ||
      !Number.isInteger(event.userId) ||
      typeof event.clientId !== 'string' ||
      !Number.isInteger(event.sequence) ||
      !['move', 'hide', 'heartbeat', 'hello', 'leave'].includes(event.kind) ||
      !colors.includes(event.color) ||
      typeof event.label !== 'string' ||
      !(event.avatar == null || typeof event.avatar === 'string') ||
      !['mac', 'windows', 'ubuntu', 'touch'].includes(event.style) ||
      !CURSOR_SHAPE_NAMES.includes(event.shape) ||
      !validCaret(event.caret ?? null) ||
      !validField(event.field ?? null) ||
      !(event.popup == null || isPopup(event.popup, kinds)) ||
      !Number.isFinite(event.timestamp) ||
      event.timestamp < serverTime - expiry ||
      !validAnchor(event.anchor)
    )
      return;
    serverTime = Math.max(serverTime, event.timestamp);
    const versionKey = `${event.userId}:${event.clientId}`;
    if ((versions.get(versionKey)?.sequence ?? -1) >= event.sequence) return;
    versions.set(versionKey, { sequence: event.sequence, seen: Date.now() });
    const away = ['hide', 'leave'].includes(event.kind);
    const previous = peers.get(event.userId);
    // A peer moves when their pointer or caret goes somewhere; the answer to a hello says again where they are.
    const moving =
      event.kind === 'move' &&
      (!sameAnchor(previous?.anchor ?? null, event.anchor ?? null) ||
        !sameCaret(previous?.caret ?? null, event.caret ?? null));
    // Presence outlives the pointer: only a departure, or expiry, takes a tab off the page.
    if (event.kind === 'leave') presence.leave(event.userId, event.clientId);
    else
      presence.see({
        ...event,
        file: away ? null : keyOf(event.anchor),
        popup: event.popup ?? null,
        moving,
      });
    if (event.kind === 'hello') {
      publish('reply');
      return;
    }
    // Only movement may steal ownership from another tab. Its hide/heartbeat cannot erase the active tab.
    if (previous && previous.clientId !== event.clientId && event.kind !== 'move') return;
    if (previous && previous.timestamp > event.timestamp) return;
    const pointer = away ? null : event.anchor;
    const remoteCaret = event.caret ?? null;
    // Like the caret, the field stays while the pointer is elsewhere.
    const remoteField = event.field ?? null;
    if (!pointer && !remoteCaret && !remoteField) {
      removePeer(event.userId);
      return;
    }
    const peer = previous || { cursor: null, caretView: null, caret: null, point: null, edge: null, moved: Date.now() };
    Object.assign(peer, {
      userId: event.userId,
      clientId: event.clientId,
      timestamp: event.timestamp,
      seen: Date.now(),
      anchor: pointer,
      field: remoteField,
      color: colorOf(event),
      label: event.label,
    });
    if (moving) peer.moved = Date.now();
    if (pointer) {
      peer.cursor ||= makeCursor(event.userId);
      const { element, icon, path, label } = peer.cursor;
      element.dataset.region = pointer.region;
      element.dataset.color = peer.color;
      element.dataset.style = event.style;
      element.dataset.shape = event.shape;
      if (event.style === 'touch') {
        path.setAttribute('d', 'M16 2a14 14 0 1 0 0 28a14 14 0 1 0 0-28Z');
        path.removeAttribute('transform');
        icon.style.translate = '';
      } else {
        const drawn = CURSOR_SHAPES[event.style][event.shape];
        const [x, y] = shapeHotspot(drawn);
        path.setAttribute('d', drawn.d);
        if (drawn.rotate) path.setAttribute('transform', `rotate(${drawn.rotate} 16 16)`);
        else path.removeAttribute('transform');
        icon.style.translate = `${(-x * SCALE).toFixed(2)}px ${(-y * SCALE).toFixed(2)}px`;
      }
      label.textContent = event.label;
      if (moving && idle > 0) {
        element.classList.remove('collaborate-cursor--idle');
        flagPointer(peer.cursor);
      }
      const target = resolveAnchor(pointer);
      if (target) {
        peer.from = peer.point || target;
        peer.to = target;
        peer.started = performance.now();
      }
    } else dropPointer(peer);
    if (remoteCaret) {
      const created = !peer.caretView;
      peer.caretView ||= makeCaret(event.userId);
      peer.caretView.element.dataset.color = peer.color;
      peer.caretView.label.textContent = event.label;
      if (created || !sameCaret(peer.caret, remoteCaret)) {
        flagCaret(peer.caretView);
        peer.caretDirty = true;
      }
      peer.caret = remoteCaret;
    } else dropCaret(peer);
    peers.set(event.userId, peer);
    markFields();
    redraw();
  }

  // A frame that only animates a pointer measures nothing: carets and anchors move with layout and events.
  function draw(now) {
    animation = null;
    const layout = layoutDirty;
    layoutDirty = false;
    let moving = false;
    if (layout) {
      markFields();
      if (kinds.length && marks.drawPopupLine(!denied)) redraw();
      if (shown) marks.drawMarkers();
    }
    if (!shown) return;
    for (const peer of peers.values()) {
      if (peer.caretView && (layout || peer.caretDirty)) drawCaret(peer.caretView, resolveCaret(peer.caret, layer));
      peer.caretDirty = false;
      if (!peer.cursor) continue;
      const { element } = peer.cursor;
      const target = layout || !peer.to ? resolveAnchor(peer.anchor) : peer.to;
      element.hidden = !target;
      if (layout) marks.drawEdge(peer, !!target);
      if (!target) continue;
      if (!peer.to || target.x !== peer.to.x || target.y !== peer.to.y) {
        peer.from = peer.point || target;
        peer.to = target;
        peer.started = now;
      }
      peer.point = interpolate(peer.from, peer.to, now - peer.started, reducedMotion.matches);
      element.dataset.edge = peer.point.x > innerWidth - 240 ? 'right' : 'left';
      element.dataset.bottom = String(peer.point.y > innerHeight - 55);
      element.style.transform = `translate3d(${peer.point.x}px, ${peer.point.y}px, 0)`;
      moving ||= peer.point.x !== peer.to.x || peer.point.y !== peer.to.y;
    }
    if (moving) redraw(false);
  }

  // Carets snap: typing moves them by a glyph, which interpolation would only blur.
  function drawCaret(view, geometry) {
    view.element.hidden = !geometry;
    if (!geometry) return;
    const { bar, rects } = geometry;
    view.bar.style.transform = `translate3d(${bar.x}px, ${bar.y}px, 0)`;
    view.bar.style.height = `${bar.height}px`;
    view.element.dataset.edge = bar.x > innerWidth - 240 ? 'right' : 'left';
    view.element.dataset.top = String(bar.y < 24);
    while (view.selection.children.length > rects.length) view.selection.lastChild.remove();
    while (view.selection.children.length < rects.length) view.selection.appendChild(document.createElement('div'));
    rects.forEach((rect, index) => {
      const tint = view.selection.children[index];
      tint.style.transform = `translate3d(${rect.left}px, ${rect.top}px, 0)`;
      tint.style.width = `${rect.width}px`;
      tint.style.height = `${rect.height}px`;
    });
  }

  function redraw(layout = true) {
    layoutDirty ||= layout;
    if (!animation && !stopped) animation = requestAnimationFrame(draw);
  }

  // The page's one Mercure stream carries the cursor topic while the page is visible; over HTTP/1.1 with
  // `share_stream`, while the page holds its turn among the tabs of the browser.
  function connect() {
    if (stopped || denied || leave || document.hidden || !navigator.onLine || !hub) return;
    const pauses = paused;
    const join = () => {
      if (pauses !== paused || stopped || denied || leave || document.hidden) return;
      leave = hub.join({
        channel: 'cursors',
        receive,
        opened(session) {
          userId = session.userId;
          entering = false;
          layer.dataset.connected = 'true';
          publish('hello');
          if (anchor || caret || field || popup || lastPointer) publish('state');
        },
        closed() {
          layer.dataset.connected = 'false';
        },
        denied: refuse,
      });
    };
    if (config.shareStream) turn.take().then(join, () => {});
    else join();
  }

  function disconnect() {
    leave?.();
    leave = null;
  }

  function listen(target, name, callback, options) {
    target.addEventListener(name, callback, options);
    removers.push(() => target.removeEventListener(name, callback, options));
  }

  function move(event) {
    clearTimeout(touchTimer);
    // A finger is captured by what it touched first: what it moves onto is found where it is.
    const target = event.pointerType === 'touch' ? null : event.target;
    lastPointer = { x: event.clientX, y: event.clientY, target };
    pointerDirty = true;
    style = cursorStyle(event.pointerType);
    publish('state');
  }

  listen(document, 'pointermove', move, { passive: true });
  listen(document, 'pointerdown', move, { passive: true });
  listen(document, 'pointerup', (event) => {
    if (event.pointerType === 'touch') {
      clearTimeout(touchTimer);
      touchTimer = setTimeout(hide, 400);
    }
  });
  listen(document, 'pointercancel', hide);
  // A drag cancels the pointer, whose moves are then the drag's, told again and again where it rests.
  listen(
    document,
    'dragover',
    (event) => {
      if (lastPointer?.x === event.clientX && lastPointer?.y === event.clientY) return;
      lastPointer = { x: event.clientX, y: event.clientY, target: null };
      pointerDirty = true;
      publish('state');
    },
    { passive: true }
  );
  listen(document.documentElement, 'pointerleave', (event) => {
    if (event.pointerType !== 'touch') hide();
  });
  function refreshLayout() {
    redraw();
    if (lastPointer) {
      lastPointer = { x: lastPointer.x, y: lastPointer.y };
      pointerDirty = true;
      publish('state');
    }
  }
  listen(document, 'scroll', refreshLayout, { capture: true, passive: true });
  listen(window, 'resize', refreshLayout);
  listen(document, 'selectionchange', updateCaret);
  listen(document, 'focusin', updateCaret);
  // Focus has not moved on yet while focusout runs.
  listen(document, 'focusout', () => queueMicrotask(updateCaret));
  // Typing, and a peer's replayed edit, shift both the own selection and every drawn caret.
  listen(
    document,
    'input',
    () => {
      updateCaret();
      redraw();
    },
    { capture: true }
  );
  // The turn among the tabs is kept by a page that goes to another place, and given up otherwise.
  function pause({ keepTurn = false } = {}) {
    if (!keepTurn) turn.give();
    paused++;
    disconnect();
    clearTimeout(sendTimer);
    pending = null;
    pendingHello = null;
    layer.dataset.connected = 'false';
    for (const id of [...peers.keys()]) removePeer(id);
    presence.clear();
    marks.clear();
    if (kinds.length) marks.drawPopupLine(false);
  }
  // The server refused the page's place: nothing is shared until the host enters one again.
  function refuse() {
    denied = true;
    pause();
  }
  listen(window, 'offline', () => pause());
  listen(window, 'online', connect);
  listen(document, 'visibilitychange', () => {
    if (document.hidden) {
      anchor = null;
      lastPointer = null;
      caret = null;
      field = null;
      clearTimeout(touchTimer);
      depart();
      pause();
    } else {
      caret = captureCaret();
      field = focusedField();
      connect();
    }
  });
  // Sent at once, past the throttle that pause() and stop() would cancel.
  // Best effort only: heartbeat expiry covers interrupted navigation and browser crashes.
  function depart(grant = layer.dataset.grant) {
    if (userId && !denied)
      request(
        eventUrl,
        { clientId, sequence: ++sequence, kind: 'leave', style, shape, anchor: null, caret: null, field: null },
        true,
        grant
      ).catch(() => {});
  }
  // A page the back/forward cache keeps leaves like a hidden tab and joins again when it is restored.
  listen(window, 'pagehide', (event) => {
    depart();
    if (!event.persisted) {
      stop();
      return;
    }
    anchor = null;
    lastPointer = null;
    caret = null;
    field = null;
    clearTimeout(touchTimer);
    pause();
  });
  listen(window, 'pageshow', (event) => {
    if (!event.persisted) return;
    caret = captureCaret();
    field = focusedField();
    connect();
  });
  const observer = new MutationObserver((records) => {
    // A modal dialog that opens is drawn in the top layer, above the pointers, which are raised above it.
    if (records.some((record) => record.target.localName === 'dialog' && record.attributeName === 'open')) raise();
    if (records.some((record) => !layer.contains(record.target))) {
      updatePopup();
      redraw();
    }
  });
  observer.observe(document.body, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['class', 'style', 'hidden', 'open'],
  });
  const resizer = new ResizeObserver(redraw);
  resizer.observe(document.body);
  const heartbeat = setInterval(() => {
    for (const [id, peer] of peers) {
      if (Date.now() - peer.seen > expiry) removePeer(id);
      // A pointer left where it is for a while is dimmed, its peer away from it.
      else if (idle > 0)
        peer.cursor?.element.classList.toggle('collaborate-cursor--idle', Date.now() - peer.moved > idle);
    }
    const known = presence.users.size;
    presence.expire(Date.now(), expiry);
    // Peers who stopped hearing each other forget each other, and a reader alone tells nothing: a hello is what
    // finds them again.
    if (silentAlone && known && !presence.users.size && layer.dataset.connected === 'true') publish('hello');
    for (const [id, version] of versions) {
      if (Date.now() - version.seen > 2 * expiry) versions.delete(id);
    }
    if (!document.hidden) publish('heartbeat');
  }, heartbeatInterval);

  /**
   * Shares in the place another grant is to, without loading the page: a host that replaces its content (a
   * listing loaded in place of another) renders `collaborate_grant()` there and enters it. A grant to the same
   * room keeps the stream open.
   */
  function enter(grant) {
    if (stopped || typeof grant !== 'string' || grant === '') return;
    const room = roomOf(grant);
    if (room && room === roomOf(layer.dataset.grant)) {
      layer.dataset.grant = grant;
      if (denied) {
        denied = false;
        connect();
      }
      // The content loaded again may have moved what the pointer rests on, once the host scrolled it back.
      queueMicrotask(refreshLayout);
      return;
    }
    depart();
    pause({ keepTurn: true });
    layer.dataset.grant = grant;
    denied = false;
    refused = null;
    anchor = null;
    lastPointer = null;
    caret = captureCaret();
    field = focusedField();
    popup = kinds.length ? capturePopup(kinds) : null;
    entering = true;
    connect();
  }

  /** Where a peer is, as the menu says it: in a record's popup, on a record, or on the page. */
  function describe(user) {
    const nameOf = (key) => findRecord(key)?.dataset.collaborateLabel || null;
    const opened = user.popup && nameOf(user.popup.key);
    if (opened) return format(popups[user.popup.kind]?.where ?? messages.where, { name: opened });
    const pointed = user.file && nameOf(user.file);
    return pointed ? format(messages.on, { name: pointed }) : (messages.here ?? '');
  }

  function announce(message) {
    if (!announcer) return;
    // Emptied first: the same sentence twice is said twice.
    announcer.textContent = '';
    setTimeout(() => {
      announcer.textContent = message;
    }, 50);
  }

  /** Finds a peer on the page: what they point at, or their popup's record, scrolled to, pointed out and said. */
  function findReader(id) {
    const user = presence.users.get(id);
    if (!user) return;
    const peer = peers.get(id);
    const key = user.popup?.key ?? user.file;
    const target = (peer?.anchor && resolvePath(peer.anchor)) || (key && findRecord(key)) || null;
    target?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    if (peer?.cursor) {
      flagPointer(peer.cursor);
      marks.pulse(peer.cursor.element, PULSE);
    }
    const marker = key && marks.views.get(key);
    if (marker) marks.pulse(marker.element, PULSE);
    announce(format(messages.is ?? '%name% is %place%', { name: user.label, place: describe(user) }));
    redraw();
  }

  /** Shows the pointers of the others, or hides them: what the reader chose, in every tab. */
  function show(next) {
    shown = next;
    layer.dataset.hidden = String(!shown);
    if (!shown) marks.clear();
    presence.render();
    redraw();
  }
  if (menu)
    listen(window, 'storage', (event) => {
      if (event.key === POINTERS) show(event.newValue !== 'hidden');
    });

  presence.bind({
    describe,
    choose: findReader,
    toggle: () => {
      writePointers(!shown);
      show(!shown);
    },
    shown: () => shown,
    change: redraw,
  });

  const onEnter = (event) => enter(event.detail?.grant);
  listen(document, 'collaborate:enter', onEnter);

  function stop() {
    stopped = true;
    turn.give();
    disconnect();
    clearInterval(heartbeat);
    for (const id of [...peers.keys()]) removePeer(id);
    presence.clear();
    presence.dispose();
    marks.clear();
    for (const timer of fadeTimers) clearTimeout(timer);
    for (const timer of [sendTimer, touchTimer]) clearTimeout(timer);
    cancelAnimationFrame(animation);
    observer.disconnect();
    resizer.disconnect();
    for (const remove of removers) remove();
    announcer?.remove();
    layer.replaceChildren();
    layer.dataset.connected = 'false';
    instances.delete(layer);
  }

  connect();
  const cursors = { stop, enter, receive, publish, findReader, peers, presence };
  instances.set(layer, cursors);
  return cursors;
}

if (document.readyState === 'loading')
  document.addEventListener('DOMContentLoaded', () => initCursors(), { once: true });
else initCursors();
