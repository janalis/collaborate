// The host application's page script: the bundle's cursors (no drafts), a share popup per record, and a folder
// loaded in place of the listing, whose grant the cursors then enter.
import '@janalis/collaborate/cursors/cursors.js';
import '@janalis/collaborate/notifications/bell.js';
import '@janalis/collaborate/notifications/page.js';
import '@janalis/collaborate/notifications/topics.js';

// What a host does of a notification its page heard of: a toast, which the scenarios read.
document.addEventListener('collaborate:notification', (event) => {
  const toast = document.createElement('p');
  toast.className = 'toast';
  toast.setAttribute('role', 'status');
  toast.textContent = event.detail.text;
  toast.dataset.id = String(event.detail.id);
  document.body.append(toast);
});

document.addEventListener('click', async (event) => {
  const share = event.target.closest('[data-share]');
  const dialog = document.querySelector('#share');
  if (share) {
    dialog.querySelector('[data-collaborate-popup]').dataset.key = share.dataset.share;
    dialog.showModal();
    return;
  }
  if (event.target.closest('[data-close]')) {
    dialog.close();
    return;
  }
  const link = event.target.closest('[data-folder-link]');
  if (!link) return;
  event.preventDefault();
  const response = await fetch(link.dataset.folderLink);
  const listing = document.querySelector('#listing');
  listing.innerHTML = await response.text();
  history.pushState(null, '', link.href);
  const grant = listing.querySelector('[data-collaborate-grant]')?.dataset.collaborateGrant;
  document.dispatchEvent(new CustomEvent('collaborate:enter', { detail: { grant } }));
});
