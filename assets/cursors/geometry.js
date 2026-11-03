export const CURSOR_COLORS = [
  'blue',
  'indigo',
  'purple',
  'pink',
  'red',
  'orange',
  'yellow',
  'green',
  'teal',
  'cyan',
  'black',
  'white',
  'gray',
  'gray-dark',
];

export function cursorColor(id) {
  return CURSOR_COLORS[id % CURSOR_COLORS.length];
}

export function cursorStyle(
  pointerType,
  platform = navigator.userAgentData?.platform || navigator.platform || navigator.userAgent
) {
  if (pointerType === 'touch') return 'touch';
  if (/mac|iphone|ipad/i.test(platform)) return 'mac';
  if (/linux|ubuntu|android/i.test(platform)) return 'ubuntu';
  return 'windows';
}

// EasyAdmin's layout, unless the host names its own regions (`cursors.regions`).
const REGIONS = {
  modal: '.modal.show',
  header: '.content-top',
  'mobile-header': '.responsive-header',
  // `.ea-sidebar` from EasyAdmin 5.6, `.sidebar` before.
  sidebar: '.ea-sidebar, .sidebar',
  content: '.content-wrapper',
  body: 'body',
};
const ANCHOR_ATTRIBUTES = ['data-id', 'data-column', 'name', 'id'];

// What the layer's configuration says of the host's pages; `configureGeometry()` sets it.
let settings;

/**
 * The host's description of its pages, from the layer's `data-config`: its regions (name => root selector,
 * the first one an element is in naming it), the attribute naming a record wherever a page shows it and
 * where those records are found, the attributes and classes a path names an element by, and the regions
 * whose text fields share their caret.
 */
export function configureGeometry({
  regions = REGIONS,
  keyAttribute = null,
  keyScope = 'body',
  anchorAttributes = ANCHOR_ATTRIBUTES,
  anchorClasses = false,
  caretRegions = [],
} = {}) {
  settings = { regions, keyAttribute, keyScope, anchorAttributes, anchorClasses, caretRegions };
}
configureGeometry();

export function regionNames() {
  return Object.keys(settings.regions);
}

/** Whether a caret in a region is shared: every region's, unless the host names some. */
export function sharesCaret(region) {
  return !settings.caretRegions.length || settings.caretRegions.includes(region);
}

function visible(element) {
  const box = element.getBoundingClientRect();
  return box.width > 0 && box.height > 0;
}

// The lookups a frame repeats for every peer and record, kept while it measures the page (`measure()`): what
// it writes, the layer's own elements and marks, changes none of them.
let frame = null;

/** Runs `work` with the page's regions, paths and records looked up once each. */
export function measure(work) {
  if (frame) return work();
  frame = new Map();
  try {
    return work();
  } finally {
    frame = null;
  }
}

function cached(key, lookup) {
  if (!frame) return lookup();
  if (!frame.has(key)) frame.set(key, lookup());
  return frame.get(key);
}

function regionRoot(region) {
  return cached(`region ${region}`, () =>
    [...document.querySelectorAll(Object.hasOwn(settings.regions, region) ? settings.regions[region] : ':not(*)')].find(
      visible
    )
  );
}

// An id drawn anew on every render names nothing another page can find: EasyAdmin's form fieldsets carry a
// ULID (`content-ea_form_fieldset_01J…`), other generators a UUID.
const generatedId = /[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/;

// Stable semantic attributes survive different scroll positions, translations and viewport widths. A record's
// key names it however long, the path as a whole being bounded.
function segment(element) {
  const { keyAttribute, anchorAttributes, anchorClasses } = settings;
  for (const name of keyAttribute ? [keyAttribute, ...anchorAttributes] : anchorAttributes) {
    const value = element.getAttribute(name);
    if (name === 'id' && generatedId.test(value ?? '')) continue;
    if (value && (name === keyAttribute || value.length < 160))
      return `${element.localName}[${name}="${CSS.escape(value)}"]`;
  }
  const siblings = [...element.parentElement.children];
  const [first] = element.classList;
  if (anchorClasses && first && siblings.filter((sibling) => sibling.classList.contains(first)).length === 1)
    return `${element.localName}.${CSS.escape(first)}`;
  const index = siblings.filter((sibling) => sibling.localName === element.localName).indexOf(element) + 1;
  return `${element.localName}:nth-of-type(${index})`;
}

/** The element showing a record among the ones the page shows (`cursors.key_scope`), or null: a key is compared, never queried. */
export function findRecord(key) {
  const { keyAttribute } = settings;
  if (!keyAttribute || typeof key !== 'string') return null;
  if (frame) return cached('records', records).get(key) ?? null;
  for (const element of keyed())
    if (element.getAttribute(keyAttribute) === key && !element.closest('[data-collaborate-popup]')) return element;
  return null;
}

function* keyed() {
  const { keyAttribute, keyScope } = settings;
  for (const scope of document.querySelectorAll(keyScope)) yield* scope.querySelectorAll(`[${keyAttribute}]`);
}

/** Every record the page shows, by key: the first element of each, as `findRecord()` finds it. */
function records() {
  const found = new Map();
  for (const element of keyed()) {
    const key = element.getAttribute(settings.keyAttribute);
    if (!found.has(key) && !element.closest('[data-collaborate-popup]')) found.set(key, element);
  }
  return found;
}

const inScope = (element) => [...document.querySelectorAll(settings.keyScope)].some((scope) => scope.contains(element));

// A region in a dialog holds popups: a path in it is of a record's popup, which another one, of the same shape,
// is not.
const popupRoot = (root) => root.closest('dialog, .modal') !== null;

/**
 * The region and selector path from a region root, or null when the element is too deep to name. With a key
 * attribute, the path starts at the record the element is in, which a peer's page finds wherever it shows it;
 * a record the page shows only to its reader (one outside the key scope) is never named.
 */
export function anchorPath(target) {
  if (!(target instanceof Element) || target.closest('[data-collaborate-layer]')) return null;
  const region = regionNames().find((name) => target.closest(settings.regions[name]));
  // The root of the page, its scrollbar, is in no region.
  if (!region) return null;
  const root = target.closest(settings.regions[region]);
  const { keyAttribute } = settings;
  const segments = [];
  let element = target;
  let record = null;
  while (element !== root && !record && segments.join(' > ').length < 1000) {
    segments.unshift(segment(element));
    if (keyAttribute && element.hasAttribute(keyAttribute)) record = element;
    element = element.parentElement;
  }
  if (element !== root && !record) return null;
  if (record && !inScope(record) && !findRecord(record.getAttribute(keyAttribute))) return null;
  return { region, selector: segments.join(' > ') };
}

function query(root, selector) {
  try {
    return root.querySelector(selector);
  } catch {
    return null;
  }
}

/**
 * The element a path names, or null. A path from a record that leads to no element here (the page lays the
 * record out another way, a row here where the peer has a tile) leads to its last step in the record, or else
 * to the record itself. In a popup, the kind of the record's element stays: two popups of a record are not one
 * another, and a path that names no record names nothing of the popup open here.
 */
export function resolvePath({ region, selector }) {
  return cached(`path ${region} ${selector}`, () => lookUpPath(region, selector));
}

function lookUpPath(region, selector) {
  const root = regionRoot(region);
  if (!root) return null;
  const { keyAttribute } = settings;
  const popup = keyAttribute && popupRoot(root);
  if (!selector) return popup ? null : root;
  const steps = selector.split(' > ');
  if (!keyAttribute || !steps[0].includes(`[${keyAttribute}=`))
    return popup ? null : query(root, `:scope > ${selector}`);
  const anyKind = (path) => (popup ? path : path.replace(/^[a-zA-Z0-9-]+/, ''));
  const record = anyKind(steps[0]);
  return (
    query(root, anyKind(selector)) ??
    (steps.length > 1 ? query(root, `${record} ${steps.at(-1).replace(/:nth-of-type\(\d+\)$/, '')}`) : null) ??
    query(root, record)
  );
}

/** The key of the record a pointer is on, among the ones the page shows, or null. */
export function keyOf(anchor) {
  const { keyAttribute } = settings;
  if (!keyAttribute || !anchor?.selector.split(' > ')[0].includes(`[${keyAttribute}=`)) return null;
  const record = resolvePath(anchor)?.closest(`[${keyAttribute}]`);
  return record && inScope(record) ? record.getAttribute(keyAttribute) : null;
}

export function captureAnchor(target, x, y) {
  if (!(target instanceof Element)) return null;
  const box = target.getBoundingClientRect();
  if (!box.width || !box.height) return null;
  const path = anchorPath(target);
  if (!path) return null;
  return {
    ...path,
    x: Math.max(0, Math.min(1, (x - box.left) / box.width)),
    y: Math.max(0, Math.min(1, (y - box.top) / box.height)),
  };
}

/** Whether two pointers are at the same point of the same element. */
export function sameAnchor(a, b) {
  return a === b || (!!a && !!b && a.region === b.region && a.selector === b.selector && a.x === b.x && a.y === b.y);
}

/**
 * The point of an element at fractions of its box where it can be seen, or null: off screen, hidden, clipped
 * or covered. The browser resolves clipping, fixed-position containing blocks and occlusion: a fixed modal can
 * escape a scrolling ancestor, so rectangle-only clipping is incorrect.
 */
export function seen(element, fx = 0.5, fy = 0.5) {
  const box = element.getBoundingClientRect();
  const x = box.left + box.width * fx;
  const y = box.top + box.height * fy;
  if (!box.width || !box.height || x < 0 || y < 0 || x > innerWidth || y > innerHeight) return null;
  const hit = document.elementFromPoint(x, y);
  if (!hit || !element.contains(hit)) return null;
  return { x, y };
}

export function resolveAnchor(anchor) {
  if (!anchor) return null;
  const target = resolvePath(anchor);
  return target && seen(target, anchor.x, anchor.y);
}

// How far from the corners of a pane its edge is pointed at.
const EDGE_MARGIN = 16;

/**
 * Where the edge of a pane (`cursors.edges`) is pointed at for a pointer its pane scrolled out of sight, above
 * it (under the head of a table, which stays in place) or below it, or null for one that is not: seen, gone,
 * outside any pane, or the pane itself covered.
 */
export function offscreenAnchor(anchor, panes) {
  if (!anchor || !panes) return null;
  const target = resolvePath(anchor);
  const pane = target?.closest(panes);
  if (!pane) return null;
  const box = target.getBoundingClientRect();
  const view = pane.getBoundingClientRect();
  if (!box.width || !box.height || !view.width || !view.height) return null;
  const top = view.top + (pane.querySelector(':scope thead')?.getBoundingClientRect().height ?? 0);
  const y = box.top + box.height * anchor.y;
  let side = null;
  if (y < top) side = 'above';
  else if (y > view.bottom) side = 'below';
  if (!side) return null;
  const x = Math.min(Math.max(box.left + box.width * anchor.x, view.left + EDGE_MARGIN), view.right - EDGE_MARGIN);
  const edge = side === 'above' ? top : view.bottom;
  const hit = document.elementFromPoint(x, side === 'above' ? edge + 1 : edge - 1);
  if (!hit || !pane.contains(hit)) return null;
  return { x, y: edge, side };
}

/** Whether a popup is one of the host's kinds, open on a record. */
export function isPopup(popup, kinds) {
  return kinds.includes(popup?.kind) && typeof popup.key === 'string' && popup.key !== '' && popup.key.length <= 255;
}

export function samePopup(a, b) {
  return a?.kind === b?.kind && a?.key === b?.key;
}

/**
 * The popup of a record the reader has open (`data-collaborate-popup="<kind>"` beside the key attribute), or
 * null: one of a record the page shows to its peers alone, since naming another would hand them its key.
 */
export function capturePopup(kinds) {
  const { keyAttribute } = settings;
  if (!keyAttribute || !kinds.length) return null;
  for (const element of document.querySelectorAll(`[data-collaborate-popup][${keyAttribute}]`)) {
    if (element.closest('dialog:not([open])') || !visible(element)) continue;
    const popup = { kind: element.dataset.collaboratePopup, key: element.getAttribute(keyAttribute) };
    if (isPopup(popup, kinds) && findRecord(popup.key)) return popup;
  }
  return null;
}

/** A point on its way from one place to another, a tenth of a second long, or there at once. */
export function interpolate(from, to, elapsed, reducedMotion = false) {
  const fraction = reducedMotion ? 1 : Math.min(1, Math.max(0, elapsed / 100));
  // The point itself: the sum of the way may miss it by a rounding, and ask frames for good.
  if (fraction === 1) return to;
  return { x: from.x + (to.x - from.x) * fraction, y: from.y + (to.y - from.y) * fraction };
}

// Pointer shapes in a 32×36 box (24 px on screen), filled with the user's color under the even-odd rule.
// Paths trace the fill of each platform's own cursors, measured against Yaru's bitmaps, apple_cursor's SVGs and
// Microsoft's cursor images; the contrast outline is painted beneath, outside the fill, as those cursors draw it.
// `hotspot` is the pointed pixel before `rotate` (degrees about 16,16).
const round = (value) => Math.round(value * 100) / 100;
const point = ([x, y]) => `${round(x)} ${round(y)}`;
const polygon = (points) => `M${points.map(point).join('L')}Z`;
const rect = (x, y, width, height) => `M${x} ${y}h${width}v${height}h${-width}Z`;
const circle = (cx, cy, r) => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0Z`;
const polar = (cx, cy, r, degrees) => [
  cx + r * Math.cos((degrees * Math.PI) / 180),
  cy + r * Math.sin((degrees * Math.PI) / 180),
];

function rotated(points, degrees, [cx, cy] = [16, 16]) {
  const angle = (degrees * Math.PI) / 180;
  return points.map(([x, y]) => [
    cx + (x - cx) * Math.cos(angle) - (y - cy) * Math.sin(angle),
    cy + (x - cx) * Math.sin(angle) + (y - cy) * Math.cos(angle),
  ]);
}

// A horizontal arrow with a head at both ends: tips `l` from the centre, heads `h` long and `w` wide, shaft `s`.
function doubleArrow({ l, h, w, s }) {
  const [a, b] = [16 - l, 16 + l];
  return polygon([
    [a, 16],
    [a + h, 16 - w],
    [a + h, 16 - s],
    [b - h, 16 - s],
    [b - h, 16 - w],
    [b, 16],
    [b - h, 16 + w],
    [b - h, 16 + s],
    [a + h, 16 + s],
    [a + h, 16 + w],
  ]);
}

// Arrows on both sides of a splitter bar, in one outline (macOS) or as separate pieces (Windows).
function barArrows({ l, h, w, s, bar, barHeight }) {
  const [a, b] = [16 - l, 16 + l];
  return polygon([
    [a, 16],
    [a + h, 16 - w],
    [a + h, 16 - s],
    [16 - bar, 16 - s],
    [16 - bar, 16 - barHeight],
    [16 + bar, 16 - barHeight],
    [16 + bar, 16 - s],
    [b - h, 16 - s],
    [b - h, 16 - w],
    [b, 16],
    [b - h, 16 + w],
    [b - h, 16 + s],
    [16 + bar, 16 + s],
    [16 + bar, 16 + barHeight],
    [16 - bar, 16 + barHeight],
    [16 - bar, 16 + s],
    [a + h, 16 + s],
    [a + h, 16 + w],
  ]);
}

function splitArrows({ l, h, w, s, gap, bar, barHeight }) {
  const arrow = (direction) =>
    polygon(
      [
        [16 - l, 16],
        [16 - l + h, 16 - w],
        [16 - l + h, 16 - s],
        [16 - gap - 2 * bar, 16 - s],
        [16 - gap - 2 * bar, 16 + s],
        [16 - l + h, 16 + s],
        [16 - l + h, 16 + w],
      ].map(([x, y]) => [16 + direction * (x - 16), y])
    );
  const bars = [-1, 1].map((side) =>
    rect(round(16 + side * gap - (side < 0 ? bar : 0)), 16 - barHeight, bar, 2 * barHeight)
  );
  return `${arrow(1)}${arrow(-1)}${bars.join('')}`;
}

function fourWay({ l, h, w, s }, [cx, cy] = [16, 16], turn = 0) {
  const quarter = [
    [cx - s, cy - l + h],
    [cx - w, cy - l + h],
    [cx, cy - l],
    [cx + w, cy - l + h],
    [cx + s, cy - l + h],
    [cx + s, cy - s],
  ];
  return polygon([0, 90, 180, 270].flatMap((quarterTurn) => rotated(quarter, quarterTurn + turn, [cx, cy])));
}

const plus = (length, half) => fourWay({ l: length, h: 0, w: half, s: half });
const cross = (cx, cy, length, half) => fourWay({ l: length, h: 0, w: half, s: half }, [cx, cy], 45);

// A ring with a bar across it: the outer disc minus the two circular segments the bar leaves.
// The bar runs at `angle` degrees: 45 is ↘, -45 is ↗.
function noEntry(cx, cy, r, thickness, angle = 45) {
  const inner = r - thickness;
  const reach = (Math.asin(thickness / 2 / inner) * 180) / Math.PI;
  const segment = (start) =>
    polygon(
      Array.from({ length: 13 }, (_, step) => polar(cx, cy, inner, start + reach + ((180 - 2 * reach) * step) / 12))
    );
  return `${circle(cx, cy, r)}${segment(angle)}${segment(angle + 180)}`;
}

// An annular sector between radii `r` and `R`, from `start` to `end` degrees clockwise (0° points right).
function ringArc(cx, cy, R, r, start, end) {
  const large = end - start > 180 ? 1 : 0;
  return `M${point(polar(cx, cy, R, start))}A${R} ${R} 0 ${large} 1 ${point(polar(cx, cy, R, end))}L${point(polar(cx, cy, r, end))}A${r} ${r} 0 ${large} 0 ${point(polar(cx, cy, r, start))}Z`;
}

// A disc cut by thin curved spokes, the single-colour form of macOS's spinning wheel.
function wheel(cx, cy, r, spokes) {
  let d = circle(cx, cy, r);
  for (let spoke = 0; spoke < spokes; spoke++) {
    const rim = point(polar(cx, cy, r, (360 / spokes) * spoke - 90));
    d += `M${cx} ${cy}A${r} ${r} 0 0 1 ${rim}A${round(r * 1.15)} ${round(r * 1.15)} 0 0 0 ${cx} ${cy}Z`;
  }
  return d;
}

const MAC_ARROW = 'M3.5 3.5v15.6l3.3-3.3 3.2 7.6 2.8-1.3-3.1-7.3h4.9Z';
const MAC_TIP = [3, 2.4];
// apple_cursor's badged cursors pair a small arrowhead with a disc below it.
const MAC_SMALL_ARROW = 'M7 2.4v9.2l2.2-2.2h4.4Z';
const WINDOWS_ARROW = 'M3.3 4v19.3l4.8-4.5 3.2 7.2H14v-2l-2.3-6h5.6Z';
const WINDOWS_TIP = [2.7, 2.7];
const WINDOWS_PALM = 'V26c0 2-1.3 4.6-4 6.4l-2 .3h-4.7c-1 0-2-.6-2.6-1.4L4.7 22h-1.4v-2.7H6l2.7 2.7H10Z';
const YARU_PALM =
  'a1.3 1.3 0 0 1-1.3 1.3H12.4c-.7 0-1.3-.3-1.7-.8L5.6 19.6a1.1 1.1 0 0 1 .2-1.6l.4-.3c.7-.5 1.6-.4 2.2.2l2.3 2.4Z';
const YARU_ARROW = 'M5.3 6.3v17.4l4.2-4 2.8 7.6h2.4l-2.3-8.3 6.3-.3Z';
const YARU_TIP = [5.5, 6];
const YARU_TEXT =
  'M10.7 5.3h2l1.3.7 1.3-.7h2v1.4h-2l-.6.6v16.1l.6.6h2v1.4h-2l-1.3-.7-1.3.7h-2v-1.4h2l.6-.6V7.3l-.6-.6h-2Z';
const MAC_TEXT =
  'M11 3.6c2 0 3.8.7 5 1.9 1.2-1.2 3-1.9 5-1.9v2c-1.8 0-3.2.9-4 2.4v7.9h1.6v2H17v7.9c.8 1.5 2.2 2.4 4 2.4v2c-2 0-3.8-.7-5-1.9-1.2 1.2-3 1.9-5 1.9v-2c1.8 0 3.2-.9 4-2.4v-7.9h-1.6v-2H15V8c-.8-1.5-2.2-2.4-4-2.4Z';
const WINDOWS_TEXT = 'M11.3 4.7h9.4V6h-4v18.7h4V26h-9.4v-1.3h4V6h-4Z';
const center = [16, 16];

const mac = {
  default: { d: MAC_ARROW, hotspot: MAC_TIP },
  pointer: {
    d:
      'M10.3 7.6a2.4 2.4 0 0 1 4.7-.5l.9 5.2c.3-1 1.2-1.6 2.3-1.6 1.2 0 2.1.7 2.4 1.8.4-.6 1.1-1 1.9-1 1.2 0 2.1.9 2.2 2.1 1-.1 1.8.7 1.8 1.7v5c0 4.4-3 7.1-6.8 7.1h-3.4c-2.3 0-3.7-.8-5-2.6l-5.5-7.3a2 2 0 0 1 .3-2.7c1.6-1.3 3.7-.4 4.9 1.1l.8 1Z' +
      `${rect(15.8, 18, 0.7, 4.8)}${rect(18.7, 18, 0.7, 4.8)}${rect(21.6, 18, 0.7, 4.8)}`,
    hotspot: [12.4, 5],
  },
  text: { d: MAC_TEXT, hotspot: [16, 16.9] },
  'vertical-text': { d: MAC_TEXT, hotspot: [16, 16.9], rotate: 90 },
  grab: {
    d: 'M9 17.4 7.2 8.6a1.7 1.7 0 0 1 3.3-.7L12 14.5l.4-8.2a1.7 1.7 0 0 1 3.4.1l.1 8.1 1.4-7a1.7 1.7 0 0 1 3.3.6l-1 7.4 2.7-4.6a1.7 1.7 0 0 1 3 1.6l-2.4 6.4c-.5 1.4-.6 2.2-.6 3.6 0 2.7-2.2 4.9-5.4 4.9h-2.4c-2.4 0-4-1.1-5.3-2.9L4.9 19.9a1.8 1.8 0 0 1 2.9-2.1Z',
    hotspot: [16, 15],
  },
  grabbing: {
    d:
      'M8.6 18v-3.6a1.7 1.7 0 0 1 3.4 0v-1a1.7 1.7 0 0 1 3.4 0v-.4a1.7 1.7 0 0 1 3.4 0v.4a1.7 1.7 0 0 1 3.4 0V21c0 3.6-2.6 6.4-6 6.4h-2.8c-2.4 0-4-1-5.4-2.9L6 20.7a1.8 1.8 0 0 1 2.6-2.4Z' +
      `${rect(11.7, 15, 0.7, 3)}${rect(15.1, 14.5, 0.7, 3)}${rect(18.5, 14.5, 0.7, 3)}`,
    hotspot: [15.4, 18],
  },
  move: { d: fourWay({ l: 12.5, h: 5.2, w: 4.6, s: 1.4 }), hotspot: center },
  'not-allowed': {
    d: `${MAC_SMALL_ARROW}${circle(16, 21.8, 9.5)}${cross(16, 21.8, 4.3, 1.1)}`,
    hotspot: [6.8, 2],
  },
  help: {
    d: `M12.3 8.2c1.1-.8 2.6-1.4 4.2-1.4 3.1 0 5.6 1.9 5.6 4.9 0 1.6-.6 2.6-1.6 3.5-.4.4-1.3 1.1-1.9 1.6-.6.5-.9 1.1-.9 2v.7h-3.1v-.9c0-1.4.4-2.4 1.4-3.3l1.7-1.5c.5-.5.8-1 .8-1.7 0-1.2-.9-2-2.4-2-1.2 0-2.3.4-3.3 1.2Z${rect(14.6, 21.6, 3.1, 3.1)}`,
    hotspot: center,
  },
  wait: { d: wheel(16, 16, 10, 6), hotspot: center },
  progress: {
    d: `${MAC_SMALL_ARROW}${circle(16, 21.8, 9.5)}${rect(15.6, 12.3, 0.8, 19)}${rect(6.5, 21.4, 19, 0.8)}`,
    hotspot: [6.8, 2],
  },
  crosshair: { d: plus(12.9, 0.55), hotspot: center },
  'col-resize': { d: barArrows({ l: 10.3, h: 4.7, w: 4.65, s: 1.4, bar: 1.4, barHeight: 10 }), hotspot: center },
  'row-resize': {
    d: barArrows({ l: 10.3, h: 4.7, w: 4.65, s: 1.4, bar: 1.4, barHeight: 10 }),
    hotspot: center,
    rotate: 90,
  },
  'ew-resize': { d: barArrows({ l: 10.3, h: 4.7, w: 4.65, s: 1.4, bar: 1.4, barHeight: 10 }), hotspot: center },
  'ns-resize': {
    d: barArrows({ l: 10.3, h: 4.7, w: 4.65, s: 1.4, bar: 1.4, barHeight: 10 }),
    hotspot: center,
    rotate: 90,
  },
  'nesw-resize': { d: doubleArrow({ l: 12.3, h: 5.2, w: 5.2, s: 1.44 }), hotspot: center, rotate: -45 },
  'nwse-resize': { d: doubleArrow({ l: 12.3, h: 5.2, w: 5.2, s: 1.44 }), hotspot: center, rotate: 45 },
};

const windowsResize = doubleArrow({ l: 14, h: 5.3, w: 4.7, s: 0.67 });
const windowsSplit = splitArrows({ l: 14, h: 5.3, w: 4.7, s: 0.67, gap: 0.67, bar: 1.33, barHeight: 9.3 });
const windows = {
  default: { d: WINDOWS_ARROW, hotspot: WINDOWS_TIP },
  pointer: {
    d: `M10 4.7a1.3 1.3 0 0 1 2.7 0V10h4v1.3h4v1.4h4${WINDOWS_PALM}${rect(12.7, 10, 1.3, 5.3)}${rect(16.7, 11.3, 1.3, 4)}${rect(20.7, 12.7, 1.3, 2.7)}`,
    hotspot: [11.3, 2.7],
  },
  text: { d: WINDOWS_TEXT, hotspot: [16, 15.3] },
  'vertical-text': { d: WINDOWS_TEXT, hotspot: [16, 15.3], rotate: 90 },
  grab: {
    d: `M10 7.3a1.3 1.3 0 0 1 2.7 0V14H14V4.7a1.3 1.3 0 0 1 2.7 0V14H18V6a1.3 1.3 0 0 1 2.7 0V14H22V9.3a1.3 1.3 0 0 1 2.7 0${WINDOWS_PALM}`,
    hotspot: [17.3, 18],
  },
  grabbing: {
    d: `M10 15.3a1.8 1.8 0 0 1 3.7 0 1.8 1.8 0 0 1 3.6 0 1.8 1.8 0 0 1 3.7 0 1.8 1.8 0 0 1 3.7 0${WINDOWS_PALM}${rect(13.3, 14.5, 0.8, 2.5)}${rect(17, 14.5, 0.8, 2.5)}${rect(20.7, 14.5, 0.8, 2.5)}`,
    hotspot: [17.3, 20],
  },
  move: { d: fourWay({ l: 14, h: 5.33, w: 4.67, s: 0.67 }), hotspot: center },
  'not-allowed': { d: noEntry(16, 16, 10.7, 2.7), hotspot: center },
  help: {
    d: `${WINDOWS_ARROW}M22 19.3h5.3l1.4 1.4v2.6L26 26v1.3h-2.7v-2.6l2.7-2.7v-1.3h-2.7V22H22Z${rect(23.3, 28.7, 2.7, 2.6)}`,
    hotspot: WINDOWS_TIP,
  },
  wait: { d: ringArc(16, 16, 13.3, 6.7, -50, 250), hotspot: center },
  progress: { d: `${WINDOWS_ARROW}${ringArc(21.3, 4, 10, 4.7, -50, 250)}`, hotspot: WINDOWS_TIP },
  crosshair: { d: plus(16, 1.33), hotspot: center },
  'col-resize': { d: windowsSplit, hotspot: center },
  'row-resize': { d: windowsSplit, hotspot: center, rotate: 90 },
  'ew-resize': { d: windowsResize, hotspot: center },
  'ns-resize': { d: windowsResize, hotspot: center, rotate: 90 },
  'nesw-resize': { d: doubleArrow({ l: 14.1, h: 4.7, w: 4.7, s: 0.7 }), hotspot: center, rotate: -45 },
  'nwse-resize': { d: doubleArrow({ l: 14.1, h: 4.7, w: 4.7, s: 0.7 }), hotspot: center, rotate: 45 },
};

const yaruResize = doubleArrow({ l: 10.7, h: 6.4, w: 3.6, s: 1.1 });
const yaruRing = (cx, cy, R, r) => [-75, 45, 165].map((start) => ringArc(cx, cy, R, r, start, start + 90)).join('');
const ubuntu = {
  default: { d: YARU_ARROW, hotspot: YARU_TIP },
  pointer: {
    d: `M10.7 5.3a1.3 1.3 0 0 1 2.6 0v7.4h8.7c1.5 0 2.7 1.2 2.7 2.7v8${YARU_PALM}`,
    hotspot: [11.3, 6.7],
  },
  text: { d: YARU_TEXT, hotspot: [14, 15.3] },
  'vertical-text': { d: YARU_TEXT, hotspot: [14, 15.3], rotate: 90 },
  grab: {
    d: `M10.7 20V8.6a1.3 1.3 0 0 1 2.6 0v4.7h1.4V6a1.3 1.3 0 0 1 2.6 0v7.3h1.4V7.3a1.3 1.3 0 0 1 2.6 0v6h1.4V9.6a1.3 1.3 0 0 1 2.6 0v13.8${YARU_PALM}`,
    hotspot: [14.7, 9.3],
  },
  grabbing: {
    d:
      'M10.7 14.7V12c0-1.1.9-2 2-2h.8c.4-.9 1.3-1.6 2.4-1.6h1.6c1.1 0 2 .6 2.4 1.6h1.6c1.5 0 2.7 1.2 2.7 2.7V22a1.3 1.3 0 0 1-1.3 1.3H12c-.7 0-1.3-.3-1.7-.8l-2.8-3.6c-.5-.6-.7-1.3-.7-2v-.6c0-1.1.9-2 2-2Z' +
      `${rect(13.2, 10, 0.8, 2)}${rect(19.6, 10, 0.8, 2)}`,
    hotspot: [16, 14.7],
  },
  move: {
    d: `M16 3.7l3.5 6.3h-7ZM16 28.3l-3.5-6.3h7ZM3.7 16l6.3-3.5v7ZM28.3 16l-6.3 3.5v-7Z${circle(16, 16, 1.4)}`,
    hotspot: center,
  },
  'not-allowed': { d: noEntry(16, 16, 10.3, 4, -45), hotspot: center },
  help: {
    d: 'M10.3 8.3c0-2.6 2.6-4.3 5.6-4.3 3.6 0 6.6 2 6.6 5.3 0 2.3-1.2 3.5-2.7 4.6-1 .7-1.8 1.4-1.8 2.6v4.8h1.8l-4.5 5-4.5-5h2.6v-5.1c0-2.2 1-3.6 2.7-4.8 1-.7 1.7-1.3 1.7-2.4 0-1.2-1-2-2.6-2-1.4 0-2.4.6-2.6 1.6Z',
    hotspot: [15.3, 26.3],
  },
  wait: { d: `${circle(16, 16, 10.3)}${yaruRing(16, 16, 7.6, 6.3)}`, hotspot: center },
  progress: {
    d: `M4 3.3v15.4l4-4.7 7.3-.7Z${circle(12.7, 23.3, 7)}${yaruRing(12.7, 23.3, 5.2, 4.2)}`,
    hotspot: [4, 3.3],
  },
  crosshair: {
    d: 'M14.7 4h2.6v7.3L16 12.7l-1.3-1.4ZM14.7 28h2.6v-7.3L16 19.3l-1.3 1.4ZM4 14.7h7.3l1.4 1.3-1.4 1.3H4ZM28 14.7h-7.3L19.3 16l1.4 1.3H28Z',
    hotspot: center,
  },
  'col-resize': { d: yaruResize, hotspot: center },
  'row-resize': { d: yaruResize, hotspot: center, rotate: 90 },
  'ew-resize': { d: yaruResize, hotspot: center },
  'ns-resize': { d: yaruResize, hotspot: center, rotate: 90 },
  'nesw-resize': { d: doubleArrow({ l: 10.8, h: 4.7, w: 4.7, s: 1.2 }), hotspot: center, rotate: -45 },
  'nwse-resize': { d: doubleArrow({ l: 10.8, h: 4.7, w: 4.7, s: 1.2 }), hotspot: center, rotate: 45 },
};

export const CURSOR_SHAPES = { mac, windows, ubuntu };

export const CURSOR_SHAPE_NAMES = Object.keys(CURSOR_SHAPES.mac);

// The pointed pixel after the shape's rotation, in viewBox units.
export function shapeHotspot({ hotspot: [x, y], rotate = 0 }) {
  const angle = (rotate * Math.PI) / 180;
  const [dx, dy] = [x - 16, y - 16];
  return [
    round(16 + dx * Math.cos(angle) - dy * Math.sin(angle)),
    round(16 + dx * Math.sin(angle) + dy * Math.cos(angle)),
  ];
}

const CURSOR_ALIASES = {
  'all-scroll': 'move',
  'e-resize': 'ew-resize',
  'w-resize': 'ew-resize',
  'n-resize': 'ns-resize',
  's-resize': 'ns-resize',
  'ne-resize': 'nesw-resize',
  'sw-resize': 'nesw-resize',
  'nw-resize': 'nwse-resize',
  'se-resize': 'nwse-resize',
};

const TEXT_INPUT_TYPES = ['text', 'search', 'url', 'tel'];

// Controls whose value and selection can be shared: never passwords, hidden or non-text inputs.
export function isTextControl(element) {
  return (
    element instanceof HTMLTextAreaElement ||
    (element instanceof HTMLInputElement && TEXT_INPUT_TYPES.includes(element.type))
  );
}

function overGlyph(x, y) {
  const position = document.caretPositionFromPoint?.(x, y);
  const range = position ? null : document.caretRangeFromPoint?.(x, y);
  const node = position ? position.offsetNode : range?.startContainer;
  const offset = position ? position.offset : range?.startOffset;
  if (node?.nodeType !== Node.TEXT_NODE) return false;
  const glyph = document.createRange();
  for (const start of [offset - 1, offset]) {
    if (start < 0 || start >= node.data.length || !/\S/.test(node.data[start])) continue;
    glyph.setStart(node, start);
    glyph.setEnd(node, start + 1);
    for (const box of glyph.getClientRects())
      if (x >= box.left && x <= box.right && y >= box.top && y <= box.bottom) return true;
  }
  return false;
}

// The shape the sender's browser shows: the computed CSS cursor, or for `auto` an I-beam over text.
export function pointerShape(target, x, y) {
  if (!(target instanceof Element)) return 'default';
  const style = getComputedStyle(target);
  const keyword = style.cursor.split(',').pop().trim();
  const shape = CURSOR_ALIASES[keyword] || keyword;
  if (CURSOR_SHAPE_NAMES.includes(shape)) return shape;
  if (keyword && keyword !== 'auto') return 'default';
  if ((isTextControl(target) && !target.disabled) || target.isContentEditable) return 'text';
  if (style.userSelect !== 'none' && overGlyph(x, y)) return 'text';
  return 'default';
}
