import Modal from '../../../drupal/js/modal.js';

// jsdom has no top layer: a dialog opens and closes by its `open` attribute.
beforeAll(() => {
  HTMLDialogElement.prototype.showModal ??= function () {
    this.open = true;
  };
  HTMLDialogElement.prototype.close ??= function () {
    this.open = false;
  };
});

afterEach(() => {
  document.body.innerHTML = '';
});

const modal = () => {
  document.body.innerHTML = '<div class="modal"><button>OK</button></div>';
  return document.querySelector('.modal');
};

test('a modal opens in a dialog of its own and tells Bootstrap’s events in order', () => {
  const element = modal();
  const events = [];
  for (const name of ['show', 'shown', 'hide', 'hidden'])
    element.addEventListener(`${name}.bs.modal`, () => events.push(name));
  const instance = Modal.getOrCreateInstance(element);
  expect(Modal.getOrCreateInstance(element)).toBe(instance);
  expect(element.parentElement.tagName).toBe('DIALOG');
  expect(element.parentElement.className).toBe('collaborate-dialog');

  instance.show();
  instance.show();
  expect([element.classList.contains('show'), element.style.display, instance.dialog.open]).toEqual([
    true,
    'block',
    true,
  ]);
  instance.hide();
  instance.hide();
  expect([element.classList.contains('show'), element.style.display, instance.dialog.open]).toEqual([false, '', false]);
  expect(events).toEqual(['show', 'shown', 'hide', 'hidden']);
});

test('a listener that prevents show or hide keeps the modal as it was', () => {
  const element = modal();
  const instance = new Modal(element);
  element.addEventListener('show.bs.modal', (event) => event.preventDefault(), { once: true });
  instance.show();
  expect(instance.dialog.open).toBe(false);
  instance.show();
  element.addEventListener('hide.bs.modal', (event) => event.preventDefault(), { once: true });
  instance.hide();
  expect(instance.dialog.open).toBe(true);
});

test('Escape and the backdrop close it unless the options say otherwise', () => {
  const element = modal();
  const instance = new Modal(element);
  instance.show();
  const cancel = new Event('cancel', { cancelable: true });
  instance.dialog.dispatchEvent(cancel);
  expect([cancel.defaultPrevented, instance.dialog.open]).toEqual([true, false]);
  instance.show();
  element.querySelector('button').click();
  expect(instance.dialog.open).toBe(true);
  instance.dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  expect(instance.dialog.open).toBe(false);

  // A dialog the markup already wraps it in is the one it opens.
  document.body.innerHTML = '<dialog><div class="modal"></div></dialog>';
  const wrapped = document.querySelector('.modal');
  const fixed = new Modal(wrapped, { backdrop: false, keyboard: false });
  expect(fixed.dialog).toBe(document.querySelector('dialog'));
  fixed.show();
  fixed.dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
  fixed.dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  expect(fixed.dialog.open).toBe(true);

  // Bootstrap's static backdrop keeps it open too.
  const stays = new Modal(modal(), { backdrop: 'static' });
  stays.show();
  stays.dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  expect(stays.dialog.open).toBe(true);
});
