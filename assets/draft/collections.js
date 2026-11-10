import { bridge } from '../bridge.js';
import { entriesOf, entrySelector, parseEntry, placeholderOf, prototypeOf } from '../markup.js';
import { valueAdapter } from './fields.js';
import { initializeRow } from './hooks.js';

/** Stable entry keys and per-entry tombstones avoid replacing another editor's collection. */
export function initializeCollections(session) {
  const collections = new Map();
  let applying = false;

  function scan() {
    if (applying || session.stopped) return;
    applying = true;
    try {
      for (const container of session.form.querySelectorAll('[data-collaborate-collection]')) {
        const path = container.dataset.collaborateCollection;
        if (!collections.has(path)) collections.set(path, collection(container, path));
        collections.get(path).scan(container);
      }
      session.initializeControls();
    } finally {
      applying = false;
    }
  }

  /** Renames an entry's controls, and the paths of the collections it holds, from one key to another. */
  function rekey(node, path, from, to) {
    const before = `${path}[${from}]`;
    const after = `${path}[${to}]`;
    for (const control of node.querySelectorAll('[name]')) control.name = control.name.replace(before, after);
    for (const nested of node.querySelectorAll('[data-collaborate-collection]'))
      nested.dataset.collaborateCollection = nested.dataset.collaborateCollection.replace(before, after);
    for (const nested of node.querySelectorAll('[data-collaborate-entries]'))
      nested.dataset.collaborateEntries = nested.dataset.collaborateEntries.replace(before, after);
    for (const nested of node.querySelectorAll('[data-prototype]'))
      nested.dataset.prototype = nested.dataset.prototype.replaceAll(before, after);
    delete node.dataset.collaboratePending;
  }

  function collection(initial, path) {
    let container = initial;
    const members = new Map();
    const nodes = new Map();
    // Entries the host is still adding (`collections.create`), and those it added since the last render.
    const creating = new Set();
    const arrived = [];
    // Entries the host could not add: asking again would fail again.
    const failed = new Set();
    let bootstrapping = true;
    let renderedOrder = [];
    const ownNodes = () =>
      Array.from(container.querySelectorAll(entrySelector())).filter(
        (node) =>
          node.closest('[data-collaborate-collection]') === container &&
          node.parentElement.closest(entrySelector())?.closest('[data-collaborate-collection]') !== container &&
          !('collaborateRemoved' in node.dataset)
      );

    /**
     * What the host says of an entry its controls name `key`: true, an entry under that key; false, a row that
     * is no entry yet (Drupal's empty row); undefined or null, an entry the page added, which takes a key of its own.
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

    function keyOf(node) {
      const control = Array.from(node.querySelectorAll('[name]')).find((input) => input.name.startsWith(`${path}[`));
      return control?.name.slice(path.length + 1).split(']')[0];
    }

    function detach(key, node) {
      for (const [name, adapter] of session.adapters) {
        if (session.formName(name).startsWith(`${path}[${key}]`)) {
          adapter.destroy?.();
          session.adapters.delete(name);
          session.editorWaiters.get(name)?.resolve();
        }
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

    /** The host could not add an entry: the page cannot follow the collection. */
    function unrenderable(key) {
      failed.add(key);
      session.setStatus('incompatible');
      session.report('prototype', path);
    }

    /**
     * Asks the host for an entry it adds itself, its collections having no prototype: the entry, or null while
     * the host adds it (Drupal asks its server for a row).
     */
    function create(key) {
      if (creating.has(key) || failed.has(key)) return null;
      const made = bridge().collections.create(container, key, (node, from) => rekey(node, path, from, key));
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
      const rows = ordered.map(([key]) => nodes.get(key)).filter((node) => node?.parentElement === parent);
      const focused = rows.find((node) => node.contains(document.activeElement));
      // What follows the entries stays after them: a row that is no entry yet (Drupal's empty one).
      let before = Array.from(parent.children).findLast((child) => rows.includes(child))?.nextElementSibling ?? null;
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
          const key = node.dataset.collaborateItem;
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
      for (const node of added) initializeRow(nodes.get(node.dataset.collaborateItem));
      renderedOrder = ownNodes().map(keyOf);
      // An entry's position (a hidden `position` child, Drupal's `_weight`) is posted from the page, not the
      // draft: once rows move, number it in the order shown.
      if (renderedOrder.join() === shown) return;
      const { position: child = 'position' } = bridge().collections;
      let position = 0;
      for (const node of ownNodes()) {
        const name = `${path}[${keyOf(node)}][${child}]`;
        const input = Array.from(node.querySelectorAll('input, select')).find((item) => item.name === name);
        if (input) input.value = String(++position);
      }
    }

    function member(key, initial) {
      if (members.has(key)) return members.get(key);
      const name = `${path}[@${key}]`;
      const state = session.ensureField(name);
      if (!state) return null;
      // A removal cancels an unsent reorder: the entry stays removed.
      const adapter = valueAdapter(
        session,
        (event, pending) => {
          adapter.value = pending ? pending.value : event.value;
          if (!bootstrapping) render();
        },
        (event) => !event.value.alive
      );
      Object.assign(adapter, {
        value: initial || state.value || state.initial,
        change(value) {
          this.value = value;
          this.send(value);
        },
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
  const ignored = (target) => target.closest('.ck, [data-collaborate-bar], [data-collaborate-conflicts]');
  // Widgets redraw their options and messages as the user types: only an entry, a collection or a control is scanned for.
  const relevant = `${entrySelector()}, [data-collaborate-collection], [name]`;
  const structural = (node) =>
    node.nodeType === Node.ELEMENT_NODE && (node.matches(relevant) || node.querySelector(relevant) !== null);
  const observer = new MutationObserver((records) => {
    if (
      records.some(
        (record) =>
          !ignored(record.target) && [...record.addedNodes, ...record.removedNodes].some((node) => structural(node))
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
