// @ts-check
import { CURSOR_SHAPE_NAMES } from './shapes.js';

// The shapes are their own module; they stay importable from here.
export { CURSOR_SHAPE_NAMES, CURSOR_SHAPES, shapeHotspot } from './shapes.js';

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
  for (const element of /** @type {NodeListOf<HTMLElement>} */ (
    document.querySelectorAll(`[data-collaborate-popup][${keyAttribute}]`)
  )) {
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
  const { data } = /** @type {Text} */ (node);
  const glyph = document.createRange();
  for (const start of [offset - 1, offset]) {
    if (start < 0 || start >= data.length || !/\S/.test(data[start])) continue;
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
  const control = /** @type {HTMLInputElement} */ (target);
  if ((isTextControl(target) && !control.disabled) || control.isContentEditable) return 'text';
  if (style.userSelect !== 'none' && overGlyph(x, y)) return 'text';
  return 'default';
}
