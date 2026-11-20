// @ts-check
// The menu a button opens (the presence list's, the bell's): a popover under the button, at its end, whose first
// item takes the focus, and whose arrows, Home and End go from one item to the next. The button's `aria-expanded`
// follows it.

/**
 * @param {HTMLElement} toggle the button whose `popovertarget` the menu is
 * @param {HTMLElement} menu the `popover` element holding the `menuitem*` items
 * @param {{ closed?: () => void }} [hooks] `closed`: what the owner does once the menu closes
 * @returns {() => void} unbinds the menu
 */
export function popoverMenu(toggle, menu, { closed = () => {} } = {}) {
  const items = () => /** @type {HTMLElement[]} */ ([...menu.querySelectorAll('[role^="menuitem"]:not([hidden])')]);

  /** @param {KeyboardEvent} event */
  function keydown(event) {
    const list = items();
    if (!list.length) return;
    const index = list.indexOf(/** @type {HTMLElement} */ (document.activeElement));
    const next = {
      ArrowDown: (index + 1) % list.length,
      ArrowUp: (index - 1 + list.length) % list.length,
      Home: 0,
      End: list.length - 1,
    }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    list[next].focus();
  }

  /** @param {Event} event a ToggleEvent */
  function toggled(event) {
    const open = /** @type {ToggleEvent} */ (event).newState === 'open';
    toggle.setAttribute('aria-expanded', String(open));
    if (!open) {
      closed();
      return;
    }
    const box = toggle.getBoundingClientRect();
    menu.style.top = `${box.bottom + 4}px`;
    menu.style.left = `${Math.max(8, box.right - menu.offsetWidth)}px`;
    items()[0]?.focus();
  }

  menu.addEventListener('keydown', keydown);
  menu.addEventListener('toggle', toggled);
  return () => {
    menu.removeEventListener('keydown', keydown);
    menu.removeEventListener('toggle', toggled);
  };
}
