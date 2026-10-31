// The EasyAdmin application's page script: the bundle's entry point, then the rich-text editors of the
// form (and of collection rows a peer adds, through the bundle's row initializers), and a "Move up"
// button on each collection entry.
import '@janalis/collaborate/easyadmin.js';
import { moveUp, startEditors } from 'editors';

startEditors();

function addMoveButtons(root = document) {
  for (const header of root.querySelectorAll('.field-collection-item .accordion-header')) {
    if (header.querySelector('[data-move-up]')) continue;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-link';
    button.dataset.moveUp = '';
    button.textContent = 'Move up';
    header.append(button);
  }
}

addMoveButtons();
// A row this page adds gets its editor here; the bundle runs the row initializers on a row a peer adds.
document.addEventListener('ea.collection.item-added', (event) => {
  const row = event.detail?.newElement;
  if (!row) return;
  addMoveButtons(row.parentElement ?? document);
  startEditors(row);
});
document.addEventListener('click', (event) => {
  if (event.target.closest('[data-move-up]')) moveUp(event.target.closest('.field-collection-item'));
});

// The article form lists the latest articles below it, as a host's embedded list would: the bundle's
// saved-view refresh asks it to reload (`ea.embedded-list.reload`) and hears when it did
// (`ea.embedded-list.refreshed`).
function embedLatestArticles() {
  const form = document.querySelector('body.ea-edit-Article form[data-collaborate]');
  if (!form) return;
  const list = document.createElement('section');
  list.className = 'field-embedded-list mt-4';
  list.dataset.src = form.dataset.latest ?? '/admin/article';
  list.setAttribute('aria-label', 'Latest articles');
  const load = async () => {
    const page = new DOMParser().parseFromString(await (await fetch(list.dataset.src)).text(), 'text/html');
    const items = Array.from(page.querySelectorAll('td[data-column="title"]'), (cell) => {
      const item = document.createElement('li');
      item.textContent = cell.textContent.trim();
      return item;
    });
    const titles = document.createElement('ul');
    titles.append(...items);
    list.replaceChildren(titles);
    list.dataset.loaded = '';
    document.dispatchEvent(new CustomEvent('ea.embedded-list.refreshed', { detail: { list } }));
  };
  list.addEventListener('ea.embedded-list.reload', load);
  form.after(list);
  load();
}

embedLatestArticles();
