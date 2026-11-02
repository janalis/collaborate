import { configureGeometry } from '../../../assets/cursors/geometry.js';
import { createMarks, format, MARKED } from '../../../assets/cursors/marks.js';
import { createPresence } from '../../../assets/cursors/presence.js';

const box = (left = 10, top = 20, width = 200, height = 100) => ({
  left,
  top,
  width,
  height,
  right: left + width,
  bottom: top + height,
});
let layer;
let presence;
let marks;
const see = (userId, overrides = {}) =>
  presence.see({ userId, clientId: `c${userId}`, label: `User ${userId}`, color: 'blue', ...overrides });

beforeEach(() => {
  document.body.innerHTML = `
    <main id="listing"><div class="pane"><table><tbody>
      <tr data-key="a"><td>a.mp3</td><td>1 kB</td></tr><tr data-key="b"><td>b.mp3</td></tr>
    </tbody></table><ul><li data-key="c">c</li></ul></div></main>
    <dialog id="share"><h2>Share</h2><p data-collaborate-popup-presence></p><div data-key="a" data-collaborate-popup="share"></div></dialog>
    <dialog id="metadata"><div data-key="b" data-collaborate-popup="metadata"></div></dialog>
    <div data-collaborate-layer></div>`;
  layer = document.querySelector('[data-collaborate-layer]');
  vi.stubGlobal('CSS', { escape: (value) => value });
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue(box());
  document.elementFromPoint = vi.fn((_x, _y) => document.querySelector('tr[data-key="a"] td'));
  configureGeometry({
    regions: { modal: 'dialog[open]', listing: '#listing', body: 'body' },
    keyAttribute: 'data-key',
    keyScope: '#listing',
  });
  presence = createPresence(() => [], 5, { colors: ['blue', 'red'] });
  marks = createMarks(layer, presence, {
    markers: true,
    edges: '.pane',
    popups: { share: { where: 'in the sharing of %name%', line: 'Shared with %names%.' }, metadata: {} },
    messages: { line: 'Also open by %names%.' },
  });
});
afterEach(() => {
  configureGeometry();
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

test('a sentence has its placeholders filled', () => {
  expect(format('%name% is %place%', { name: 'Ana', place: 'here' })).toBe('Ana is here');
  expect(format(undefined, { name: 'Ana' })).toBe('');
});

test('the records peers are on are marked with their initials, in their colours, a row at the end of its cell', () => {
  see(2, { file: 'a', color: 'red' });
  see(1, { file: 'a' });
  see(3, { popup: { kind: 'share', key: 'c' } });
  document.querySelector('tr[data-key="a"] td').getBoundingClientRect = () => box(10, 20, 80, 100);
  document.querySelector('li[data-key="c"]').getBoundingClientRect = () => box(300, 20, 200, 100);
  document.elementFromPoint = vi.fn((x) =>
    x < 250 ? document.querySelector('tr[data-key="a"] td') : document.querySelector('li[data-key="c"]')
  );
  marks.drawMarkers();
  const [row, tile] = layer.querySelectorAll('.collaborate-marker');
  expect(row.dataset.shape).toBe('row');
  expect(row.style.getPropertyValue('--marker-end')).toBe('80px');
  expect(row.style.transform).toBe('translate3d(10px, 20px, 0)');
  expect(row.title).toBe('User 1, User 2');
  expect(
    [...row.querySelectorAll('.collaborate-marker__user')].map((user) => [user.textContent, user.dataset.color])
  ).toEqual([
    ['U', 'blue'],
    ['U', 'red'],
  ]);
  expect(row.dataset.color).toBe('blue');
  expect(tile.dataset.shape).toBe('tile');
  expect(tile.style.getPropertyValue('--marker-end')).toBe('200px');
  expect(tile.style.transform).toBe('translate3d(300px, 20px, 0)');
  // Drawn again unchanged, the initials stay.
  const initials = row.querySelector('.collaborate-marker__users').firstChild;
  marks.drawMarkers();
  expect(row.querySelector('.collaborate-marker__users').firstChild).toBe(initials);
  // A peer gone unmarks their record; a record out of sight hides its mark.
  presence.leave(3, 'c3');
  document.querySelector('tr[data-key="a"]').getBoundingClientRect = () => box(10, 20, 0, 0);
  marks.drawMarkers();
  expect(layer.querySelectorAll('.collaborate-marker')).toHaveLength(1);
  expect(row.hidden).toBe(true);
  marks.clear();
  expect(layer.querySelector('.collaborate-marker')).toBeNull();
});

test(`a record is marked with ${MARKED} peers, the others counted`, () => {
  document.elementFromPoint = vi.fn(() => document.querySelector('tr[data-key="b"] td'));
  for (let id = 1; id <= MARKED + 2; id++) see(id, { file: 'b' });
  marks.drawMarkers();
  const marker = layer.querySelector('.collaborate-marker');
  expect(marker.querySelectorAll('.collaborate-marker__user')).toHaveLength(MARKED);
  expect(marker.querySelector('.collaborate-marker__more').textContent).toBe('+2');
});

test('no markers unless the host asks for them', () => {
  marks = createMarks(layer, presence);
  see(1, { file: 'a' });
  marks.drawMarkers();
  expect(layer.querySelector('.collaborate-marker')).toBeNull();
});

test('a pointer scrolled out of its pane is an arrow at its edge, hidden once seen', () => {
  const pane = document.querySelector('.pane');
  pane.getBoundingClientRect = () => box(0, 100, 400, 300);
  document.querySelector('tr[data-key="b"]').getBoundingClientRect = () => box(0, 0, 400, 20);
  document.elementFromPoint = vi.fn(() => pane);
  see(1);
  const peer = {
    userId: 1,
    label: 'User 1',
    color: 'red',
    anchor: { region: 'listing', selector: 'tr[data-key="b"]', x: 0.5, y: 0.5 },
  };
  marks.drawEdge(peer, false);
  expect(peer.edge.className).toBe('collaborate-edge');
  expect(peer.edge.dataset).toMatchObject({ side: 'above', color: 'blue', userId: '1' });
  expect(peer.edge.textContent).toBe('U');
  expect(peer.edge.title).toBe('User 1');
  expect(peer.edge.style.transform).toBe('translate3d(200px, 100px, 0)');
  expect(peer.edge.hidden).toBe(false);
  marks.drawEdge(peer, true);
  expect(peer.edge.hidden).toBe(true);
  // A peer the presence lost keeps the colour and name of their event.
  presence.clear();
  marks.drawEdge(peer, false);
  expect(peer.edge.dataset.color).toBe('red');
  marks.dropEdge(peer);
  expect(peer.edge).toBeNull();
  expect(layer.querySelector('.collaborate-edge')).toBeNull();
  // Without edges, or seen, no arrow is made.
  marks.drawEdge(peer, true);
  expect(peer.edge).toBeNull();
  createMarks(layer, presence).drawEdge(peer, false);
  expect(peer.edge).toBeNull();
});

test('a popup says who else has it open, in the host placeholder, emptied as it closes', () => {
  const dialog = document.querySelector('#share');
  const placeholder = dialog.querySelector('[data-collaborate-popup-presence]');
  expect(marks.drawPopupLine(true)).toBe(false);
  dialog.setAttribute('open', '');
  // Made a status, empty, a frame before anything is said in it.
  expect(marks.drawPopupLine(true)).toBe(true);
  expect(placeholder.getAttribute('role')).toBe('status');
  expect(placeholder.textContent).toBe('');
  see(2, { popup: { kind: 'share', key: 'a' }, color: 'red' });
  see(1, { popup: { kind: 'share', key: 'a' } });
  see(3, { popup: { kind: 'share', key: 'b' } });
  expect(marks.drawPopupLine(true)).toBe(false);
  expect(placeholder.textContent).toBe('UUShared with User 1, User 2.');
  expect(
    [...placeholder.querySelectorAll('.collaborate-popup-presence__swatch')].map((swatch) => swatch.dataset.color)
  ).toEqual(['blue', 'red']);
  const said = placeholder.lastChild;
  marks.drawPopupLine(true);
  expect(placeholder.lastChild).toBe(said);
  presence.clear();
  marks.drawPopupLine(true);
  expect(placeholder.textContent).toBe('');
  dialog.removeAttribute('open');
  see(1, { popup: { kind: 'share', key: 'a' } });
  marks.drawPopupLine(true);
  expect(placeholder.hasAttribute('data-collaborate-popup-line')).toBe(false);
  expect(placeholder.isConnected).toBe(true);
});

test('a popup without a placeholder gets a line of its own, removed as it closes or the page stops sharing', () => {
  const dialog = document.querySelector('#metadata');
  dialog.setAttribute('open', '');
  see(1, { popup: { kind: 'metadata', key: 'b' } });
  expect(marks.drawPopupLine(true)).toBe(true);
  marks.drawPopupLine(true);
  const line = dialog.querySelector('.collaborate-popup-presence');
  expect(line.textContent).toBe('UAlso open by User 1.');
  expect(line.parentElement).toBe(dialog.querySelector('[data-collaborate-popup]'));
  marks.drawPopupLine(false);
  expect(line.isConnected).toBe(false);
});

test('a found peer is pointed out a moment', () => {
  vi.useFakeTimers();
  const element = document.createElement('div');
  marks.pulse(element, 1200);
  expect(element.classList.contains('collaborate-pulse')).toBe(true);
  vi.advanceTimersByTime(1200);
  expect(element.classList.contains('collaborate-pulse')).toBe(false);
});
