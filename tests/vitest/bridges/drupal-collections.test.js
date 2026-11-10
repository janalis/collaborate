// Drupal's multi-value fields, as its profile reads and changes them: the module names an item's controls by its
// key and an empty row's by `t<delta>`; a peer's item takes an empty row, or one "Add another item" adds; a peer's
// removal presses the item's Remove button. Drupal's AJAX is reduced to its instances and the event they bind.
import { resetBridges } from '../../../assets/bridge.js';
import { DRUPAL } from '../../../assets/bridges/drupal.js';

const { entry, create, remove } = DRUPAL.collections;

afterAll(() => resetBridges());

const item = (key, pending = false) =>
  `<tr class="draggable"${pending ? ' data-collaborate-pending' : ''}><td><input name="notes[${key}][value]"></td>` +
  `<td><input type="submit" name="notes_${key}_remove_button" value="Remove"></td></tr>`;

function widget(rows) {
  return (
    `<div data-collaborate-collection="notes"><table><tbody>${rows.join('')}</tbody></table>` +
    '<input type="submit" class="field-add-more-submit" name="notes_add_more" value="Add another item"></div>'
  );
}

/** Drupal's AJAX: an instance per button, which rebuilds the field with `rebuild` once its event fires. */
function drupal(rebuilds = {}) {
  const instances = [];
  for (const button of document.querySelectorAll('input[type="submit"]')) {
    const instance = { element: button, event: 'mousedown', ajaxing: false };
    button.addEventListener('mousedown', () => {
      instance.ajaxing = true;
      setTimeout(() => {
        const rebuild = rebuilds[button.name];
        if (rebuild) document.querySelector('[data-collaborate-collection]').outerHTML = widget(rebuild);
        instance.ajaxing = false;
      }, 30);
    });
    instances.push(instance);
  }
  globalThis.Drupal = { ajax: { instances: [null, ...instances] } };
  globalThis.jQuery = (element) => ({ trigger: (event) => element.dispatchEvent(new Event(event)) });
  return instances;
}

function page(rows) {
  document.body.innerHTML = `<form>${widget(rows)}</form>`;
  return document.querySelector('[data-collaborate-collection]');
}

afterEach(() => {
  document.body.innerHTML = '';
  delete globalThis.Drupal;
  delete globalThis.jQuery;
  vi.useRealTimers();
});

test("takes the server's keys, and an empty row for an entry once typed in", () => {
  const row = document.createElement('tr');
  expect(entry(row, 'p0')).toBe(true);
  expect(entry(row, 'nabc')).toBe(true);
  expect(entry(row, 't3')).toBe(false);
  row.dataset.collaborateTouched = '';
  expect(entry(row, 't3')).toBeNull();
});

test("gives a peer's item an empty row the user is not in", () => {
  const container = page([item('p0'), item('t1', true), item('t2', true)]);
  container.querySelector('[name="notes[t1][value]"]').focus();
  const rekey = vi.fn();
  const row = create(container, 'nPeer', rekey);
  expect(row).toBe(container.querySelectorAll('tr')[2]);
  expect(rekey).toHaveBeenCalledWith(row, 't2');
});

test('asks Drupal for a row when there is no empty one, one request at a time', async () => {
  const container = page([item('p0')]);
  drupal({ notes_add_more: [item('p0'), item('t1', true)] });
  const rekey = vi.fn();
  const row = await create(container, 'nPeer', rekey);
  expect(row.querySelector('input').name).toBe('notes[t1][value]');
  expect(rekey).toHaveBeenCalledWith(row, 't1');
  expect(row.isConnected).toBe(true);
});

test.each([
  [
    'the form has no add button',
    () =>
      page([item('p0')])
        .querySelector('.field-add-more-submit')
        .remove(),
    {},
  ],
  ['Drupal binds no AJAX to it', () => page([item('p0')]), null],
  ['the rebuild brings no empty row', () => page([item('p0')]), { notes_add_more: [item('p0')] }],
])('adds no row when %s', async (_, setUp, rebuilds) => {
  setUp();
  if (rebuilds) drupal(rebuilds);
  const container = document.querySelector('[data-collaborate-collection]');
  expect(await create(container, 'nPeer', vi.fn())).toBeNull();
});

test('adds no row outside a form, or while the button is busy', async () => {
  document.body.innerHTML = widget([item('p0')]);
  expect(await create(document.querySelector('[data-collaborate-collection]'), 'nPeer', vi.fn())).toBeNull();
  const container = page([item('p0')]);
  const [busy] = drupal({});
  busy.ajaxing = true;
  expect(await create(container, 'nPeer', vi.fn())).toBeNull();
});

test('gives up on a rebuild that takes too long, and without jQuery', async () => {
  vi.useFakeTimers();
  const container = page([item('p0')]);
  const instance = { element: container.querySelector('.field-add-more-submit'), event: 'mousedown', ajaxing: false };
  globalThis.Drupal = { ajax: { instances: [instance] } };
  // The rebuild never ends.
  globalThis.jQuery = () => ({ trigger: () => (instance.ajaxing = true) });
  const result = create(container, 'nPeer', vi.fn());
  await vi.advanceTimersByTimeAsync(16000);
  expect(await result).toBeNull();
  instance.ajaxing = false;
  delete globalThis.jQuery;
  const without = create(container, 'nPeer', vi.fn());
  await vi.advanceTimersByTimeAsync(100);
  expect(await without).toBeNull();
});

test("presses the Remove button of a peer's removed item, as Drupal rendered it last", async () => {
  const container = page([item('p0'), item('p1')]);
  drupal({ notes_p0_remove_button: [item('p1')] });
  const first = container.querySelector('tr');
  remove(first, container);
  await vi.waitFor(() => expect(document.querySelectorAll('tr')).toHaveLength(1));
  expect(document.querySelector('tr input').name).toBe('notes[p1][value]');
});

test('takes the row out when Drupal gives it no Remove button, and leaves a row already gone', async () => {
  const container = page(['<tr class="draggable"><td><input name="notes[p0][value]"></td></tr>', item('p1')]);
  const first = container.querySelector('tr');
  remove(first, container);
  await vi.waitFor(() => expect(first.isConnected).toBe(false));
  const gone = document.createElement('tr');
  gone.innerHTML = '<td><input name="notes[p9][value]"></td>';
  remove(gone, container);
  document.body.innerHTML = widget([item('p1')]);
  remove(document.querySelector('tr'), document.querySelector('[data-collaborate-collection]'));
  await new Promise((resolve) => setTimeout(resolve, 10));
  expect(document.querySelectorAll('tr')).toHaveLength(1);
});

test('runs the next request after one that failed', async () => {
  const container = page([item('p0')]);
  drupal({ notes_add_more: [item('p0'), item('t1', true)] });
  const failing = create(container, 'nPeer', () => {
    throw new Error('rekey failed');
  });
  await expect(failing).rejects.toThrow('rekey failed');
  drupal({ notes_add_more: [item('p0'), item('t1', true), item('t2', true)] });
  const row = await create(document.querySelector('[data-collaborate-collection]'), 'nOther', vi.fn());
  // The first rebuild's empty row is free: the page takes it, without asking Drupal.
  expect(row.querySelector('input').name).toBe('notes[t1][value]');
});

/** A paragraph's key: `n<type>-<uuid>`. */
const paragraph = (type) => `n${type}-12345678-1234-1234-1234-123456789abc`;

/** A Paragraphs field: its rows, and its add buttons per type or its select of types. */
function paragraphs(rows, controls) {
  document.body.innerHTML =
    `<form><div data-collaborate-collection="parts"><table><tbody>${rows.join('')}</tbody></table>` +
    `${controls}</div></form>`;
  return document.querySelector('[data-collaborate-collection]');
}

const part = (key) => `<tr class="draggable"><td><input name="parts[${key}][subform][field_text][0][value]"></td></tr>`;

test("adds a peer's paragraph with its type's button, under the key it asks for", async () => {
  const container = paragraphs(
    [part('p0')],
    '<input type="submit" name="parts_text_add_more" value="Add Text"><input type="submit" name="parts_quote_add_more" value="Add Quote">'
  );
  const instances = drupal({});
  const quote = instances.find((instance) => instance.element.name === 'parts_quote_add_more');
  let asked = null;
  quote.element.addEventListener('mousedown', () => {
    asked = document.querySelector('[name="_collaborate_add"]')?.value;
    setTimeout(() => {
      document.querySelector('tbody').insertAdjacentHTML('beforeend', part(paragraph('quote')));
    }, 10);
  });

  const row = await create(container, paragraph('quote'), vi.fn());

  expect(asked).toBe(paragraph('quote'));
  expect(row.querySelector('input').name).toBe(`parts[${paragraph('quote')}][subform][field_text][0][value]`);
  // The key it asked for is gone once Drupal answered.
  expect(document.querySelector('[name="_collaborate_add"]')).toBeNull();
});

test("chooses a peer's paragraph type in Paragraphs' select, and adds nothing without one", async () => {
  const container = paragraphs(
    [part('p0')],
    '<select name="parts[add_more][add_more_select]"><option value="text">Text</option><option value="quote">Quote</option></select>' +
      '<input type="submit" name="parts_add_more" value="Add">'
  );
  const [add] = drupal({});
  add.element.addEventListener('mousedown', () => {
    setTimeout(() => {
      document.querySelector('tbody').insertAdjacentHTML('beforeend', part(paragraph('quote')));
    }, 10);
  });

  const row = await create(container, paragraph('quote'), vi.fn());

  expect(document.querySelector('select').value).toBe('quote');
  expect(row).not.toBeNull();
  const bare = paragraphs([part('p0')], '');
  expect(await create(bare, paragraph('quote'), vi.fn())).toBeNull();
});
