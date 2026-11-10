// EasyAdmin's markup: its bridge's profile.
import '../../../assets/bridges/easyadmin.js';
import {
  anchorPath,
  CURSOR_COLORS,
  CURSOR_SHAPE_NAMES,
  CURSOR_SHAPES,
  captureAnchor,
  cursorColor,
  cursorStyle,
  interpolate,
  isTextControl,
  pointerShape,
  resolveAnchor,
  resolvePath,
  shapeHotspot,
} from '../../../assets/cursors/geometry.js';

const box = (left = 10, top = 20, width = 200, height = 100) => ({
  left,
  top,
  width,
  height,
  right: left + width,
  bottom: top + height,
});

beforeEach(() => {
  document.body.innerHTML =
    '<header class="content-top"><a id="profile">Profile</a></header><aside class="sidebar"></aside><main class="content-wrapper"><table><tbody><tr data-id="42"><td data-column="name"><input name="title"></td></tr></tbody></table></main>';
  document.elementFromPoint = vi.fn(() => document.querySelector('input'));
  vi.stubGlobal('CSS', { escape: (value) => value.replaceAll('"', '\\"') });
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue(box());
});
afterEach(() => vi.restoreAllMocks());

test('uses every Bootstrap base color in stable modulo order', () => {
  expect(CURSOR_COLORS).toEqual([
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
  ]);
  for (let id = 0; id < 42; id++) expect(cursorColor(id)).toBe(CURSOR_COLORS[id % 14]);
});

test.each([
  ['mouse', 'MacIntel', 'mac'],
  ['mouse', 'Win32', 'windows'],
  ['pen', 'Linux x86_64', 'ubuntu'],
  ['mouse', 'Ubuntu', 'ubuntu'],
  ['touch', 'MacIntel', 'touch'],
  ['mouse', 'unknown', 'windows'],
  ['mouse', 'iPad', 'mac'],
])('detects %s on %s', (pointer, platform, expected) => {
  expect(cursorStyle(pointer, platform)).toBe(expected);
});

test('uses browser platform information by default and has three different arrow paths', () => {
  expect(['mac', 'windows', 'ubuntu']).toContain(cursorStyle('mouse'));
  expect(new Set(Object.values(CURSOR_SHAPES).map((shapes) => shapes.default.d)).size).toBe(3);
});

test('draws every shape on every platform with its own silhouette and a hotspot in the box', () => {
  expect(CURSOR_SHAPE_NAMES).toEqual([
    'default',
    'pointer',
    'text',
    'vertical-text',
    'grab',
    'grabbing',
    'move',
    'not-allowed',
    'help',
    'wait',
    'progress',
    'crosshair',
    'col-resize',
    'row-resize',
    'ew-resize',
    'ns-resize',
    'nesw-resize',
    'nwse-resize',
  ]);
  for (const name of CURSOR_SHAPE_NAMES) {
    const paths = ['mac', 'windows', 'ubuntu'].map((platform) => CURSOR_SHAPES[platform][name]);
    for (const shape of paths) {
      expect(shape.d).toMatch(/^M[-\d. ,MLAHVCSZhvacslz]+$/);
      expect(shape.d).not.toMatch(/NaN|undefined/);
      for (const value of shapeHotspot(shape)) {
        expect(value).toBeGreaterThanOrEqual(0);
        expect(value).toBeLessThanOrEqual(32);
      }
    }
    expect(new Set(paths.map((shape) => shape.d)).size).toBe(3);
  }
});

test('rotates hotspots with their shape', () => {
  expect(shapeHotspot({ hotspot: [16, 17] })).toEqual([16, 17]);
  expect(shapeHotspot({ hotspot: [16, 17], rotate: 90 })).toEqual([15, 16]);
  expect(shapeHotspot(CURSOR_SHAPES.mac['ns-resize'])).toEqual([16, 16]);
  expect(CURSOR_SHAPES.windows['vertical-text'].rotate).toBe(90);
});

describe('pointerShape', () => {
  const styled = (cursor, userSelect = 'auto') =>
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ cursor, userSelect });

  test.each([
    ['pointer', 'pointer'],
    ['url("hand.svg") 4 4, pointer', 'pointer'],
    ['col-resize', 'col-resize'],
    ['e-resize', 'ew-resize'],
    ['all-scroll', 'move'],
    ['se-resize', 'nwse-resize'],
    ['default', 'default'],
    ['cell', 'default'],
    ['none', 'default'],
  ])('maps the computed %s cursor to %s', (cursor, expected) => {
    styled(cursor);
    expect(pointerShape(document.querySelector('a'), 0, 0)).toBe(expected);
  });

  test('shows an I-beam for automatic cursors over editable fields and glyphs only', () => {
    styled('auto');
    const input = document.querySelector('input');
    expect(pointerShape(input, 0, 0)).toBe('text');
    input.disabled = true;
    document.caretPositionFromPoint = undefined;
    document.caretRangeFromPoint = undefined;
    expect(pointerShape(input, 0, 0)).toBe('default');
    const editable = document.createElement('div');
    Object.defineProperty(editable, 'isContentEditable', { value: true });
    expect(pointerShape(editable, 0, 0)).toBe('text');
    expect(pointerShape(null, 0, 0)).toBe('default');

    const text = document.querySelector('a').firstChild;
    const glyph = { left: 10, right: 18, top: 20, bottom: 36 };
    // jsdom does no layout: ranges have no boxes of their own.
    Range.prototype.getClientRects = () => [glyph];
    document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: text, offset: 1 }));
    expect(pointerShape(document.querySelector('a'), 12, 30)).toBe('text');
    expect(pointerShape(document.querySelector('a'), 40, 30)).toBe('default');
    document.caretPositionFromPoint = undefined;
    document.caretRangeFromPoint = vi.fn(() => ({ startContainer: text, startOffset: 0 }));
    expect(pointerShape(document.querySelector('a'), 12, 30)).toBe('text');
    document.caretRangeFromPoint = vi.fn(() => ({ startContainer: document.body, startOffset: 0 }));
    expect(pointerShape(document.querySelector('a'), 12, 30)).toBe('default');
    document.querySelector('a').textContent = '  ';
    document.caretRangeFromPoint = vi.fn(() => ({
      startContainer: document.querySelector('a').firstChild,
      startOffset: 1,
    }));
    expect(pointerShape(document.querySelector('a'), 12, 30)).toBe('default');
    styled('', 'none');
    document.caretRangeFromPoint = vi.fn(() => ({ startContainer: text, startOffset: 0 }));
    expect(pointerShape(document.querySelector('a'), 12, 30)).toBe('default');
    delete document.caretRangeFromPoint;
    delete document.caretPositionFromPoint;
    delete Range.prototype.getClientRects;
  });
});

test('recognises shareable text controls only', () => {
  const make = (html) => {
    const template = document.createElement('template');
    template.innerHTML = html;
    return template.content.firstChild;
  };
  expect(isTextControl(make('<textarea></textarea>'))).toBe(true);
  for (const type of ['text', 'search', 'url', 'tel']) expect(isTextControl(make(`<input type="${type}">`))).toBe(true);
  for (const type of ['password', 'hidden', 'checkbox', 'email', 'number'])
    expect(isTextControl(make(`<input type="${type}">`))).toBe(false);
  expect(isTextControl(make('<div></div>'))).toBe(false);
});

test('names an element by its region path and finds it again', () => {
  const input = document.querySelector('input');
  const path = anchorPath(input);
  expect(path.region).toBe('content');
  expect(resolvePath(path)).toBe(input);
  expect(anchorPath(null)).toBeNull();
  expect(resolvePath({ region: 'content', selector: '[' })).toBeNull();
  expect(resolvePath({ region: 'unknown', selector: '' })).toBeNull();
});

test('passes over ids drawn anew on every render, which no other page shares', () => {
  const input = document.querySelector('input');
  const fieldset = document.createElement('div');
  input.parentElement.append(fieldset);
  fieldset.append(input);
  for (const id of ['content-ea_form_fieldset_01M69G2XVM9WXXTGAVXHAHXH4T', 'f-3f2b8a1c-9d4e-4f6a-8b7c-2d1e0f9a8b7c']) {
    fieldset.id = id;
    const path = anchorPath(input);
    expect(path.selector).not.toContain(id);
    expect(path.selector).toContain('div:nth-of-type(');
    expect(resolvePath(path)).toBe(input);
  }
  fieldset.id = 'details';
  expect(anchorPath(input).selector).toContain('div[id="details"]');
});

test('anchors a point to semantic record and field identifiers', () => {
  const input = document.querySelector('input');
  const anchor = captureAnchor(input, 110, 70);
  expect(anchor.region).toBe('content');
  expect(anchor.selector).toContain('tr[data-id="42"]');
  expect(anchor.selector).toContain('td[data-column="name"]');
  expect(anchor.selector).toContain('input[name="title"]');
  expect(resolveAnchor(anchor)).toEqual({ x: 110, y: 70 });
  input.getBoundingClientRect = () => box(100, 200, 400, 200);
  expect(resolveAnchor(anchor)).toEqual({ x: 300, y: 300 });
});

test('anchors the header, sidebar and region root independently', () => {
  expect(captureAnchor(document.querySelector('a'), 10, 20).selector).toBe('a[id="profile"]');
  document.elementFromPoint.mockReturnValue(document.querySelector('aside'));
  const sidebar = captureAnchor(document.querySelector('aside'), 110, 70);
  expect(sidebar).toEqual({ region: 'sidebar', selector: '', x: 0.5, y: 0.5 });
  expect(resolveAnchor(sidebar)).toEqual({ x: 110, y: 70 });
  expect(captureAnchor(document.body, -100, 500)).toEqual({ region: 'body', selector: '', x: 0, y: 1 });
});

test('finds the sidebar of EasyAdmin 5.6 and later', () => {
  document.querySelector('aside').className = 'ea-sidebar';
  document.elementFromPoint.mockReturnValue(document.querySelector('aside'));
  const sidebar = captureAnchor(document.querySelector('aside'), 110, 70);
  expect(sidebar).toEqual({ region: 'sidebar', selector: '', x: 0.5, y: 0.5 });
  expect(resolveAnchor(sidebar)).toEqual({ x: 110, y: 70 });
});

test('rejects missing, zero-sized, oversized or invalid anchors and its own overlay', () => {
  expect(captureAnchor(null, 0, 0)).toBeNull();
  document.body.insertAdjacentHTML('beforeend', '<div data-collaborate-layer><span></span></div>');
  expect(captureAnchor(document.querySelector('[data-collaborate-layer] span'), 1, 1)).toBeNull();
  const input = document.querySelector('input');
  input.getBoundingClientRect = () => box(0, 0, 0, 0);
  expect(captureAnchor(input, 1, 1)).toBeNull();
  expect(resolveAnchor(null)).toBeNull();
  expect(resolveAnchor({ region: 'unknown' })).toBeNull();
  expect(resolveAnchor({ region: 'modal' })).toBeNull();
  expect(resolveAnchor({ region: 'content', selector: '[' })).toBeNull();
  expect(resolveAnchor({ region: 'content', selector: 'missing' })).toBeNull();
  expect(resolveAnchor({ region: 'content', selector: 'table > tbody > tr > td > input', x: 0, y: 0 })).toBeNull();
  input.getBoundingClientRect = () => box(-100, 0, 10, 10);
  expect(resolveAnchor({ region: 'content', selector: 'table > tbody > tr > td > input', x: 0, y: 0 })).toBeNull();
  input.getBoundingClientRect = () => box(2000, 0, 10, 10);
  expect(resolveAnchor({ region: 'content', selector: 'table > tbody > tr > td > input', x: 0, y: 0 })).toBeNull();
  input.getBoundingClientRect = () => box(0, -10, 10, 10);
  expect(resolveAnchor({ region: 'content', selector: 'table > tbody > tr > td > input', x: 0, y: 0 })).toBeNull();
  input.getBoundingClientRect = () => box(0, 2000, 10, 10);
  expect(resolveAnchor({ region: 'content', selector: 'table > tbody > tr > td > input', x: 0, y: 0 })).toBeNull();
});

test('hides positions that the browser reports as clipped or occluded', () => {
  document.elementFromPoint.mockReturnValue(document.body);
  const main = document.querySelector('main');
  main.style.overflowX = 'auto';
  main.getBoundingClientRect = () => box(0, 0, 20, 20);
  const anchor = captureAnchor(document.querySelector('input'), 110, 70);
  expect(resolveAnchor(anchor)).toBeNull();
  main.style.overflowX = '';
  main.style.overflowY = 'hidden';
  expect(resolveAnchor(anchor)).toBeNull();
});

test('refuses excessive selector depth instead of publishing an unrelated position', () => {
  const main = document.querySelector('main');
  main.innerHTML = `${'<div>'.repeat(100)}<span>deep</span>${'</div>'.repeat(100)}`;
  expect(captureAnchor(main.querySelector('span'), 1, 1)).toBeNull();
});

test('interpolates within 100ms, clamps time and respects reduced motion', () => {
  const from = { x: 0, y: 100 };
  const to = { x: 100, y: 0 };
  expect(interpolate(from, to, 50)).toEqual({ x: 50, y: 50 });
  expect(interpolate(from, to, -10)).toEqual(from);
  expect(interpolate(from, to, 200)).toEqual(to);
  expect(interpolate(from, to, 0, true)).toEqual(to);
});

test('keeps the toolbar separate from the visible sidebar logo and mobile header', () => {
  document.body.insertAdjacentHTML(
    'afterbegin',
    '<div class="responsive-header"><a>Mobile</a></div><header class="main-header"><a>Logo</a></header>'
  );
  const toolbar = document.querySelector('.content-top a');
  toolbar.getBoundingClientRect = () => box(500, 10, 100, 20);
  document.elementFromPoint.mockReturnValue(toolbar);
  const anchor = captureAnchor(toolbar, 550, 20);
  expect(anchor.region).toBe('header');
  expect(resolveAnchor(anchor)).toEqual({ x: 550, y: 20 });
  document.elementFromPoint.mockReturnValue(document.querySelector('.responsive-header a'));
  const mobileAnchor = captureAnchor(document.querySelector('.responsive-header a'), 110, 70);
  expect(mobileAnchor.region).toBe('mobile-header');
  expect(resolveAnchor(mobileAnchor)).toEqual({ x: 110, y: 70 });
});

test('trusts the browser for fixed modals escaping scrolling ancestors', () => {
  document.querySelector('main').innerHTML =
    '<div class="modal show" style="position:fixed"><button id="close">Close</button></div>';
  const main = document.querySelector('main');
  main.style.overflowY = 'auto';
  main.getBoundingClientRect = () => box(0, 300, 200, 100);
  const button = document.querySelector('button');
  button.getBoundingClientRect = () => box(10, 10, 50, 20);
  document.elementFromPoint.mockReturnValue(button);
  const anchor = captureAnchor(button, 35, 20);
  expect(resolveAnchor(anchor)).toEqual({ x: 35, y: 20 });
  document.elementFromPoint.mockReturnValue(null);
  expect(resolveAnchor(anchor)).toBeNull();
});
