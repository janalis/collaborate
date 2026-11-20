// `bootstrap/js/src/modal.js` for the bundle's confirmations under Drupal, on a native <dialog>: the subset of
// Bootstrap's modal the state bar uses (show, hide, and its show/shown/hide/hidden events), so that no Bootstrap
// is loaded beside Claro or Gin.
export default class Modal {
  /**
   * @param {HTMLElement} element
   * @param {{ backdrop?: boolean | 'static', keyboard?: boolean }} [options]
   */
  constructor(element, { backdrop = true, keyboard = true } = {}) {
    this.element = element;
    this.backdrop = backdrop;
    this.keyboard = keyboard;
    this.dialog = element.closest('dialog') ?? Modal.wrap(element);
    this.dialog.addEventListener('cancel', (/** @type {Event} */ event) => {
      event.preventDefault();
      if (this.keyboard) this.hide();
    });
    this.dialog.addEventListener('click', (/** @type {MouseEvent} */ event) => {
      // Bootstrap's `'static'` backdrop keeps the dialog open.
      if (this.backdrop === true && event.target === this.dialog) this.hide();
    });
  }

  /**
   * The bar's markup is Bootstrap's `.modal`: a dialog holds it, so that the browser gives it a top layer.
   *
   * @param {HTMLElement} element
   */
  static wrap(element) {
    const dialog = document.createElement('dialog');
    dialog.className = 'collaborate-dialog';
    element.replaceWith(dialog);
    dialog.append(element);
    return dialog;
  }

  /** @param {string} name */
  emit(name) {
    const event = new Event(`${name}.bs.modal`, { cancelable: true });
    this.element.dispatchEvent(event);
    return !event.defaultPrevented;
  }

  show() {
    if (this.dialog.open || !this.emit('show')) return;
    this.element.classList.add('show');
    this.element.style.display = 'block';
    this.dialog.showModal();
    this.emit('shown');
  }

  hide() {
    if (!this.dialog.open || !this.emit('hide')) return;
    this.element.classList.remove('show');
    this.element.style.display = '';
    this.dialog.close();
    this.emit('hidden');
  }

  /**
   * @param {HTMLElement & { collaborateModal?: Modal }} element
   * @param {{ backdrop?: boolean | 'static', keyboard?: boolean }} [options]
   */
  static getOrCreateInstance(element, options) {
    element.collaborateModal ??= new Modal(element, options);
    return element.collaborateModal;
  }
}
