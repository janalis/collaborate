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
        collections.get(path).scan();
      }
      session.initializeControls();
    } finally {
      applying = false;
    }
  }

  function collection(container, path) {
    const members = new Map();
    const nodes = new Map();
    let bootstrapping = true;
    let renderedOrder = [];
    const ownNodes = () =>
      Array.from(container.querySelectorAll('.field-collection-item')).filter(
        (node) =>
          node.closest('[data-collaborate-collection]') === container &&
          node.parentElement.closest('.field-collection-item')?.closest('[data-collaborate-collection]') !== container
      );

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
      node.remove();
      nodes.delete(key);
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
        container.querySelector(
          '.ea-form-collection-items .accordion > .form-widget-compound [data-empty-collection]'
        ) ||
        container.querySelector('.ea-form-collection-items > .accordion') ||
        container.querySelector('.ea-form-collection-items');
      if (!parent) return;
      const added = [];
      for (const [key] of ordered) {
        let node = nodes.get(key);
        if (!node) {
          const prototype = container.dataset.prototype;
          if (!prototype) {
            session.setStatus('incompatible');
            continue;
          }
          const placeholder = container.dataset.formTypeNamePlaceholder || '__name__';
          const template = document.createElement('template');
          template.innerHTML = prototype.replaceAll(placeholder, key);
          node = template.content.firstElementChild;
          if (!node) continue;
          node.dataset.collaborateItem = key;
          parent.append(node);
          nodes.set(key, node);
          added.push(node);
        }
      }
      const rows = ordered.map(([key]) => nodes.get(key)).filter((node) => node?.parentElement === parent);
      const focused = rows.find((node) => node.contains(document.activeElement));
      let before = null;
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
          document.dispatchEvent(
            new CustomEvent('ea.collection.item-added', { detail: { newElement: node, collection: container } })
          );
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
      // A hidden `position` child is posted from the page, not the draft: once rows move, number it in the order shown.
      if (renderedOrder.join() === shown) return;
      let position = 0;
      for (const node of ownNodes()) {
        const name = `${path}[${keyOf(node)}][position]`;
        const input = Array.from(node.querySelectorAll('input[type="hidden"]')).find((item) => item.name === name);
        if (input) input.value = ++position;
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

    for (const node of ownNodes()) {
      const key = keyOf(node);
      if (key) {
        node.dataset.collaborateItem = key;
        nodes.set(key, node);
      }
    }
    for (const [name, state] of Object.entries(session.state.fields)) {
      if (state.type === 'member' && name.startsWith(`${path}[@`) && !name.slice(path.length + 2, -1).includes(']')) {
        member(name.slice(path.length + 2, -1), state.value);
      }
    }
    bootstrapping = false;
    render();

    return {
      scan() {
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
            key = `n${crypto.randomUUID()}`;
            const before = `${path}[${old}]`;
            const after = `${path}[${key}]`;
            for (const control of node.querySelectorAll('[name]')) control.name = control.name.replace(before, after);
            for (const nested of node.querySelectorAll('[data-collaborate-collection]'))
              nested.dataset.collaborateCollection = nested.dataset.collaborateCollection.replace(before, after);
            for (const nested of node.querySelectorAll('[data-prototype]'))
              nested.dataset.prototype = nested.dataset.prototype.replaceAll(before, after);
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
          if (!seen.has(key) && value.value.alive) value.change({ ...value.value, alive: false });
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
  const relevant = '.field-collection-item, [data-collaborate-collection], [name]';
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
  document.addEventListener('ea.collection.item-added', scan);
  document.addEventListener('ea.collection.item-removed', scan);
  // Entries a replay brings are projected from the session's field states.
  session.scanCollections = scan;
  return () => {
    session.scanCollections = null;
    observer.disconnect();
    document.removeEventListener('ea.collection.item-added', scan);
    document.removeEventListener('ea.collection.item-removed', scan);
  };
}
