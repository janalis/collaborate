// @ts-check
import { bridge } from '../bridge.js';
import { entriesOf, entrySelector, parseEntry, placeholderOf, prototypeOf } from '../markup.js';
import { valueAdapter } from './fields.js';
import { initializeRow } from './hooks.js';

/**
 * An entry's state in the draft: whether it is in its collection, and where.
 *
 * @typedef {{ alive: boolean, order: number }} Member
 */

/**
 * The value adapter of an entry, holding its current state.
 *
 * @typedef {import('./fields.js').ValueAdapter & { value: Member, change(value: Member): void }} MemberAdapter
 */

/**
 * Stable entry keys and per-entry tombstones avoid replacing another editor's collection.
 *
 * @param {import('./session.js').DraftSession} session
 */
export function initializeCollections(session) {
  /** @type {Map<string, { scan(rendered: HTMLElement): void }>} */
  const collections = new Map();
  let applying = false;

  function scan() {
    if (applying || session.stopped) return;
    applying = true;
    try {
      for (const container of /** @type {NodeListOf<HTMLElement>} */ (
        session.form.querySelectorAll('[data-collaborate-collection]')
      )) {
        const path = /** @type {string} */ (container.dataset.collaborateCollection);
        if (!collections.has(path)) collections.set(path, collection(container, path));
        /** @type {{ scan(rendered: HTMLElement): void }} */ (collections.get(path)).scan(container);
      }
      session.initializeControls();
    } finally {
      applying = false;
    }
  }

  /**
   * Renames an entry's controls, and the paths of the collections it holds, from one key to another.
   *
   * @param {HTMLElement} node
   * @param {string} path
   * @param {string} from
   * @param {string} to
   */
  function rekey(node, path, from, to) {
    const before = `${path}[${from}]`;
    const after = `${path}[${to}]`;
    // Each selector names the attribute read below: it is there.
    for (const control of /** @type {NodeListOf<HTMLInputElement>} */ (node.querySelectorAll('[name]')))
      control.name = control.name.replace(before, after);
    for (const nested of /** @type {NodeListOf<HTMLElement>} */ (
      node.querySelectorAll('[data-collaborate-collection]')
    ))
      nested.dataset.collaborateCollection = /** @type {string} */ (nested.dataset.collaborateCollection).replace(
        before,
        after
      );
    for (const nested of /** @type {NodeListOf<HTMLElement>} */ (node.querySelectorAll('[data-collaborate-entries]')))
      nested.dataset.collaborateEntries = /** @type {string} */ (nested.dataset.collaborateEntries).replace(
        before,
        after
      );
    for (const nested of /** @type {NodeListOf<HTMLElement>} */ (node.querySelectorAll('[data-prototype]')))
      nested.dataset.prototype = /** @type {string} */ (nested.dataset.prototype).replaceAll(before, after);
    delete node.dataset.collaboratePending;
  }

  /**
   * @param {HTMLElement} initial
   * @param {string} path
   */
  function collection(initial, path) {
    let container = initial;
    /** @type {Map<string, MemberAdapter>} */
    const members = new Map();
    /** @type {Map<string, HTMLElement>} */
    const nodes = new Map();
    // Entries the host is still adding (`collections.create`), and those it added since the last render.
    /** @type {Set<string>} */
    const creating = new Set();
    /** @type {HTMLElement[]} */
    const arrived = [];
    // Entries the host could not add: asking again would fail again.
    /** @type {Set<string>} */
    const failed = new Set();
    let bootstrapping = true;
    /** @type {(string | undefined)[]} */
    let renderedOrder = [];
    const ownNodes = () =>
      Array.from(/** @type {NodeListOf<HTMLElement>} */ (container.querySelectorAll(entrySelector()))).filter(
        (node) =>
          node.closest('[data-collaborate-collection]') === container &&
          // An entry inside the container has a parent.
          /** @type {HTMLElement} */ (node.parentElement)
            .closest(entrySelector())
            ?.closest('[data-collaborate-collection]') !== container &&
          !('collaborateRemoved' in node.dataset)
      );

    /**
     * What the host says of an entry its controls name `key`: true, an entry under that key; false, a row that
     * is no entry yet (Drupal's empty row); undefined or null, an entry the page added, which takes a key of its own.
     *
     * @param {HTMLElement} node
     * @param {string} key
     */
    function verdict(node, key) {
      const answer = bridge().collections.entry?.(node, key);
      if (answer === false) node.dataset.collaboratePending = '';
      else delete node.dataset.collaboratePending;
      return answer;
    }

    /** The entries a collection rendered (again: a host may replace its whole widget) take the keys they name. */
    function adopt() {
      nodes.clear();
      for (const node of ownNodes()) {
        const key = keyOf(node);
        if (key && verdict(node, key) !== false) {
          node.dataset.collaborateItem = key;
          nodes.set(key, node);
        }
      }
    }

    /** @param {HTMLElement} node */
    function keyOf(node) {
      const control = Array.from(/** @type {NodeListOf<HTMLInputElement>} */ (node.querySelectorAll('[name]'))).find(
        (input) => input.name.startsWith(`${path}[`)
      );
      return control?.name.slice(path.length + 1).split(']')[0];
    }

    /**
     * @param {string} key
     * @param {HTMLElement} node
     */
    function detach(key, node) {
      for (const name of [...session.adapters.keys()]) {
        if (session.formName(name).startsWith(`${path}[${key}]`)) session.detachField(name);
      }
      for (const root of node.querySelectorAll('.ck-editor__editable')) root.ckeditorInstance?.destroy();
      const { remove } = bridge().collections;
      if (remove) {
        // The host removes it (Drupal, on the server): until then it is no entry.
        node.dataset.collaborateRemoved = '';
        remove(node, container);
      } else node.remove();
      nodes.delete(key);
    }

    /**
     * The host could not add an entry: the page cannot follow the collection.
     *
     * @param {string} key
     */
    function unrenderable(key) {
      failed.add(key);
      session.setStatus('incompatible');
      session.report('prototype', path);
    }

    /**
     * Asks the host for an entry it adds itself, its collections having no prototype: the entry, or null while
     * the host adds it (Drupal asks its server for a row).
     *
     * @param {string} key
     * @returns {HTMLElement | null}
     */
    function create(key) {
      if (creating.has(key) || failed.has(key)) return null;
      // Only called for a bridge whose collections add their entries themselves.
      const collections = /** @type {any} */ (bridge().collections);
      const made = collections.create(
        container,
        key,
        /**
         * @param {HTMLElement} node
         * @param {string} from
         */
        (node, from) => rekey(node, path, from, key)
      );
      if (!(made instanceof Promise)) {
        if (!made) unrenderable(key);
        return made;
      }
      creating.add(key);
      made
        .then((node) => {
          if (!node) {
            unrenderable(key);
            return;
          }
          node.dataset.collaborateItem = key;
          nodes.set(key, node);
          arrived.push(node);
        })
        .catch(() => unrenderable(key))
        .finally(() => {
          creating.delete(key);
          if (!session.stopped) render();
        });
      return null;
    }

    function render() {
      const shown = ownNodes().map(keyOf).join();
      const ordered = Array.from(members.entries())
        .filter(([, value]) => value.value.alive)
        .sort(([a, x], [b, y]) => x.value.order - y.value.order || a.localeCompare(b));
      for (const [key, node] of nodes) {
        if (!members.get(key)?.value.alive) detach(key, node);
      }
      const parent =
        ownNodes()[0]?.parentElement ||
        entriesOf(container) ||
        // A collection without entries yet: where the bridge's theme holds them.
        bridge()
          .collections.containers.map((selector) => container.querySelector(selector))
          .find(Boolean);
      if (!parent) return;
      const added = arrived.splice(0).filter((node) => node.isConnected);
      let prototype;
      let placeholder;
      for (const [key] of ordered) {
        /** @type {HTMLElement | null | undefined} */
        let node = nodes.get(key);
        if (!node && bridge().collections.create) {
          node = create(key);
          if (!node) continue;
          node.dataset.collaborateItem = key;
          parent.append(node);
          nodes.set(key, node);
          added.push(node);
          continue;
        }
        if (!node) {
          prototype ??= prototypeOf(container);
          if (!prototype) {
            session.setStatus('incompatible');
            session.report('prototype', path);
            continue;
          }
          placeholder ??= placeholderOf(container);
          node = parseEntry(prototype.replaceAll(placeholder, key));
          if (!node) continue;
          node.dataset.collaborateItem = key;
          parent.append(node);
          nodes.set(key, node);
          added.push(node);
        }
      }
      const rows = /** @type {HTMLElement[]} */ (
        ordered.map(([key]) => nodes.get(key)).filter((node) => node?.parentElement === parent)
      );
      const focused = rows.find((node) => node.contains(document.activeElement));
      // What follows the entries stays after them: a row that is no entry yet (Drupal's empty one).
      let before =
        Array.from(parent.children).findLast((child) => rows.includes(/** @type {HTMLElement} */ (child)))
          ?.nextElementSibling ?? null;
      // Keep the active row attached: moving it blurs native controls and editors.
      // Place only misplaced siblings around it, leaving unchanged orders untouched.
      for (const node of rows.toReversed()) {
        if (node !== focused && node.nextElementSibling !== before) parent.insertBefore(node, before);
        before = node;
      }
      const wasApplying = applying;
      applying = true;
      try {
        for (const node of added) {
          // Every row added was given its key.
          const key = /** @type {string} */ (node.dataset.collaborateItem);
          const { added: announced } = bridge().collections;
          if (announced)
            document.dispatchEvent(new CustomEvent(announced, { detail: { newElement: node, collection: container } }));
          // Form decorators can replace a redundant outer wrapper. Keep its
          // stable identity before the observer sees the decorated row.
          const decorated = ownNodes().find((item) => keyOf(item) === key) || node;
          decorated.dataset.collaborateItem = key;
          nodes.set(key, decorated);
        }
      } finally {
        applying = wasApplying;
      }
      session.initializeControls();
      // The host builds its widgets on each row added, once the row's own controls follow the draft.
      for (const node of added)
        initializeRow(/** @type {HTMLElement} */ (nodes.get(/** @type {string} */ (node.dataset.collaborateItem))));
      renderedOrder = ownNodes().map(keyOf);
      // An entry's position (a hidden `position` child, Drupal's `_weight`) is posted from the page, not the
      // draft: once rows move, number it in the order shown.
      if (renderedOrder.join() === shown) return;
      const { position: child = 'position' } = bridge().collections;
      let position = 0;
      for (const node of ownNodes()) {
        const name = `${path}[${keyOf(node)}][${child}]`;
        const input = Array.from(
          /** @type {NodeListOf<HTMLInputElement | HTMLSelectElement>} */ (node.querySelectorAll('input, select'))
        ).find((item) => item.name === name);
        if (input) input.value = String(++position);
      }
    }

    /**
     * @param {string} key
     * @param {Member} [initial]
     * @returns {MemberAdapter | null}
     */
    function member(key, initial) {
      if (members.has(key)) return /** @type {MemberAdapter} */ (members.get(key));
      const name = `${path}[@${key}]`;
      const state = session.ensureField(name);
      if (!state) return null;
      // A removal cancels an unsent reorder: the entry stays removed.
      // Its value, change and adopt are assigned below.
      const adapter = /** @type {MemberAdapter} */ (
        valueAdapter(
          session,
          (event, pending) => {
            adapter.value = pending ? pending.value : event.value;
            if (!bootstrapping) render();
          },
          (event) => !event.value.alive
        )
      );
      Object.assign(adapter, {
        value: initial || state.value || state.initial,
        /** @param {Member} value */
        change(value) {
          this.value = value;
          /** @type {any} */ (this).send(value);
        },
        /** @param {Member} value */
        adopt(value) {
          this.change(value);
          render();
        },
      });
      members.set(key, adapter);
      session.register(name, adapter);
      return adapter;
    }

    adopt();
    for (const [name, state] of Object.entries(session.state.fields)) {
      if (state.type === 'member' && name.startsWith(`${path}[@`) && !name.slice(path.length + 2, -1).includes(']')) {
        member(name.slice(path.length + 2, -1), state.value);
      }
    }
    bootstrapping = false;
    render();

    return {
      /** @param {HTMLElement} rendered */
      scan(rendered) {
        if (rendered !== container) {
          container = rendered;
          adopt();
        }
        for (const [name, state] of Object.entries(session.state.fields)) {
          if (state.type !== 'member' || !name.startsWith(`${path}[@`)) continue;
          const key = name.slice(path.length + 2, -1);
          if (key.includes(']') || members.has(key)) continue;
          member(key, state.value);
          render();
        }
        const seen = new Set();
        const current = ownNodes();
        const reordered =
          current.length !== renderedOrder.length ||
          current.some((node, index) => keyOf(node) !== renderedOrder[index]);
        let order = 0;
        for (const node of current) {
          let key = node.dataset.collaborateItem;
          if (!key) {
            const old = keyOf(node);
            if (!old) continue;
            const answer = verdict(node, old);
            if (answer === false) continue;
            key = answer === true ? old : `n${crypto.randomUUID()}`;
            if (key !== old) rekey(node, path, old, key);
            node.dataset.collaborateItem = key;
            nodes.set(key, node);
          }
          seen.add(key);
          const value = member(key);
          if (!value) continue;
          ++order;
          if (!value.value.alive || (reordered && value.value.order !== order))
            value.change({ ...value.value, alive: true, order });
        }
        for (const [key, value] of members) {
          if (!seen.has(key) && !creating.has(key) && !failed.has(key) && value.value.alive)
            value.change({ ...value.value, alive: false });
        }
        renderedOrder = ownNodes().map(keyOf);
      },
    };
  }

  scan();
  // Typing in a rich editor and status updates rewrite nodes too; neither adds or removes an entry.
  // A childList record's target is the element whose children changed.
  /** @param {Element} target */
  const ignored = (target) => target.closest('.ck, [data-collaborate-bar], [data-collaborate-conflicts]');
  // Widgets redraw their options and messages as the user types: only an entry, a collection or a control is scanned for.
  const relevant = `${entrySelector()}, [data-collaborate-collection], [name]`;
  /** @param {Element} node any node added or removed: only an element reaches the element methods */
  const structural = (node) =>
    node.nodeType === Node.ELEMENT_NODE && (node.matches(relevant) || node.querySelector(relevant) !== null);
  const observer = new MutationObserver((records) => {
    if (
      records.some(
        (record) =>
          !ignored(/** @type {Element} */ (record.target)) &&
          [...record.addedNodes, ...record.removedNodes].some((node) => structural(/** @type {Element} */ (node)))
      )
    )
      scan();
  });
  observer.observe(session.form, { childList: true, subtree: true });
  const { changed } = bridge().collections;
  for (const event of changed) document.addEventListener(event, scan);
  // Entries a replay brings are projected from the session's field states.
  session.scanCollections = scan;
  return () => {
    session.scanCollections = null;
    observer.disconnect();
    for (const event of changed) document.removeEventListener(event, scan);
  };
}
