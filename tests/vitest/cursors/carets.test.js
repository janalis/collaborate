import {
  captureCaret,
  domPosition,
  focusedField,
  MAX_CARET_OFFSET,
  resolveCaret,
  sameCaret,
  textOffset,
} from '../../../assets/cursors/carets.js';

const box = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });
const fieldBox = box(10, 20, 300, 100);
let spanBox;
let mirrorStyles;
let layer;

function editable(html) {
  const root = document.querySelector('[data-editable]');
  root.innerHTML = html;
  Object.defineProperty(root, 'isContentEditable', { configurable: true, value: true });
  return root;
}

beforeEach(() => {
  document.body.innerHTML =
    '<main class="content-wrapper"><textarea name="notes"></textarea><input name="title"><div data-editable="1" id="body"></div><select name="kind"></select></main><div data-collaborate-layer></div>';
  layer = document.querySelector('[data-collaborate-layer]');
  vi.stubGlobal('CSS', { escape: (value) => value });
  spanBox = box(30, 25, 0, 16);
  mirrorStyles = [];
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function () {
    if (this.localName === 'span') {
      const { whiteSpace, lineHeight, paddingRight } = this.parentElement.style;
      mirrorStyles.push({ whiteSpace, lineHeight, paddingRight });
      return spanBox;
    }
    return fieldBox;
  });
  document.elementFromPoint = vi.fn(() => document.querySelector('textarea'));
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  delete Range.prototype.getClientRects;
});

describe('editable text offsets', () => {
  const html =
    '<p>ab</p><p>c<strong>d</strong>⁠⁠e</p><span class="ck-fake-selection-container">zz</span><br data-cke-filler="true">';

  test('count blocks once and skip the inline filler and editor-only UI', () => {
    const root = editable(html);
    const [first, second] = root.querySelectorAll('p');
    expect(textOffset(root, root, 0)).toBe(0);
    expect(textOffset(root, first.firstChild, 1)).toBe(2);
    expect(textOffset(root, second.lastChild, 2)).toBe(6);
    expect(textOffset(root, root, root.childNodes.length)).toBe(7);
    expect(textOffset(root, root.querySelector('span').firstChild, 1)).toBe(7);
  });

  test('map offsets back to DOM positions, after any filler and clamped to the end', () => {
    const root = editable(html);
    const [first, second] = root.querySelectorAll('p');
    expect(domPosition(root, 0)).toEqual({ node: root, offset: 0 });
    expect(domPosition(root, 1)).toEqual({ node: first, offset: 0 });
    expect(domPosition(root, 2)).toEqual({ node: first.firstChild, offset: 1 });
    expect(domPosition(root, 6)).toEqual({ node: root.querySelector('strong').firstChild, offset: 1 });
    expect(domPosition(root, 7)).toEqual({ node: second.lastChild, offset: 3 });
    expect(domPosition(root, 99)).toEqual({ node: root, offset: root.childNodes.length });
    for (let offset = 0; offset <= 7; offset++) {
      const { node, offset: local } = domPosition(root, offset);
      expect(textOffset(root, node, local)).toBe(offset);
    }
  });
});

describe('captureCaret', () => {
  test('reads a text control selection with its focus last', () => {
    const textarea = document.querySelector('textarea');
    textarea.value = 'hello world';
    textarea.setSelectionRange(2, 7, 'backward');
    expect(captureCaret(textarea)).toEqual({ region: 'content', selector: 'textarea[name="notes"]', start: 7, end: 2 });
    textarea.setSelectionRange(2, 7, 'forward');
    expect(captureCaret(textarea)).toMatchObject({ start: 2, end: 7 });
    textarea.focus();
    expect(captureCaret()).toMatchObject({ start: 2, end: 7 });
  });

  test('reads an editable selection in both directions', () => {
    const root = editable('<p>ab</p><p>cd</p>');
    const [first, second] = root.querySelectorAll('p');
    getSelection().setBaseAndExtent(second.firstChild, 1, first.firstChild, 0);
    expect(captureCaret(root)).toEqual({ region: 'content', selector: 'div[id="body"]', start: 5, end: 1 });
    getSelection().removeAllRanges();
    expect(captureCaret(root)).toBeNull();
    getSelection().setBaseAndExtent(document.body, 0, document.body, 0);
    expect(captureCaret(root)).toBeNull();
  });

  test('ignores fields it cannot share or name', () => {
    expect(captureCaret(null)).toBeNull();
    expect(captureCaret(document.querySelector('select'))).toBeNull();
    const own = document.createElement('textarea');
    layer.append(own);
    expect(captureCaret(own)).toBeNull();
    document
      .querySelector('main')
      .insertAdjacentHTML(
        'beforeend',
        '<div class="monaco-editor"><textarea class="inputarea">source</textarea></div>'
      );
    const proxy = document.querySelector('.inputarea');
    proxy.setSelectionRange(1, 2);
    expect(captureCaret(proxy)).toBeNull();
    const input = document.querySelector('input');
    Object.defineProperty(input, 'selectionStart', { value: null });
    expect(captureCaret(input)).toBeNull();
    const main = document.querySelector('main');
    main.innerHTML = `${'<div>'.repeat(100)}<textarea></textarea>${'</div>'.repeat(100)}`;
    expect(captureCaret(main.querySelector('textarea'))).toBeNull();
  });

  test('refuses offsets beyond the protocol bound', () => {
    const textarea = document.querySelector('textarea');
    textarea.value = 'x'.repeat(MAX_CARET_OFFSET + 1);
    textarea.setSelectionRange(MAX_CARET_OFFSET + 1, MAX_CARET_OFFSET + 1);
    expect(captureCaret(textarea)).toBeNull();
  });
});

test('compares carets by place and offsets', () => {
  const caret = { region: 'content', selector: 'x', start: 1, end: 2 };
  expect(sameCaret(null, null)).toBe(true);
  expect(sameCaret(caret, { ...caret })).toBe(true);
  expect(sameCaret(caret, null)).toBe(false);
  expect(sameCaret(null, caret)).toBe(false);
  for (const change of [{ region: 'body' }, { selector: 'y' }, { start: 0 }, { end: 3 }])
    expect(sameCaret(caret, { ...caret, ...change })).toBe(false);
});

describe('resolveCaret', () => {
  const caret = (overrides = {}) => ({
    region: 'content',
    selector: 'textarea[name="notes"]',
    start: 2,
    end: 5,
    ...overrides,
  });

  test('lays out a text control copy and tints its clipped selection', () => {
    const textarea = document.querySelector('textarea');
    textarea.value = 'hello world';
    const rects = [box(0, 30, 50, 16), box(400, 30, 20, 16), ...Array.from({ length: 70 }, () => box(40, 40, 10, 16))];
    vi.spyOn(Element.prototype, 'getClientRects').mockReturnValue(rects);
    const geometry = resolveCaret(caret(), layer);
    expect(geometry.bar).toEqual({ x: 30, y: 25, height: 16 });
    expect(geometry.rects[0]).toEqual({ left: 10, top: 30, width: 40, height: 16 });
    expect(geometry.rects).toHaveLength(64);
    expect(layer.children).toHaveLength(0);
    expect(mirrorStyles[0].whiteSpace).toBe('pre-wrap');
    expect(resolveCaret(caret({ start: 50, end: 50 }), layer).rects).toEqual([]);
    expect(resolveCaret(caret({ start: 5, end: 2 }), layer).bar.x).toBe(30);
  });

  test('centres a single-line input and pads for a scrollbar', () => {
    const input = document.querySelector('input');
    input.value = 'title';
    input.style.padding = '4px';
    input.style.border = '0';
    Object.defineProperty(input, 'clientHeight', { configurable: true, value: 38 });
    Object.defineProperty(input, 'offsetWidth', { value: 320 });
    Object.defineProperty(input, 'clientWidth', { value: 300 });
    document.elementFromPoint.mockReturnValue(input);
    expect(resolveCaret(caret({ selector: 'input[name="title"]', start: 1, end: 1 }), layer)).not.toBeNull();
    expect(mirrorStyles[0].lineHeight).toBe('30px');
    expect(mirrorStyles[0].paddingRight).toBe('24px');
    expect(mirrorStyles[0].whiteSpace).toBe('pre');
    Object.defineProperty(input, 'clientHeight', { value: 0 });
    resolveCaret(caret({ selector: 'input[name="title"]', start: 1, end: 1 }), layer);
    expect(mirrorStyles[1].lineHeight).not.toBe('30px');
  });

  test('measures an editable range and falls back to the line box of an empty block', () => {
    const root = editable('<p>abc</p><p></p>');
    document.elementFromPoint.mockReturnValue(root.querySelector('p'));
    Range.prototype.getClientRects = function () {
      return this.collapsed ? [box(50, 22, 0, 18)] : [box(20, 22, 30, 18)];
    };
    const at = (start, end) => resolveCaret({ region: 'content', selector: 'div[id="body"]', start, end }, layer);
    expect(at(1, 3)).toEqual({
      bar: { x: 50, y: 22, height: 18 },
      rects: [{ left: 20, top: 22, width: 30, height: 18 }],
    });
    Range.prototype.getClientRects = () => [];
    expect(at(5, 5)).toEqual({ bar: { x: 10, y: 20, height: 100 }, rects: [] });
    expect(at(2, 2).bar.x).toBe(10);
  });

  test('hides carets whose field is gone, empty, scrolled away or covered', () => {
    const textarea = document.querySelector('textarea');
    textarea.value = 'hello';
    vi.spyOn(Element.prototype, 'getClientRects').mockReturnValue([]);
    expect(resolveCaret(null, layer)).toBeNull();
    expect(resolveCaret(caret({ selector: 'textarea[name="missing"]' }), layer)).toBeNull();
    expect(resolveCaret(caret({ selector: 'select[name="kind"]' }), layer)).toBeNull();
    spanBox = box(500, 25, 0, 16);
    expect(resolveCaret(caret(), layer)).toBeNull();
    spanBox = box(30, 25, 0, 16);
    vi.stubGlobal('innerHeight', 30);
    expect(resolveCaret(caret(), layer)).toBeNull();
    vi.stubGlobal('innerHeight', 800);
    document.elementFromPoint.mockReturnValue(document.body);
    expect(resolveCaret(caret(), layer)).toBeNull();
    document.elementFromPoint.mockReturnValue(null);
    expect(resolveCaret(caret(), layer)).toBeNull();
    Element.prototype.getBoundingClientRect.mockImplementation(function () {
      return this.localName === 'textarea' ? box(0, 0, 0, 0) : fieldBox;
    });
    expect(resolveCaret(caret(), layer)).toBeNull();
  });
});

test('names the shared form control in focus, through a choice widget or a rich editor, never an unshared one', () => {
  document.body.innerHTML =
    '<form data-collaborate="{}">' +
    '<input name="Record[title]">' +
    '<div class="form-group"><select name="Record[owner]"></select><div class="ts-wrapper"><input class="search"></div></div>' +
    '<textarea name="Record[body]"></textarea><div class="ck-editor__editable"><p>Text</p></div>' +
    '<input type="password" name="Record[password]"><input type="file" name="Record[file]">' +
    `<input name="${'x'.repeat(513)}"><span id="loose"></span>` +
    '</form><input name="outside">';
  const form = document.querySelector('form');
  expect(focusedField(form.querySelector('[name="Record[title]"]'))).toBe('Record[title]');
  expect(focusedField(form.querySelector('.search'))).toBe('Record[owner]');
  const editable = form.querySelector('.ck-editor__editable');
  editable.ckeditorInstance = { sourceElement: form.querySelector('textarea') };
  expect(focusedField(editable.querySelector('p'))).toBe('Record[body]');
  expect(focusedField(form.querySelector('[type="password"]'))).toBeNull();
  expect(focusedField(form.querySelector('[type="file"]'))).toBeNull();
  expect(focusedField(form.querySelector(`[name="${'x'.repeat(513)}"]`))).toBeNull();
  expect(focusedField(form.querySelector('#loose'))).toBeNull();
  expect(focusedField(document.querySelector('[name="outside"]'))).toBeNull();
  expect(focusedField(null)).toBeNull();
  form.querySelector('[name="Record[title]"]').focus();
  expect(focusedField()).toBe('Record[title]');
});
