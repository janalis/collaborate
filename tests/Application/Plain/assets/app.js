// The plain application's page script: the bundle's entry point first (it captures `window.fetch`),
// then the rich-text editors and the tag collection's add, move and remove buttons.
import '@janalis/collaborate/easyadmin.js';
import { moveUp, startEditors } from 'editors';

startEditors();

document.addEventListener('click', (event) => {
  const add = event.target.closest('[data-add-tag]');
  if (add) {
    const container = add.closest('[data-tags]');
    const template = document.createElement('template');
    template.innerHTML = container.dataset.prototype.replaceAll('__name__', `n${Date.now().toString(36)}`).trim();
    const row = template.content.firstElementChild;
    container.querySelector('.ea-form-collection-items').append(row);
    startEditors(row);
    return;
  }
  const item = event.target.closest('.field-collection-item');
  if (event.target.closest('[data-move-up]')) moveUp(item);
  else if (event.target.closest('[data-remove-tag]')) item.remove();
});
