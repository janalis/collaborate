import { hubFor, layerConfig } from '../hub.js';
import { captureCaret, focusedField, MAX_CARET_OFFSET, resolveCaret, sameCaret } from './carets.js';
import {
  CURSOR_COLORS,
  CURSOR_SHAPE_NAMES,
  CURSOR_SHAPES,
  captureAnchor,
  cursorStyle,
  interpolate,
  pointerShape,
  resolveAnchor,
  shapeHotspot,
} from './geometry.js';
import { createPresence } from './presence.js';

const REGIONS = ['header', 'mobile-header', 'sidebar', 'content', 'modal', 'body'];
// The SVG box renders at 24 px for 32 viewBox units.
const SCALE = 0.75;

const validField = (field) => field === null || (typeof field === 'string' && field !== '' && field.length <= 512);

function validCaret(caret) {
  return (
    caret === null ||
    (typeof caret === 'object' &&
      typeof caret.selector === 'string' &&
      caret.selector.length <= 1024 &&
      REGIONS.includes(caret.region) &&
      [caret.start, caret.end].every((offset) => Number.isInteger(offset) && offset >= 0 && offset <= MAX_CARET_OFFSET))
  );
}

// Captured when the module is evaluated: cursor traffic is background traffic, and a host that wraps
// `window.fetch` (a loading indicator, say) must load easyadmin.js before installing its wrapper.
const backgroundFetch = window.fetch.bind(window);

export function initCursors(
  layer = document.querySelector('[data-collaborate-layer]'),
  fetcher = backgroundFetch,
  hub = hubFor(layer, fetcher),
  presence = createPresence(undefined, layerConfig(layer).maxAvatars)
) {
  // A layer without an event URL serves the other modules only: cursors are disabled.
  if (!layer?.dataset.eventUrl || layer.dataset.initialized) return null;
  layer.dataset.initialized = 'true';
  const { eventUrl } = layer.dataset;
  const config = layerConfig(layer);
  // The minimum time between two events sent, the heartbeat's period, and how long a silent peer stays.
  const throttle = config.throttle ?? 100;
  const heartbeatInterval = config.heartbeat ?? 5000;
  const expiry = config.expiry ?? 15000;
  const colors = Array.isArray(config.colors) ? config.colors : CURSOR_COLORS;
  const clientId = crypto.randomUUID();
  const peers = new Map();
  const versions = new Map();
  const removers = [];
  const fadeTimers = new Set();
  let userId;
  let sequence = 0;
  let style = cursorStyle('mouse');
  let shape = 'default';
  let anchor = null;
  let caret = null;
  // The shared form control focused here: peers mark its field, a select's or a checkbox's as well.
  let field = null;
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
  // Newest server timestamp received: freshness compares server clocks, never the client's own.
  let serverTime = -Infinity;
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

  // The grant and CSRF token last refused: the hub renews them on the layer once, a second refusal stops.
  let refused = null;

  async function request(url, body, keepalive = false) {
    const { grant, csrf } = layer.dataset;
    const response = await fetcher(url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ grant, ...body }),
    });
    if ([401, 403].includes(response.status) || response.redirected) {
      const credentials = `${grant} ${csrf}`;
      if (!hub || refused === credentials) stop();
      else if (credentials === `${layer.dataset.grant} ${layer.dataset.csrf}`) {
        refused = credentials;
        // The session the hub requests next is refused alike, renews them, or stops every member.
        hub.reconnect();
      }
    }
    return response;
  }

  async function flush() {
    if (sending || (!pending && !pendingHello) || stopped) return;
    const delay = throttle - (performance.now() - lastSend);
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
    try {
      const response = await request(eventUrl, event);
      if (!response.ok) lastSend = performance.now() + 900;
    } catch {
      lastSend = performance.now() + 900;
    } finally {
      sending = false;
      if (pending || pendingHello) flush();
    }
  }

  // `state` sends the pointer or caret, or hides them; `reply` answers a hello with them, or a heartbeat.
  function publish(kind) {
    if (stopped || !userId || !navigator.onLine) return;
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
    const visible = anchor || caret || field;
    const resolved = { state: visible ? 'move' : 'hide', reply: visible ? 'move' : 'heartbeat' }[kind] ?? kind;
    return { clientId, sequence: ++sequence, kind: resolved, style, shape, anchor, caret, field };
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
    return { element, icon, path, label };
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
    const marks = new Map();
    for (const peer of peers.values()) {
      if (!peer.field) continue;
      for (const form of document.querySelectorAll('form[data-collaborate]')) {
        const found = form.elements.namedItem(peer.field);
        const control = found instanceof RadioNodeList ? found[0] : found;
        const group = control?.closest('.form-group') || control;
        if (!group) continue;
        if (!marks.has(group)) marks.set(group, []);
        marks.get(group).push(peer);
      }
    }
    for (const element of document.querySelectorAll('[data-peer-focus]')) {
      if (!marks.has(element)) {
        element.removeAttribute('data-peer-focus');
        element.removeAttribute('data-peer-focus-label');
      }
    }
    for (const [group, marked] of marks) {
      group.dataset.peerFocus = marked[0].color;
      group.dataset.peerFocusLabel = marked.map((peer) => peer.label).join(', ');
    }
  }

  // The name flag shows while the caret changes, then fades.
  function flagCaret(view) {
    view.element.classList.add('collaborate-caret--labelled');
    clearTimeout(view.timer);
    view.timer = setTimeout(() => view.element.classList.remove('collaborate-caret--labelled'), 2000);
  }

  function receive(message) {
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
      !Number.isFinite(event.timestamp) ||
      event.timestamp < serverTime - expiry
    )
      return;
    if (
      event.anchor &&
      (typeof event.anchor?.selector !== 'string' ||
        event.anchor.selector.length > 1024 ||
        !REGIONS.includes(event.anchor.region) ||
        !Number.isFinite(event.anchor.x) ||
        !Number.isFinite(event.anchor.y) ||
        event.anchor.x < 0 ||
        event.anchor.x > 1 ||
        event.anchor.y < 0 ||
        event.anchor.y > 1)
    )
      return;
    serverTime = Math.max(serverTime, event.timestamp);
    const versionKey = `${event.userId}:${event.clientId}`;
    if ((versions.get(versionKey)?.sequence ?? -1) >= event.sequence) return;
    versions.set(versionKey, { sequence: event.sequence, seen: Date.now() });
    // Presence outlives the pointer: only a departure, or expiry, takes a tab off the page.
    if (event.kind === 'leave') presence.leave(event.userId, event.clientId);
    else presence.see(event);
    if (event.kind === 'hello') {
      publish('reply');
      return;
    }
    const previous = peers.get(event.userId);
    // Only movement may steal ownership from another tab. Its hide/heartbeat cannot erase the active tab.
    if (previous && previous.clientId !== event.clientId && event.kind !== 'move') return;
    if (previous && previous.timestamp > event.timestamp) return;
    const pointer = ['hide', 'leave'].includes(event.kind) ? null : event.anchor;
    const remoteCaret = event.caret ?? null;
    // Like the caret, the field stays while the pointer is elsewhere.
    const remoteField = event.field ?? null;
    if (!pointer && !remoteCaret && !remoteField) {
      removePeer(event.userId);
      return;
    }
    const peer = previous || { cursor: null, caretView: null, caret: null, point: null };
    Object.assign(peer, {
      clientId: event.clientId,
      timestamp: event.timestamp,
      seen: Date.now(),
      anchor: pointer,
      field: remoteField,
      color: event.color,
      label: event.label,
    });
    if (pointer) {
      peer.cursor ||= makeCursor(event.userId);
      const { element, icon, path, label } = peer.cursor;
      element.dataset.region = pointer.region;
      element.dataset.color = event.color;
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
      peer.caretView.element.dataset.color = event.color;
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
    if (layout) markFields();
    for (const peer of peers.values()) {
      if (peer.caretView && (layout || peer.caretDirty)) drawCaret(peer.caretView, resolveCaret(peer.caret, layer));
      peer.caretDirty = false;
      if (!peer.cursor) continue;
      const { element } = peer.cursor;
      const target = layout || !peer.to ? resolveAnchor(peer.anchor) : peer.to;
      element.hidden = !target;
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

  // The page's one Mercure stream carries the cursor topic while the page is visible.
  function connect() {
    if (stopped || leave || document.hidden || !navigator.onLine || !hub) return;
    leave = hub.join({
      channel: 'cursors',
      receive,
      opened(session) {
        userId = session.userId;
        layer.dataset.connected = 'true';
        publish('hello');
        if (anchor || caret || field || lastPointer) publish('state');
      },
      closed() {
        layer.dataset.connected = 'false';
      },
      denied: stop,
    });
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
    lastPointer = { x: event.clientX, y: event.clientY, target: event.target };
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
  function pause() {
    disconnect();
    clearTimeout(sendTimer);
    pending = null;
    pendingHello = null;
    layer.dataset.connected = 'false';
    for (const id of [...peers.keys()]) removePeer(id);
    presence.clear();
  }
  listen(window, 'offline', pause);
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
  function depart() {
    if (userId)
      request(
        eventUrl,
        { clientId, sequence: ++sequence, kind: 'leave', style, shape, anchor: null, caret: null, field: null },
        true
      ).catch(() => {});
  }
  listen(window, 'pagehide', () => {
    depart();
    stop();
  });
  const observer = new MutationObserver((records) => {
    if (records.some((record) => !layer.contains(record.target))) redraw();
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
    }
    presence.expire(Date.now(), expiry);
    for (const [id, version] of versions) {
      if (Date.now() - version.seen > 2 * expiry) versions.delete(id);
    }
    if (!document.hidden) publish('heartbeat');
  }, heartbeatInterval);

  function stop() {
    stopped = true;
    disconnect();
    clearInterval(heartbeat);
    for (const id of [...peers.keys()]) removePeer(id);
    presence.clear();
    for (const timer of fadeTimers) clearTimeout(timer);
    for (const timer of [sendTimer, touchTimer]) clearTimeout(timer);
    cancelAnimationFrame(animation);
    observer.disconnect();
    resizer.disconnect();
    for (const remove of removers) remove();
    layer.replaceChildren();
    layer.dataset.connected = 'false';
  }

  connect();
  return { stop, receive, publish, peers, presence };
}

if (document.readyState === 'loading')
  document.addEventListener('DOMContentLoaded', () => initCursors(), { once: true });
else initCursors();
