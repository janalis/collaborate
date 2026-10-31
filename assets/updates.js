import { hubFor } from './hub.js';

const stateKey = `collaborate-update:${location.pathname}${location.search}`;

export function initUpdates(
  layer = document.querySelector('[data-collaborate-layer]'),
  hub = hubFor(layer),
  reload = () => location.reload()
) {
  if (!layer?.dataset.updates || !hub) return null;
  let leave;
  let refresh;
  let stopped = false;
  let suspended = false;
  const deferredLists = new Map();
  // Lists loading, by when the load started; lists that heard of a save meanwhile, and their retries.
  const reloadingLists = new Map();
  const staleLists = new Set();
  const retries = new Map();
  // A list without content is on its first load.
  for (const list of document.querySelectorAll('.field-embedded-list[data-src]:not([data-loaded])'))
    reloadingLists.set(list, Date.now());
  // EasyAdmin names the page's entity in its body class (`ea-index-Product`, `ea-edit-Product`…).
  const pageEntity = /\bea-(?:index|detail|edit|new)-(\S+)/.exec(document.body.className)?.[1];
  const saved = sessionStorage.getItem(stateKey);
  if (saved) {
    sessionStorage.removeItem(stateKey);
    try {
      const state = JSON.parse(saved);
      requestAnimationFrame(() => {
        window.scrollTo(state.x, state.y);
        for (const checkbox of document.querySelectorAll('.form-batch-checkbox')) {
          if (state.selected.includes(checkbox.value)) {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        const focus = state.focusRow
          ? Array.from(document.querySelectorAll('.form-batch-checkbox')).find(
              (input) => input.value === state.focusRow
            )
          : document.getElementById(state.focusId);
        focus?.focus({ preventScroll: true });
      });
    } catch {
      /* A stale browser snapshot must not prevent normal navigation. */
    }
  }

  function deferList(list) {
    if (deferredLists.has(list)) return;
    // Focus has not landed on the next control yet while focusout runs, nor in its microtasks: the event names it.
    const blur = (event) => {
      if (list.contains(event.relatedTarget)) return;
      list.removeEventListener('focusout', blur);
      deferredLists.delete(list);
      reloadList(list);
    };
    deferredLists.set(list, blur);
    list.addEventListener('focusout', blur);
  }

  function refreshList(list) {
    if (list.contains(document.activeElement)) deferList(list);
    else reloadList(list);
  }

  // EasyAdmin aborts a list's pending load for a reload: saves elsewhere arriving faster than a slow list
  // renders would keep it from ever loading, and a page change the user asked for would be undone.
  // A list still loading refreshes once that load lands. A load that fails announces nothing, so the
  // refresh also runs once the load stops holding the list, 30 s after it started.
  function reloadList(list) {
    const wait = (reloadingLists.get(list) ?? -Infinity) + 30_000 - Date.now();
    if (wait > 0) {
      staleLists.add(list);
      if (!retries.has(list))
        retries.set(
          list,
          setTimeout(() => {
            retries.delete(list);
            // A load started since, the user's own page change included, holds the list in turn.
            if ((reloadingLists.get(list) ?? -Infinity) + 30_000 > Date.now()) return reloadList(list);
            reloadingLists.delete(list);
            if (staleLists.delete(list)) refreshList(list);
          }, wait)
        );
      return;
    }
    reloadingLists.set(list, Date.now());
    list.dispatchEvent(new Event('ea.embedded-list.reload'));
  }

  function listLoaded(event) {
    const list = event.detail?.list;
    reloadingLists.delete(list);
    clearTimeout(retries.get(list));
    retries.delete(list);
    if (staleLists.delete(list)) refreshList(list);
  }

  // A link or form EasyAdmin binds inside a list loads its page in place.
  function listNavigated(event) {
    const control = event.type === 'submit' ? event.target : event.target.closest?.('a');
    const list = control?.closest('.field-embedded-list');
    if (list && control.dataset.eaFragmentBound) reloadingLists.set(list, Date.now());
  }

  // Saved events name the entities they can affect; a page that cannot tell refreshes for all of them.
  function concerns({ entities }) {
    return !pageEntity || !Array.isArray(entities) || entities.includes(pageEntity);
  }

  // Text typed into a search or filter box and left unsubmitted would be lost to the refresh; ticked rows are restored.
  function typed() {
    return Array.from(
      document.querySelectorAll(
        'input:not([type="checkbox"], [type="radio"], [type="hidden"], [type="submit"], [type="button"]), textarea'
      )
    ).some((control) => control.value !== control.defaultValue);
  }

  // A reload issued while the user's own navigation is pending would cancel it.
  function postpone() {
    clearTimeout(refresh);
    refresh = null;
    window.removeEventListener('beforeunload', postpone);
    // A download or a 204 leaves the page in place: refresh on the next interaction.
    window.addEventListener('pointerdown', resume, true);
    window.addEventListener('keydown', resume, true);
  }

  function resume() {
    window.removeEventListener('pointerdown', resume, true);
    window.removeEventListener('keydown', resume, true);
    changed();
  }

  // One refresh a second at most, counted from the first change: a steady stream of saves elsewhere
  // must not postpone it indefinitely.
  function changed() {
    if (stopped || suspended || refresh) return;
    window.addEventListener('beforeunload', postpone);
    refresh = setTimeout(() => {
      refresh = null;
      window.removeEventListener('beforeunload', postpone);
      const activeForm = document.querySelector('form[data-collaborate]');
      if (
        document.hidden ||
        document.querySelector('.modal.show, .offcanvas.show, [aria-expanded="true"].dropdown-toggle') ||
        (!activeForm &&
          // A ticked row gets its selection and focus back after the refresh: a click leaves focus on it.
          (document.activeElement?.matches(
            'input:not(.form-batch-checkbox), textarea, select, [contenteditable="true"]'
          ) ||
            typed()))
      ) {
        changed();
        return;
      }
      // The form hears of its own record on the draft's topic; here only its embedded lists refresh.
      if (activeForm) {
        for (const list of document.querySelectorAll('.field-embedded-list[data-src]')) refreshList(list);
        return;
      }
      if (!document.body.classList.contains('ea-index') && !document.body.classList.contains('ea-detail')) return;
      sessionStorage.setItem(
        stateKey,
        JSON.stringify({
          x: window.scrollX,
          y: window.scrollY,
          selected: Array.from(document.querySelectorAll('.form-batch-checkbox:checked'), (input) => input.value),
          focusId: document.activeElement?.id,
          focusRow: document.activeElement?.classList.contains('form-batch-checkbox')
            ? document.activeElement.value
            : null,
        })
      );
      reload();
    }, 1000);
  }

  // The page's one Mercure stream carries the saved-views topic. The hub keeps no history: a save published
  // while the stream was lost is missed, so its return refreshes the page as if one concerned it. A stream
  // that never opened lost nothing: the page's first opening refreshes nothing, however many attempts it took.
  function connect() {
    let streamed = false;
    let lost = false;
    leave = hub.join({
      channel: 'updates',
      receive: (_message, data) => {
        if (concerns(data)) changed();
      },
      opened: () => {
        streamed = true;
        if (!lost) return;
        lost = false;
        changed();
      },
      closed: () => {
        lost = streamed;
      },
      denied: stop,
    });
  }

  function listen() {
    document.addEventListener('ea.embedded-list.refreshed', listLoaded);
    document.addEventListener('click', listNavigated, true);
    document.addEventListener('submit', listNavigated, true);
  }

  function suspend() {
    suspended = true;
    leave?.();
    leave = null;
    clearTimeout(refresh);
    refresh = null;
    window.removeEventListener('beforeunload', postpone);
    window.removeEventListener('pointerdown', resume, true);
    window.removeEventListener('keydown', resume, true);
    for (const [list, listener] of deferredLists) list.removeEventListener('focusout', listener);
    deferredLists.clear();
    document.removeEventListener('ea.embedded-list.refreshed', listLoaded);
    document.removeEventListener('click', listNavigated, true);
    document.removeEventListener('submit', listNavigated, true);
    for (const retry of retries.values()) clearTimeout(retry);
    retries.clear();
    reloadingLists.clear();
    staleLists.clear();
  }
  function hide(event) {
    if (event.persisted) suspend();
    else stop();
  }
  function restore(event) {
    if (!event.persisted || stopped || !suspended) return;
    suspended = false;
    listen();
    connect();
    changed();
  }
  function stop() {
    stopped = true;
    suspend();
    window.removeEventListener('pagehide', hide);
    window.removeEventListener('pageshow', restore);
  }
  window.addEventListener('pagehide', hide);
  window.addEventListener('pageshow', restore);
  listen();
  connect();
  return { stop, changed };
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initUpdates());
else initUpdates();
