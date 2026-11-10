// `bootstrap/js/src/modal.js` for the bundle's confirmations under Drupal, on a native <dialog>: the subset of
// Bootstrap's modal the state bar uses (show, hide, and its show/shown/hide/hidden events), so that no Bootstrap
// is loaded beside Claro or Gin.
export default class Modal {
  constructor(element, { backdrop = true, keyboard = true } = {}) {
    this.element = element;
    this.backdrop = backdrop;
    this.keyboard = keyboard;
    this.dialog = element.closest('dialog') ?? Modal.wrap(element);
    this.dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      if (this.keyboard) this.hide();
    });
    this.dialog.addEventListener('click', (event) => {
      if (this.backdrop && event.target === this.dialog) this.hide();
    });
  }

  /** The bar's markup is Bootstrap's `.modal`: a dialog holds it, so that the browser gives it a top layer. */
  static wrap(element) {
    const dialog = document.createElement('dialog');
    dialog.className = 'collaborate-dialog';
    element.replaceWith(dialog);
    dialog.append(element);
    return dialog;
  }

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

  static getOrCreateInstance(element, options) {
    element.collaborateModal ??= new Modal(element, options);
    return element.collaborateModal;
  }
}
