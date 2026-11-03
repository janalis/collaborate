// The plain application's page script: the bundle's entry point first (it captures `window.fetch`),
// then the rich-text editors and the tag collection's add, move and remove buttons, on the markup of
// whichever stock form theme renders the page.
import '@janalis/collaborate/easyadmin.js';
import { moveUp, startEditors } from 'editors';

startEditors();

/** The entry a collection's prototype renders: a table layout's is a row, parsed within a table body. */
function entryOf(widget) {
  const html = widget.dataset.prototype.replaceAll('__name__', `n${Date.now().toString(36)}`).trim();
  const template = document.createElement('template');
  const row = /^<tr[\s>]/i.test(html);
  template.innerHTML = row ? `<table><tbody>${html}</tbody></table>` : html;
  return row ? template.content.querySelector('tbody > *') : template.content.firstElementChild;
}

document.addEventListener('click', (event) => {
  const add = event.target.closest('[data-add-tag]');
  if (add) {
    const widget = document.getElementById(add.dataset.addTag);
    const row = entryOf(widget);
    (widget.tBodies?.[0] ?? widget).append(row);
    startEditors(row);
    return;
  }
  const item = event.target.closest('[data-collaborate-entry]');
  if (event.target.closest('[data-move-up]')) moveUp(item);
  else if (event.target.closest('[data-remove-tag]')) item.remove();
});
