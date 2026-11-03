// @ts-check
// The form markup the scripts read, whatever form theme renders it. The form extension tags a shared form's
// rows (`data-collaborate-row`), each collection's widget (`data-collaborate-entries`) and each entry's row
// (`data-collaborate-entry`), and every stock Symfony theme renders those attributes. EasyAdmin's own classes,
// and those of templates written for earlier versions, are still read after them.

/** A field's row: the element a peer's field mark outlines, and where a widget's own control is found. */
export const ROW = '[data-collaborate-row], .form-group';

/** One entry of a collection. */
export const ENTRY = '[data-collaborate-entry], .field-collection-item';

/**
 * The row of the field `element` is in, or null.
 *
 * @param {Element | null | undefined} element
 * @returns {HTMLElement | null}
 */
export const rowOf = (element) => /** @type {HTMLElement | null} */ (element?.closest(ROW) ?? null);

/**
 * The element holding the entries of the collection whose row is `container`: its widget, not a nested
 * collection's.
 *
 * @param {Element} container
 * @returns {HTMLElement | null}
 */
export function entriesOf(container) {
  for (const element of container.querySelectorAll('[data-collaborate-entries]')) {
    if (element.closest('[data-collaborate-collection]') === container) return /** @type {HTMLElement} */ (element);
  }
  return null;
}

/**
 * The prototype of a collection's entries: on its row (EasyAdmin, hand-written templates) or on its widget
 * (Symfony's themes).
 *
 * @param {HTMLElement} container
 * @returns {string | undefined}
 */
export function prototypeOf(container) {
  return container.dataset.prototype ?? entriesOf(container)?.dataset.prototype;
}

/**
 * The placeholder a collection's prototype names its entry's key with.
 *
 * @param {HTMLElement} container
 * @returns {string}
 */
export function placeholderOf(container) {
  return (
    container.dataset.formTypeNamePlaceholder || entriesOf(container)?.dataset.collaboratePlaceholder || '__name__'
  );
}

/**
 * The entry a rendered prototype builds. A table row (`form_table_layout`) is parsed within a table body:
 * outside one, the parser drops it.
 *
 * @param {string} html
 * @returns {HTMLElement | null}
 */
export function parseEntry(html) {
  const template = document.createElement('template');
  const trimmed = html.trim();
  if (/^<tr[\s>]/i.test(trimmed)) {
    template.innerHTML = `<table><tbody>${trimmed}</tbody></table>`;
    return /** @type {HTMLElement | null} */ (template.content.querySelector('tbody > *'));
  }
  template.innerHTML = trimmed;
  return /** @type {HTMLElement | null} */ (template.content.firstElementChild);
}
