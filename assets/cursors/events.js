// @ts-check
// What a peer's cursor event may hold, as the server's CursorEvent checks it before relaying: an event holding
// anything else is dropped, whoever sent it.
import { MAX_CARET_OFFSET } from './carets.js';
import { CURSOR_SHAPE_NAMES, isPopup, regionNames } from './geometry.js';

const KINDS = ['move', 'hide', 'heartbeat', 'hello', 'leave'];
const STYLES = ['mac', 'windows', 'ubuntu', 'touch'];

/** @param {unknown} field */
const validField = (field) => field === null || (typeof field === 'string' && field !== '' && field.length <= 512);

/** @param {any} caret */
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

/** @param {any} anchor */
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

/**
 * The event a hub message carries, or null when it carries none a page may draw.
 *
 * @param {string} data the message's JSON
 * @param {{ colors: string[], kinds: string[], since: number }} page the colours and popup kinds of the page, and
 *   the oldest timestamp it still draws
 * @returns {any}
 */
export function peerEvent(data, { colors, kinds, since }) {
  let event;
  try {
    event = JSON.parse(data);
  } catch {
    return null;
  }
  const valid =
    !!event &&
    Number.isInteger(event.userId) &&
    typeof event.clientId === 'string' &&
    Number.isInteger(event.sequence) &&
    KINDS.includes(event.kind) &&
    colors.includes(event.color) &&
    typeof event.label === 'string' &&
    (event.avatar == null || typeof event.avatar === 'string') &&
    STYLES.includes(event.style) &&
    CURSOR_SHAPE_NAMES.includes(event.shape) &&
    validCaret(event.caret ?? null) &&
    validField(event.field ?? null) &&
    (event.popup == null || isPopup(event.popup, kinds)) &&
    Number.isFinite(event.timestamp) &&
    event.timestamp >= since &&
    validAnchor(event.anchor);

  return valid ? event : null;
}
