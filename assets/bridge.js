// @ts-check
// The admin a page belongs to (`collaborate.bridge`), and what the scripts assume of its markup beyond the
// bundle's own `data-collaborate-*` attributes: the regions a pointer is placed from, the rows and entries of its
// forms, the page a list or record is shown on, its embedded lists, its selection, its overlays, its collections'
// events, its slug widget and its save buttons.
//
// An entry point registers the profile of its admin (`easyadmin.js`, `drupal.js`) before any module starts; the
// layer's `data-config` names the bridge the server resolved, which wins when its profile is registered.

/**
 * @typedef {object} EmbeddedLists
 * @property {string} selector a list loaded in place
 * @property {string} loading a list still on its first load (matched with `selector`)
 * @property {(list: HTMLElement) => void} reload asks a list to load again
 * @property {string} loaded the document event a list's load landed with (`detail.list`)
 * @property {(control: HTMLElement) => boolean} bound whether a link or form inside a list loads in place
 */

/**
 * @typedef {object} Collections
 * @property {string[]} containers where a collection without entries appends them, after its widget
 * @property {string | null} added the document event a row added is announced with, `detail.newElement`
 * @property {string[]} changed document events after which the collections are scanned again
 * @property {string} [position] an entry's child the page numbers in the order shown once rows move (`position`)
 * @property {(node: HTMLElement, key: string) => boolean | null | undefined} [entry] what a row its controls key
 *   `key` is: true, the entry of that key (keys the server keeps); false, no entry yet (a host's empty row);
 *   otherwise an entry the page added, which takes a key of its own
 * @property {(container: HTMLElement, key: string, rekey: (node: HTMLElement, from: string) => void) =>
 *   HTMLElement | null | Promise<HTMLElement | null>} [create] adds a peer's entry when the collection has no
 *   prototype: a row of the host's, its controls renamed from `from` to the key by `rekey`
 * @property {(node: HTMLElement, container: HTMLElement) => void} [remove] removes an entry a peer removed, in
 *   place of taking its row out of the page
 */

/**
 * @typedef {object} BridgeProfile
 * @property {string} name the bridge, as `collaborate.bridge` names it
 * @property {Record<string, string>} regions name => root selector, the first one an element is in naming it
 * @property {string[]} rows a field's row, after `[data-collaborate-row]`
 * @property {string[]} entries one entry of a collection, after `[data-collaborate-entry]`
 * @property {(body: HTMLElement) => {entity: string | null, kind: string} | null} page what the page shows
 * @property {EmbeddedLists | null} lists
 * @property {string} selection a row's selection checkbox, kept across a refresh
 * @property {string} overlays an open overlay a refresh would close
 * @property {Collections} collections
 * @property {string | null} slug a field a widget writes without an event, from the form's other fields
 * @property {string[]} submitters the values of the save buttons the bar's Save stands for, in preference
 */

/** The page a host names (`data-collaborate-page="<entity>:<index|detail|edit|new>"` on the body), or null. */
export function markedPage(body) {
  const match = /^(?:(.+):)?(index|detail|edit|new)$/.exec(body.dataset.collaboratePage ?? '');
  return match ? { entity: match[1] ?? null, kind: match[2] } : null;
}

/** @type {BridgeProfile} */
export const NONE = {
  name: 'none',
  regions: {
    modal: 'dialog[open], .modal.show',
    header: 'body > header, [role=banner]',
    content: 'main, [role=main]',
    body: 'body',
  },
  rows: [],
  entries: [],
  page: markedPage,
  lists: null,
  selection: 'input[type="checkbox"][data-collaborate-select]',
  overlays: 'dialog[open], .modal.show, [aria-expanded="true"][aria-haspopup]',
  collections: { containers: [], added: null, changed: [] },
  slug: null,
  submitters: [],
};

/** @type {Map<string, BridgeProfile>} */
const profiles = new Map([[NONE.name, NONE]]);
/** @type {BridgeProfile | null} */
let registered = null;

/**
 * Registers a bridge's profile, its options defaulting to no bridge's: the entry point of its admin calls it
 * before any module starts.
 *
 * @param {Partial<BridgeProfile> & {name: string}} profile
 * @returns {BridgeProfile}
 */
export function registerBridge(profile) {
  const complete = { ...NONE, ...profile };
  profiles.set(complete.name, complete);
  registered = complete;
  return complete;
}

/** The bridge the layer names, from its `data-config`; null without a layer or a readable configuration. */
function named() {
  try {
    const layer = /** @type {HTMLElement | null} */ (document.querySelector('[data-collaborate-layer]'));
    const name = JSON.parse(layer?.dataset.config || '{}')?.bridge;
    return typeof name === 'string' ? name : null;
  } catch {
    return null;
  }
}

/**
 * The profile of the page's bridge: the one the layer names when registered, else the one the entry point
 * registered, else no bridge's.
 *
 * @returns {BridgeProfile}
 */
export function bridge() {
  const name = named();
  return (name !== null ? profiles.get(name) : undefined) ?? registered ?? NONE;
}

/** Forgets the registered profiles (tests). */
export function resetBridges() {
  profiles.clear();
  profiles.set(NONE.name, NONE);
  registered = null;
}
