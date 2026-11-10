// EasyAdmin's markup: its bridge's profile.
import '../../../assets/bridges/easyadmin.js';
import {
  anchorPath,
  capturePopup,
  configureGeometry,
  findRecord,
  interpolate,
  isPopup,
  keyOf,
  measure,
  offscreenAnchor,
  regionNames,
  resolveAnchor,
  resolvePath,
  sameAnchor,
  samePopup,
  seen,
  sharesCaret,
} from '../../../assets/cursors/geometry.js';

// A host's file listing: a toolbar, a listing of records shown as rows or tiles, an inspector
// showing the record selected, and a dialog holding a popup of a record.
const page = (listing = 'rows') => `
  <nav id="toolbar"><button data-action="new">New</button><input id="search" name="q"></nav>
  <main id="listing"><div class="pane">${
    listing === 'rows'
      ? '<table><thead><tr><th>Name</th></tr></thead><tbody><tr data-key="a" data-collaborate-label="a.mp3"><td class="name"><span>a.mp3</span></td><td class="size">1 kB</td></tr><tr data-key="b"><td class="name"><span>b.mp3</span></td><td class="size">2 kB</td></tr></tbody></table>'
      : '<ul><li data-key="a"><span class="name">a.mp3</span><span class="size">1 kB</span></li><li data-key="b"><span class="name">b.mp3</span></li></ul>'
  }</div></main>
  <aside id="inspector"><div data-key="a"><h2>a.mp3</h2></div><div data-key="secret"><h2>Mine</h2></div></aside>
  <dialog id="popup"><div data-key="a" data-collaborate-popup="share"><input name="title"></div></dialog>`;

const box = (left = 10, top = 20, width = 200, height = 100) => ({
  left,
  top,
  width,
  height,
  right: left + width,
  bottom: top + height,
});

const host = {
  regions: { modal: 'dialog[open]', toolbar: '#toolbar', listing: '#listing', inspector: '#inspector', body: 'body' },
  keyAttribute: 'data-key',
  keyScope: '#listing',
  anchorAttributes: ['data-action', 'name', 'id'],
  anchorClasses: true,
  caretRegions: ['modal'],
};

beforeEach(() => {
  document.body.innerHTML = page();
  vi.stubGlobal('CSS', { escape: (value) => value.replaceAll('"', '\\"') });
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue(box());
  document.elementFromPoint = vi.fn((_x, _y) => document.querySelector('td span'));
  configureGeometry(host);
});
afterEach(() => {
  configureGeometry();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

test('the host names its regions, and only its caret regions share a caret', () => {
  expect(regionNames()).toEqual(['modal', 'toolbar', 'listing', 'inspector', 'body']);
  expect(sharesCaret('modal')).toBe(true);
  expect(sharesCaret('toolbar')).toBe(false);
  configureGeometry();
  expect(regionNames()).toEqual(['modal', 'header', 'mobile-header', 'sidebar', 'content', 'body']);
  expect(sharesCaret('content')).toBe(true);
});

test('a path starts at the record an element is in, and is found in the other layout of the record', () => {
  const name = document.querySelector('tr[data-key="a"] td.name span');
  const path = anchorPath(name);
  expect(path).toEqual({ region: 'listing', selector: 'tr[data-key="a"] > td.name > span:nth-of-type(1)' });
  // The tile of the record holds no cell: the last step is looked for in it, then the record itself.
  document.body.innerHTML = page('tiles');
  expect(resolvePath(path)).toBe(document.querySelector('li[data-key="a"] span'));
  expect(resolvePath({ region: 'listing', selector: 'tr[data-key="a"] > td > em' })).toBe(
    document.querySelector('li[data-key="a"]')
  );
  expect(resolvePath({ region: 'listing', selector: 'tr[data-key="a"]' })).toBe(
    document.querySelector('li[data-key="a"]')
  );
  expect(resolvePath({ region: 'listing', selector: 'tr[data-key="z"] > td' })).toBeNull();
  expect(resolvePath({ region: 'listing', selector: 'tr[data-key="a"] > td[' })).toBe(
    document.querySelector('li[data-key="a"]')
  );
});

test('an element is named by its action, its name, then a class no sibling shares, then its rank', () => {
  expect(anchorPath(document.querySelector('[data-action]')).selector).toBe('button[data-action="new"]');
  expect(anchorPath(document.querySelector('#search')).selector).toBe('input[name="q"]');
  expect(anchorPath(document.querySelector('td.size')).selector).toBe('tr[data-key="a"] > td.size');
  configureGeometry({ ...host, anchorClasses: false });
  expect(anchorPath(document.querySelector('td.size')).selector).toBe('tr[data-key="a"] > td:nth-of-type(2)');
  // A long attribute names nothing; a key, however long, names its record.
  configureGeometry({ ...host, anchorAttributes: ['title'] });
  const long = document.querySelector('[data-action]');
  long.title = 'x'.repeat(160);
  expect(anchorPath(long).selector).toBe('button:nth-of-type(1)');
  document.querySelector('tbody tr').dataset.key = 'k'.repeat(300);
  expect(anchorPath(document.querySelector('td.name')).selector).toContain('k'.repeat(300));
});

test('a record the page keeps to its reader is never named', () => {
  // The inspector shows record a, which the listing shows the peers, and a record of the reader's own.
  expect(anchorPath(document.querySelector('#inspector [data-key="a"] h2')).selector).toBe(
    'div[data-key="a"] > h2:nth-of-type(1)'
  );
  expect(anchorPath(document.querySelector('#inspector [data-key="secret"] h2'))).toBeNull();
  // Out of every region, and on the layer, nothing is named.
  configureGeometry({ ...host, regions: { listing: '#listing' } });
  expect(anchorPath(document.querySelector('#search'))).toBeNull();
});

test('in a popup, a path keeps the kind of its record and names nothing of another popup', () => {
  const popup = document.querySelector('dialog');
  popup.setAttribute('open', '');
  const path = anchorPath(popup.querySelector('input'));
  expect(path).toEqual({ region: 'modal', selector: 'div[data-key="a"] > input[name="title"]' });
  expect(resolvePath(path)).toBe(popup.querySelector('input'));
  expect(resolvePath({ region: 'modal', selector: 'section[data-key="a"] > input[name="title"]' })).toBeNull();
  expect(resolvePath({ region: 'modal', selector: 'input[name="title"]' })).toBeNull();
  expect(resolvePath({ region: 'modal', selector: '' })).toBeNull();
  expect(resolvePath({ region: 'toolbar', selector: '' })).toBe(document.querySelector('#toolbar'));
  expect(resolvePath({ region: 'toolbar', selector: 'input[name="q"]' })).toBe(document.querySelector('#search'));
  expect(resolvePath({ region: 'unknown', selector: '' })).toBeNull();
  expect(resolvePath({ region: 'constructor', selector: '' })).toBeNull();
});

test('a record is found among the ones the page shows, by its key compared rather than queried', () => {
  expect(findRecord('a')).toBe(document.querySelector('tr[data-key="a"]'));
  expect(findRecord('"] *, [x="')).toBeNull();
  expect(findRecord('secret')).toBeNull();
  expect(findRecord(null)).toBeNull();
  configureGeometry({ ...host, keyAttribute: null });
  expect(findRecord('a')).toBeNull();
});

test('a frame finds each region, path and record once, as it would one by one, and forgets them after', () => {
  // A popup of a record and the reader's own records come before the listing's: none of them is found.
  document.body.insertAdjacentHTML('afterbegin', '<div data-collaborate-popup="share"><p data-key="b"></p></div>');
  configureGeometry({ ...host, keyScope: 'body' });
  const one = { region: 'listing', selector: 'tr[data-key="b"] > td.name' };
  const lookups = vi.spyOn(document, 'querySelectorAll');
  const found = measure(() => {
    const first = [findRecord('a'), findRecord('b'), findRecord('gone'), resolvePath(one)];
    // A frame within a frame shares its lookups.
    const again = measure(() => [findRecord('a'), findRecord('b'), resolvePath(one), resolvePath(one)]);
    return { first, again, lookups: lookups.mock.calls.length };
  });
  expect(found.first).toEqual([
    document.querySelector('tr[data-key="a"]'),
    document.querySelector('tr[data-key="b"]'),
    null,
    document.querySelector('tr[data-key="b"] > td.name'),
  ]);
  expect(found.again).toEqual([found.first[0], found.first[1], found.first[3], found.first[3]]);
  // The scopes of the records, and the region of the path: each once.
  expect(found.lookups).toBe(2);
  expect([findRecord('a'), findRecord('b')]).toEqual([found.first[0], found.first[1]]);
  // Out of the frame, the page is read again: a record gone is gone.
  document.querySelector('tr[data-key="b"]').remove();
  lookups.mockClear();
  expect(findRecord('b')).toBeNull();
  expect(resolvePath(one)).toBeNull();
  expect(lookups).toHaveBeenCalledTimes(2);
  // A frame that throws forgets them too.
  expect(() =>
    measure(() => {
      findRecord('a');
      throw new Error('drawn');
    })
  ).toThrow('drawn');
  document.querySelector('tr[data-key="a"]').remove();
  expect(findRecord('a')).toBe(document.querySelector('#inspector [data-key="a"]'));
});

test('the key of the record a pointer is on', () => {
  expect(keyOf({ region: 'listing', selector: 'tr[data-key="b"] > td.name' })).toBe('b');
  expect(keyOf({ region: 'inspector', selector: 'div[data-key="a"] > h2' })).toBeNull();
  expect(keyOf({ region: 'toolbar', selector: 'input[name="q"]' })).toBeNull();
  expect(keyOf({ region: 'listing', selector: 'tr[data-key="gone"]' })).toBeNull();
  expect(keyOf(null)).toBeNull();
  configureGeometry({ ...host, keyAttribute: null });
  expect(keyOf({ region: 'listing', selector: 'tr[data-key="b"]' })).toBeNull();
});

test('the popup of a record the reader has open, which peers see', () => {
  const dialog = document.querySelector('dialog');
  expect(capturePopup(['share'])).toBeNull();
  dialog.setAttribute('open', '');
  expect(capturePopup(['share'])).toEqual({ kind: 'share', key: 'a' });
  expect(capturePopup(['metadata'])).toBeNull();
  expect(capturePopup([])).toBeNull();
  // A popup of a record the listing does not show, or one hidden, is the reader's alone.
  dialog.querySelector('div').dataset.key = 'secret';
  expect(capturePopup(['share'])).toBeNull();
  dialog.querySelector('div').dataset.key = 'a';
  Element.prototype.getBoundingClientRect.mockReturnValue(box(0, 0, 0, 0));
  expect(capturePopup(['share'])).toBeNull();
  configureGeometry({ ...host, keyAttribute: null });
  expect(capturePopup(['share'])).toBeNull();
});

test('popups compare by kind and key', () => {
  expect(isPopup({ kind: 'share', key: 'a' }, ['share'])).toBe(true);
  expect(isPopup({ kind: 'share', key: '' }, ['share'])).toBe(false);
  expect(isPopup({ kind: 'share', key: 'k'.repeat(256) }, ['share'])).toBe(false);
  expect(isPopup({ kind: 'share', key: 1 }, ['share'])).toBe(false);
  expect(isPopup({ kind: 'metadata', key: 'a' }, ['share'])).toBe(false);
  expect(isPopup(null, ['share'])).toBe(false);
  expect(samePopup({ kind: 'share', key: 'a' }, { kind: 'share', key: 'a' })).toBe(true);
  expect(samePopup({ kind: 'share', key: 'a' }, { kind: 'share', key: 'b' })).toBe(false);
  expect(samePopup(null, null)).toBe(true);
  const anchor = { region: 'listing', selector: 'x', x: 0.5, y: 0.5 };
  expect(sameAnchor(anchor, { ...anchor })).toBe(true);
  for (const change of [{ region: 'body' }, { selector: 'y' }, { x: 0 }, { y: 0 }])
    expect(sameAnchor(anchor, { ...anchor, ...change })).toBe(false);
  expect(sameAnchor(anchor, null)).toBe(false);
  expect(sameAnchor(null, null)).toBe(true);
});

test('a point is seen on the screen, uncovered', () => {
  const name = document.querySelector('td span');
  expect(seen(name)).toEqual({ x: 110, y: 70 });
  expect(seen(name, 0, 1)).toEqual({ x: 10, y: 120 });
  document.elementFromPoint = vi.fn(() => null);
  expect(seen(name)).toBeNull();
  document.elementFromPoint = vi.fn(() => document.body);
  expect(seen(name)).toBeNull();
  for (const hidden of [box(10, 20, 0, 10), box(10, 20, 10, 0), box(-500), box(10, -500), box(5000), box(10, 5000)]) {
    name.getBoundingClientRect = () => hidden;
    expect(seen(name)).toBeNull();
  }
  expect(resolveAnchor(null)).toBeNull();
  expect(resolveAnchor({ region: 'listing', selector: 'tr[data-key="z"]', x: 0, y: 0 })).toBeNull();
});

test('a pointer its pane scrolled out of sight points at the edge of the pane, under the head of a table', () => {
  const pane = document.querySelector('.pane');
  const row = document.querySelector('tr[data-key="b"]');
  pane.getBoundingClientRect = () => box(0, 100, 400, 300);
  document.querySelector('thead').getBoundingClientRect = () => box(0, 100, 400, 30);
  document.elementFromPoint = vi.fn(() => pane);
  const anchor = { region: 'listing', selector: 'tr[data-key="b"]', x: 0.5, y: 0.5 };
  row.getBoundingClientRect = () => box(0, 0, 400, 20);
  expect(offscreenAnchor(anchor, '.pane')).toEqual({ x: 200, y: 130, side: 'above' });
  expect(document.elementFromPoint).toHaveBeenLastCalledWith(200, 131);
  row.getBoundingClientRect = () => box(0, 600, 1000, 20);
  expect(offscreenAnchor({ ...anchor, x: 1 }, '.pane')).toEqual({ x: 384, y: 400, side: 'below' });
  expect(document.elementFromPoint).toHaveBeenLastCalledWith(384, 399);
  row.getBoundingClientRect = () => box(-100, 600, 10, 20);
  expect(offscreenAnchor({ ...anchor, x: 0 }, '.pane').x).toBe(16);
  // Seen, in no pane, of no size, or the pane covered: no arrow.
  row.getBoundingClientRect = () => box(0, 200, 400, 20);
  expect(offscreenAnchor(anchor, '.pane')).toBeNull();
  row.getBoundingClientRect = () => box(0, 0, 400, 20);
  expect(offscreenAnchor(anchor, '.elsewhere')).toBeNull();
  expect(offscreenAnchor(anchor, null)).toBeNull();
  expect(offscreenAnchor(null, '.pane')).toBeNull();
  expect(offscreenAnchor({ ...anchor, selector: 'tr[data-key="z"]' }, '.pane')).toBeNull();
  document.elementFromPoint = vi.fn(() => document.body);
  expect(offscreenAnchor(anchor, '.pane')).toBeNull();
  document.elementFromPoint = vi.fn(() => null);
  expect(offscreenAnchor(anchor, '.pane')).toBeNull();
  for (const empty of [box(0, 0, 0, 20), box(0, 0, 400, 0)]) {
    row.getBoundingClientRect = () => empty;
    expect(offscreenAnchor(anchor, '.pane')).toBeNull();
  }
  row.getBoundingClientRect = () => box(0, 0, 400, 20);
  for (const empty of [box(0, 100, 0, 300), box(0, 100, 400, 0)]) {
    pane.getBoundingClientRect = () => empty;
    expect(offscreenAnchor(anchor, '.pane')).toBeNull();
  }
  // A pane without a table head points at its own top.
  pane.getBoundingClientRect = () => box(0, 100, 400, 300);
  document.querySelector('thead').remove();
  document.elementFromPoint = vi.fn(() => pane);
  expect(offscreenAnchor(anchor, '.pane')).toEqual({ x: 200, y: 100, side: 'above' });
});

test('an interpolated point reaches its end exactly', () => {
  const to = { x: 0.1 + 0.2, y: 3 };
  expect(interpolate({ x: 0, y: 0 }, to, 100)).toBe(to);
  expect(interpolate({ x: 0, y: 0 }, to, 0, true)).toBe(to);
  expect(interpolate({ x: 0, y: 0 }, { x: 10, y: 10 }, 50)).toEqual({ x: 5, y: 5 });
});
