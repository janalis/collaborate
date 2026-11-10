// @ts-check
// Drupal's administration pages, in Claro or Gin: their regions, Form API's items and multi-value tables, the
// page the module names on the body, Views' bulk selection, jQuery UI dialogs and drop buttons. Rows added by
// Form API's AJAX ("Add another item") are announced by the module's behavior as `collaborate:rows-changed`.
//
// A multi-value field's items are a collection the server keys (the module names each item's controls by its
// key, not its delta). Its empty row, the one Drupal renders below the items or adds with "Add another item", is
// named `t<delta>`: no entry until it is typed in. A peer's new item takes an empty row, or one Drupal adds with
// the key asked for (a paragraph, one of its type); a peer's removal presses the item's Remove button. Drupal
// rebuilds the field on its server either way, one request at a time.
import { registerBridge } from '../bridge.js';

/** An empty row's key: the module names its controls by its delta. */
const EMPTY = /^t\d+$/;

/** How long a field's rebuild may take before the page stops waiting for it. */
const TIMEOUT = 15000;

/** The rows of a collection, its own and not a nested one's. */
function rowsOf(container) {
  return Array.from(container.querySelectorAll('tr.draggable')).filter(
    (row) => row.closest('[data-collaborate-collection]') === container
  );
}

/** The key a row's controls name, below the collection's path. */
function keyOf(row, path) {
  const control = Array.from(row.querySelectorAll('[name]')).find((input) => input.name.startsWith(`${path}[`));
  return control?.name.slice(path.length + 1).split(']')[0];
}

/** A button of the collection's, not a nested one's. */
function buttonOf(element, container, selector) {
  return Array.from(element.querySelectorAll(selector)).find(
    (button) => button.closest('[data-collaborate-collection]') === container
  );
}

let queue = Promise.resolve();

/** Runs Drupal's AJAX operations one after the other: each rebuilds from the form the previous one left. */
function serially(operation) {
  const next = queue.then(operation);
  queue = next.catch(() => {});
  return next;
}

/**
 * Presses one of Form API's AJAX buttons, as Drupal binds them (on their event, submitting the form with them),
 * and waits until Drupal has applied its response.
 */
async function press(button) {
  const ajax = globalThis.Drupal?.ajax.instances.find((instance) => instance?.element === button);
  const jquery = globalThis.jQuery;
  if (!ajax || !jquery || ajax.ajaxing) return false;
  jquery(button).trigger(ajax.event);
  const started = Date.now();
  while (ajax.ajaxing) {
    if (Date.now() - started > TIMEOUT) return false;
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  return true;
}

/** The collection a path names now: Drupal replaced the field's markup with its rebuild. */
const collectionOf = (form, path) =>
  Array.from(form.querySelectorAll('[data-collaborate-collection]')).find(
    (element) => /** @type {HTMLElement} */ (element).dataset.collaborateCollection === path
  );

/** A new paragraph's key, which names its type for the others' pages to add one: `n<type>-<uuid>`. */
const PARAGRAPH = /^n([a-z0-9_]+)-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * The button adding an item to a collection: "Add another item", or a paragraph type's ("Add Text", or the
 * type chosen in Paragraphs' select).
 */
function addButton(container, type) {
  if (!type) return buttonOf(container, container, '.field-add-more-submit');
  const own = buttonOf(container, container, `input[type="submit"][name$="_${type}_add_more"]`);
  if (own) return own;
  const select = buttonOf(container, container, 'select[name$="[add_more][add_more_select]"]');
  if (!select) return null;
  select.value = type;
  return buttonOf(container, container, 'input[type="submit"][name$="_add_more"]');
}

/** A row no one typed in yet, the one the user is in excepted. */
const emptyRow = (container) =>
  rowsOf(container).find((row) => 'collaboratePending' in row.dataset && !row.contains(document.activeElement));

export const DRUPAL = registerBridge({
  name: 'drupal',
  regions: {
    modal: '.ui-dialog',
    header: '#toolbar-bar, .gin-secondary-toolbar, .region-header',
    sidebar: '.layout-region--secondary, .gin-sidebar',
    content: '.region-content, .layout-region--main, main',
    body: 'body',
  },
  rows: ['.js-form-item', '.js-form-wrapper'],
  entries: ['tr.draggable'],
  selection: 'input[type="checkbox"][name$="_bulk_form[]"], .views-field-bulk-form input[type="checkbox"]',
  overlays: '.ui-dialog, .dropbutton-wrapper.open, [aria-expanded="true"].dropbutton__toggle',
  collections: {
    containers: ['tbody'],
    added: null,
    changed: ['collaborate:rows-changed'],
    // Drupal saves the items in the order of their weights.
    position: '_weight',
    // An empty row becomes an item once typed in (the module marks it touched).
    entry: (node, key) => (EMPTY.test(key) ? ('collaborateTouched' in node.dataset ? null : false) : true),
    create(container, key, rekey) {
      const path = String(container.dataset.collaborateCollection);
      const type = PARAGRAPH.exec(key)?.[1];
      const free = type ? null : emptyRow(container);
      if (free) {
        rekey(free, String(keyOf(free, path)));
        return free;
      }
      const form = container.closest('form');
      return serially(async () => {
        const current = form && collectionOf(form, path);
        const button = current && addButton(current, type);
        if (!form || !button) return null;
        // The server gives the row it adds the key asked for.
        const asked = document.createElement('input');
        Object.assign(asked, { type: 'hidden', name: '_collaborate_add', value: key });
        form.append(asked);
        const pressed = await press(button);
        asked.remove();
        const rebuilt = pressed ? collectionOf(form, path) : null;
        if (!rebuilt) return null;
        const keyed = rowsOf(rebuilt).find((candidate) => keyOf(candidate, path) === key);
        if (keyed) return keyed;
        const row = rowsOf(rebuilt).findLast((candidate) => 'collaboratePending' in candidate.dataset);
        if (!row) return null;
        rekey(row, String(keyOf(row, path)));
        return row;
      });
    },
    remove(node, container) {
      const path = String(container.dataset.collaborateCollection);
      const key = keyOf(node, path);
      const form = container.closest('form');
      serially(async () => {
        // The row as Drupal rendered it last: an earlier rebuild replaced the one the page removed.
        const current = form && collectionOf(form, path);
        const row = current ? rowsOf(current).find((candidate) => keyOf(candidate, path) === key) : null;
        // Form API's Remove, or a paragraph's.
        const button =
          row &&
          buttonOf(row, current, 'input[type="submit"][name$="_remove_button"], input[type="submit"][name$="_remove"]');
        if (button) await press(button);
        else row?.remove();
      });
    },
  },
});
