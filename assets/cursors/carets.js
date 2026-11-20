// @ts-check
import { entrySelector, rowOf } from '../markup.js';
import { anchorPath, isTextControl, resolvePath, sharesCaret } from './geometry.js';

/** @typedef {import('./geometry.js').AnchorPath & { start: number, end: number }} Caret a text field's selection: its anchor and its focus, as text offsets */
/** @typedef {{ x: number, y: number, height: number }} CaretBar */
/** @typedef {{ left: number, top: number, width: number, height: number }} CaretRect */
/**
 * What a walk's step sees: a boundary point (`container` and `index`) or a text node, at a text offset.
 *
 * @typedef {{ container: Node, index: number, text?: undefined, offset: number }
 *   | { container?: undefined, index?: undefined, text: Text, offset: number }} WalkPoint
 */
/** @typedef {{ end: number, node?: undefined }} WalkEnd the text length, when no step ended the walk */

export const MAX_CARET_OFFSET = 10485760;
const MAX_RECTS = 64;

// Editor UI that exists only in the sender's DOM, and CKEditor's inline filler, never count as text.
const SKIPPED =
  '.ck-fake-selection-container, [data-cke-filler], .ck-widget__type-around, .ck-widget__selection-handle, .ck-widget__resizer';
const BLOCKS = /^(p|h[1-6]|li|ul|ol|blockquote|pre|figure|figcaption|table|tr|td|th|div|hr|br)$/;
const FILLER = /⁠/g;
/** @param {string} text */
const visibleLength = (text) => text.replace(FILLER, '').length;
/** @param {string} value */
const px = (value) => parseFloat(value) || 0;

/**
 * Walks the editable in document order with a running text offset, where every block or line break counts one.
 * `step` sees each boundary point and each text node; the first value it returns ends the walk.
 *
 * @template T
 * @param {Node} root
 * @param {(point: WalkPoint) => T | undefined} step
 * @returns {T | WalkEnd}
 */
function walk(root, step) {
  let offset = 0;
  /** @type {(container: Node) => T | undefined} */
  const visit = (container) => {
    for (let index = 0; index <= container.childNodes.length; index++) {
      let result = step({ container, index, offset });
      if (result !== undefined) return result;
      const child = container.childNodes[index];
      if (!child) break;
      if (child.nodeType === Node.TEXT_NODE) {
        result = step({ text: /** @type {Text} */ (child), offset });
        if (result !== undefined) return result;
        offset += visibleLength(/** @type {Text} */ (child).data);
      } else if (child.nodeType === Node.ELEMENT_NODE && !(/** @type {Element} */ (child).matches(SKIPPED))) {
        if (BLOCKS.test(/** @type {Element} */ (child).localName)) offset++;
        result = visit(child);
        if (result !== undefined) return result;
      }
    }
  };
  return visit(root) ?? { end: offset };
}

/**
 * @param {Node} root
 * @param {Node | null} node
 * @param {number} nodeOffset
 * @returns {number}
 */
export function textOffset(root, node, nodeOffset) {
  const result = walk(root, ({ container, index, text, offset }) => {
    if (text === node) return offset + visibleLength(/** @type {Text} */ (node).data.slice(0, nodeOffset));
    if (container === node && index === nodeOffset) return offset;
  });
  return typeof result === 'number' ? result : result.end;
}

/**
 * @param {Node} root
 * @param {number} target
 * @returns {{ node: Node, offset: number }}
 */
export function domPosition(root, target) {
  const result = walk(root, ({ container, index, text, offset }) => {
    if (container && offset >= target) return { node: container, offset: index };
    if (text && target - offset <= visibleLength(text.data)) {
      let remaining = target - offset;
      let position = 0;
      while (remaining > 0 || text.data[position] === '⁠') {
        if (text.data[position] !== '⁠') remaining--;
        position++;
      }
      return { node: text, offset: position };
    }
  });
  return result.node ? result : { node: root, offset: root.childNodes.length };
}

/**
 * The focused field's selection as offsets into its text; `end` is the focus, so direction survives.
 *
 * @param {Element | null} [element]
 * @returns {Caret | null}
 */
export function captureCaret(element = document.activeElement) {
  // Monaco types through an invisible proxy (a transparent textarea or edit context) whose offsets are not the
  // text on screen, and it moves that proxy's selection constantly: sharing it would only flood the channel.
  if (!(element instanceof Element) || element.closest('[data-collaborate-layer], .monaco-editor')) return null;
  let start;
  let end;
  if (isTextControl(element)) {
    const control = /** @type {HTMLInputElement | HTMLTextAreaElement} */ (element);
    if (control.selectionStart === null || control.selectionEnd === null) return null;
    [start, end] = [control.selectionStart, control.selectionEnd];
    if (control.selectionDirection === 'backward') [start, end] = [end, start];
  } else if (element instanceof HTMLElement && element.isContentEditable) {
    const selection = getSelection();
    if (!selection?.rangeCount || !element.contains(selection.anchorNode) || !element.contains(selection.focusNode))
      return null;
    start = textOffset(element, selection.anchorNode, selection.anchorOffset);
    end = textOffset(element, selection.focusNode, selection.focusOffset);
  } else return null;
  const path = anchorPath(element);
  // A field of a region the host keeps to its reader (a search box, say) shares no caret.
  if (!path || !sharesCaret(path.region) || Math.max(start, end) > MAX_CARET_OFFSET) return null;
  return { ...path, start, end };
}

/**
 * @param {Caret | null} a
 * @param {Caret | null} b
 */
export function sameCaret(a, b) {
  return (
    a === b ||
    (!!a && !!b && a.region === b.region && a.selector === b.selector && a.start === b.start && a.end === b.end)
  );
}

const MIRRORED = /** @type {const} */ ([
  'boxSizing',
  'width',
  'height',
  'borderTopWidth',
  'borderRightWidth',
  'borderBottomWidth',
  'borderLeftWidth',
  'borderStyle',
  'paddingTop',
  'paddingRight',
  'paddingBottom',
  'paddingLeft',
  'fontFamily',
  'fontSize',
  'fontStyle',
  'fontVariant',
  'fontWeight',
  'fontStretch',
  'letterSpacing',
  'lineHeight',
  'textAlign',
  'textIndent',
  'textTransform',
  'wordSpacing',
  'tabSize',
  'direction',
]);

/**
 * Text controls expose no geometry for their content: lay the value out again in an invisible copy.
 *
 * @param {HTMLInputElement | HTMLTextAreaElement} field
 * @param {Element} layer
 * @param {number} from
 * @param {number} to
 * @param {number} focus
 * @returns {{ rects: DOMRect[], bar: DOMRect }}
 */
function controlRanges(field, layer, from, to, focus) {
  const box = field.getBoundingClientRect();
  const style = getComputedStyle(field);
  const mirror = document.createElement('div');
  for (const name of MIRRORED) mirror.style[name] = style[name];
  Object.assign(mirror.style, {
    position: 'fixed',
    left: `${box.left}px`,
    top: `${box.top}px`,
    visibility: 'hidden',
    overflow: 'hidden',
    borderColor: 'transparent',
    whiteSpace: field instanceof HTMLTextAreaElement ? 'pre-wrap' : 'pre',
    overflowWrap: field instanceof HTMLTextAreaElement ? 'break-word' : 'normal',
  });
  // The copy has no scrollbar: keep the control's text width by padding for it.
  const scrollbar = field.offsetWidth - field.clientWidth - px(style.borderLeftWidth) - px(style.borderRightWidth);
  if (scrollbar > 0) mirror.style.paddingRight = `${px(style.paddingRight) + scrollbar}px`;
  if (!(field instanceof HTMLTextAreaElement)) {
    // A single-line input centres its line vertically.
    const inner = field.clientHeight - px(style.paddingTop) - px(style.paddingBottom);
    if (inner > 0) mirror.style.lineHeight = `${inner}px`;
  }
  const value = field.value;
  const selected = document.createElement('span');
  selected.textContent = value.slice(from, to);
  const marker = document.createElement('span');
  marker.textContent = '​';
  const parts = [value.slice(0, from), selected, value.slice(to)];
  parts.splice(focus === from ? 1 : 2, 0, marker);
  mirror.append(...parts);
  layer.append(mirror);
  mirror.scrollTop = field.scrollTop;
  mirror.scrollLeft = field.scrollLeft;
  const rects = from === to ? [] : [...selected.getClientRects()];
  const bar = marker.getBoundingClientRect();
  mirror.remove();
  return { rects, bar };
}

/**
 * @param {HTMLElement} field
 * @param {number} from
 * @param {number} to
 * @param {number} focus
 * @returns {{ rects: DOMRect[], bar: { left: number, top: number, height: number } }}
 */
function editableRanges(field, from, to, focus) {
  const range = document.createRange();
  const start = domPosition(field, from);
  const end = domPosition(field, to);
  range.setStart(start.node, start.offset);
  range.setEnd(end.node, end.offset);
  const rects = from === to ? [] : [...range.getClientRects()];
  const point = focus === from ? start : end;
  range.setStart(point.node, point.offset);
  range.collapse(true);
  /** @type {{ left: number, top: number, height: number }} */
  let bar = range.getClientRects()[0];
  if (!bar?.height) {
    // A collapsed range in an empty block has no box: use the line box of the block itself.
    const element = /** @type {Element} */ (
      point.node.nodeType === Node.ELEMENT_NODE ? point.node : point.node.parentElement
    );
    const box = element.getBoundingClientRect();
    const style = getComputedStyle(element);
    bar = {
      left: box.left + px(style.paddingLeft),
      top: box.top,
      height: Math.min(px(style.lineHeight) || box.height, box.height),
    };
  }
  return { rects, bar };
}

/**
 * Viewport geometry of a peer's caret, or null when its field is gone, hidden, clipped or covered.
 *
 * @param {Caret | null | undefined} caret
 * @param {Element} layer
 * @returns {{ bar: CaretBar, rects: CaretRect[] } | null}
 */
export function resolveCaret(caret, layer) {
  const field = /** @type {HTMLElement | null | undefined} */ (caret && resolvePath(caret));
  if (!field || !(isTextControl(field) || field.isContentEditable)) return null;
  const box = field.getBoundingClientRect();
  if (!box.width || !box.height) return null;
  const length = isTextControl(field) ? field.value.length : textOffset(field, field, field.childNodes.length);
  const start = Math.min(caret.start, length);
  const end = Math.min(caret.end, length);
  const [from, to] = start < end ? [start, end] : [end, start];
  const { rects, bar } = isTextControl(field)
    ? controlRanges(field, layer, from, to, end)
    : editableRanges(field, from, to, end);
  const height = Math.min(bar.height, box.height);
  const x = bar.left;
  const y = Math.max(box.top, Math.min(bar.top, box.bottom - height));
  if (x < box.left - 1 || x > box.right + 1 || x < 0 || y < 0 || x > innerWidth || y + height > innerHeight)
    return null;
  const hit = document.elementFromPoint(Math.min(x, box.right - 1), y + height / 2);
  if (!hit || !field.contains(hit)) return null;
  /** @type {CaretRect[]} */
  const clipped = [];
  for (const rect of rects) {
    const left = Math.max(rect.left, box.left);
    const top = Math.max(rect.top, box.top);
    const right = Math.min(rect.right, box.right);
    const bottom = Math.min(rect.bottom, box.bottom);
    if (right > left && bottom > top) clipped.push({ left, top, width: right - left, height: bottom - top });
    if (clipped.length === MAX_RECTS) break;
  }
  return { bar: { x, y, height }, rects: clipped };
}

const UNSHARED = ['password', 'file', 'hidden', 'submit', 'button', 'reset', 'image'];

/**
 * The name of the shared form control `element` is in, a choice widget or a rich editor's included,
 * so peers can mark that field even where it shows no caret.
 */
export function focusedField(element = document.activeElement) {
  if (!(element instanceof Element) || !element.closest('form[data-collaborate]')) return null;
  /** @type {any} */
  let control = /** @type {any} */ (element).name ? element : null;
  // A rich editor edits its source textarea; a widget's search input sits beside the select it fills.
  control ||= element.closest('.ck-editor__editable')?.ckeditorInstance?.sourceElement;
  // A collection entry's own row holds its buttons and help, not a field: focus there names none.
  const row = rowOf(element);
  if (row && !row.matches(entrySelector())) {
    control ||= row.querySelector('select[name], textarea[name], input[name]:not([type="hidden"])');
  }
  if (!control?.name || UNSHARED.includes(control.type) || control.name.length > 512) return null;
  return control.name;
}
