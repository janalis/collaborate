vi.mock('bootstrap/js/src/modal.js', () => ({
  default: class Modal {
    // As Bootstrap's: a hide asked while the dialog still fades in is ignored.
    static fading = false;

    constructor(element) {
      this.element = element;
    }

    show() {
      this.element.dispatchEvent(new Event('shown.bs.modal'));
    }

    hide() {
      if (Modal.fading) return;
      this.element.dispatchEvent(new Event('hide.bs.modal'));
      this.element.dispatchEvent(new Event('hidden.bs.modal'));
    }
  },
}));

import Modal from 'bootstrap/js/src/modal.js';
import { CollaborateBar, confirmAction, STATES } from '../../../assets/draft/bar.js';

const messages = {
  save: 'Save',
  discard: 'Discard changes',
  close: 'Close',
  copyInvitation: 'Copy invitation link',
  copied: 'Copied',
  invitationCopied: 'Invitation link copied.',
  copyInvitationFallback: 'Copy this link:',
  openRecord: 'Open saved record',
};

function bar(onDiscard = vi.fn(), onSave = vi.fn()) {
  const created = new CollaborateBar(messages, onDiscard, onSave);
  document.body.append(created.element);
  return created;
}

afterEach(() => {
  document.body.innerHTML = '';
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

test('shows each state in its alert variant and icon, a spinner while it waits', () => {
  const shown = bar();
  shown.setState('saved', 'All changes saved.');
  expect(shown.element.className).toContain('alert-success');
  expect(shown.icon.className).toContain('fa-circle-check');
  expect(shown.text.textContent).toBe('All changes saved.');
  shown.setState('denied', 'Access expired.');
  expect(shown.element.classList.contains('alert-success')).toBe(false);
  expect(shown.element.className).toContain('alert-danger');
  shown.setState('pending', 'Synchronizing…');
  expect(shown.icon.className).toContain('spinner-border');
  expect(shown.element.className).toContain('alert-info');
  // An unknown state reads as a refusal rather than as a success.
  shown.setState('unknown', 'Unknown');
  expect(shown.element.className).toContain(`alert-${STATES.failed.variant}`);
  expect(shown.discard.disabled).toBe(true);
});

test('announces a state once, and never the synchronization a flush flips through', () => {
  const shown = bar();
  const announcer = shown.element.querySelector('[role="status"]');
  shown.setState('draft', 'Unsaved changes.');
  expect(announcer.textContent).toBe('Unsaved changes.');
  announcer.textContent = '';
  shown.setState('pending', 'Synchronizing…');
  shown.setState('draft', 'Unsaved changes.');
  expect(announcer.textContent).toBe('');
  shown.setState('saved', 'Saved.');
  expect(announcer.textContent).toBe('Saved.');
  // The visible text is not a live region: it changes on every flush.
  expect(shown.text.getAttribute('role')).toBeNull();
});

test('enables Discard only where it can succeed and while no request of its own runs', () => {
  const onDiscard = vi.fn();
  const shown = bar(onDiscard);
  shown.setState('connecting', 'Connecting…');
  expect(shown.discard.disabled).toBe(true);
  shown.setState('full', 'Limit reached.');
  expect(shown.discard.disabled).toBe(false);
  shown.setBusy(true);
  expect(shown.discard.disabled).toBe(true);
  shown.setBusy(false);
  shown.discard.click();
  expect(onDiscard).toHaveBeenCalledOnce();
  for (const key of ['saved', 'discarded', 'created', 'failed', 'denied', 'incompatible', 'unavailable']) {
    shown.setState(key, key);
    expect(shown.discard.disabled).toBe(true);
    expect(shown.discard.hidden).toBe(true);
  }
});

test('offers Save while the draft holds unsaved changes, first among its actions, and hides it otherwise', () => {
  const onSave = vi.fn();
  const shown = bar(vi.fn(), onSave);
  expect([...shown.actions.children]).toEqual([shown.save, shown.discard]);
  expect(shown.save.textContent).toBe('Save');
  expect(shown.save.type).toBe('button');
  for (const key of ['draft', 'pending', 'offline', 'changed']) {
    shown.setState(key, key);
    expect(shown.save.hidden).toBe(false);
    expect(shown.save.disabled).toBe(false);
    // Unsaved changes always come with Save beside Discard: Discard is never the bar's only way out.
    expect(shown.discard.hidden).toBe(false);
  }
  shown.setBusy(true);
  expect(shown.save.disabled).toBe(true);
  shown.setBusy(false);
  shown.save.click();
  expect(onSave).toHaveBeenCalledOnce();
  for (const key of ['connecting', 'saved', 'conflict', 'full', 'discarded', 'created', 'failed', 'denied']) {
    shown.setState(key, key);
    expect(shown.save.hidden).toBe(true);
    expect(shown.save.disabled).toBe(true);
  }
});

test('offers no Save without a handler for it', () => {
  const shown = new CollaborateBar(messages, vi.fn());
  shown.setState('draft', 'Unsaved.');
  expect(shown.save.hidden).toBe(true);
  expect(shown.discard.hidden).toBe(false);
});

test('puts the invitation and open-record buttons before Save', () => {
  const shown = bar();
  const invite = shown.invitation('/new?draft=1');
  const open = shown.openRecord('/record/1/edit');
  expect([...shown.actions.children]).toEqual([invite, open, shown.save, shown.discard]);
});

test('shows a dismissible notice, replacing the previous one, and announces it', () => {
  const shown = bar();
  shown.notice('<b>First</b>');
  shown.notice('You discarded the shared draft.');
  const notices = shown.element.querySelectorAll('[data-collaborate-notice]');
  expect(notices).toHaveLength(1);
  expect(notices[0].querySelector('b')).toBeNull();
  expect(shown.element.querySelector('[role="status"]').textContent).toBe('You discarded the shared draft.');
  const close = notices[0].querySelector('.btn-close');
  expect(close.getAttribute('aria-label')).toBe('Close');
  close.click();
  expect(shown.element.querySelector('[data-collaborate-notice]')).toBeNull();
});

test('copies the absolute invitation link and says so for two seconds', async () => {
  vi.useFakeTimers();
  const writeText = vi.fn(async () => {});
  vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
  const shown = bar();
  const button = shown.invitation('/admin/product/new?draft=abc');
  expect(button.nextElementSibling).toBe(shown.save);
  button.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(writeText).toHaveBeenCalledWith(new URL('/admin/product/new?draft=abc', location.href).href);
  expect(button.textContent).toBe('Copied');
  expect(shown.element.querySelector('[role="status"]').textContent).toBe('Invitation link copied.');
  button.click();
  await vi.advanceTimersByTimeAsync(1500);
  expect(button.textContent).toBe('Copied');
  await vi.advanceTimersByTimeAsync(500);
  expect(button.textContent).toBe('Copy invitation link');
});

test('shows the invitation link selected for copying by hand where the clipboard refuses', async () => {
  vi.stubGlobal('navigator', {
    ...navigator,
    clipboard: { writeText: vi.fn(async () => Promise.reject(new Error('denied'))) },
  });
  const shown = bar();
  const button = shown.invitation('/new?draft=abc');
  button.click();
  const field = await vi.waitFor(() => {
    const found = shown.element.querySelector('[data-collaborate-invitation-url]');
    expect(found).not.toBeNull();
    return found;
  });
  expect(field.value).toBe(new URL('/new?draft=abc', location.href).href);
  expect(field.readOnly).toBe(true);
  expect(document.activeElement).toBe(field);
  expect(field.closest('label').textContent).toContain('Copy this link:');
  button.click();
  await vi.waitFor(() => expect(document.activeElement).toBe(field));
  expect(shown.element.querySelectorAll('[data-collaborate-invitation-url]')).toHaveLength(1);
});

test('offers the saved record beside Save and Discard', () => {
  const shown = bar();
  const link = shown.openRecord('/record/42/edit');
  expect(link.getAttribute('href')).toBe('/record/42/edit');
  expect(link.textContent).toBe('Open saved record');
  expect(link.nextElementSibling).toBe(shown.save);
});

test('asks in a dialog outside the form, Cancel focused, and resolves whether it was confirmed', async () => {
  document.body.innerHTML = '<form inert></form>';
  const asked = confirmAction({ title: 'Discard?', body: '<i>Everyone</i>', confirm: 'Discard', cancel: 'Cancel' });
  const dialog = document.querySelector('[data-collaborate-confirm]');
  expect(dialog.parentElement).toBe(document.body);
  expect(dialog.querySelector('h2').textContent).toBe('Discard?');
  expect(dialog.querySelector('.modal-body i')).toBeNull();
  expect(document.activeElement).toBe(dialog.querySelector('[data-collaborate-confirm-cancel]'));
  dialog.querySelector('[data-collaborate-confirm-accept]').click();
  await expect(asked).resolves.toBe(true);

  const declined = confirmAction({ title: 'Use saved?', body: 'Replaced.', confirm: 'Use', cancel: 'Cancel' });
  expect(document.querySelectorAll('[data-collaborate-confirm]')).toHaveLength(1);
  expect(dialog.querySelector('[data-collaborate-confirm-accept]').textContent).toBe('Use');
  dialog.querySelector('[data-collaborate-confirm-cancel]').click();
  await expect(declined).resolves.toBe(false);

  // Escape or the backdrop hide it without confirming.
  const escaped = confirmAction({ title: 'Discard?', body: '', confirm: 'Discard', cancel: 'Cancel' });
  dialog.dispatchEvent(new Event('hidden.bs.modal'));
  await expect(escaped).resolves.toBe(false);

  // A Confirm click while the dialog still fades in is ignored, so a Cancel after it declines.
  const early = confirmAction({ title: 'Discard?', body: '', confirm: 'Discard', cancel: 'Cancel' });
  Modal.fading = true;
  dialog.querySelector('[data-collaborate-confirm-accept]').click();
  Modal.fading = false;
  dialog.querySelector('[data-collaborate-confirm-cancel]').click();
  await expect(early).resolves.toBe(false);

  // A page that replaced its body builds the dialog again.
  document.body.innerHTML = '';
  const again = confirmAction({ title: 'Again?', body: '', confirm: 'Yes', cancel: 'No' });
  expect(document.querySelector('[data-collaborate-confirm] h2').textContent).toBe('Again?');
  document.querySelector('[data-collaborate-confirm-accept]').click();
  await expect(again).resolves.toBe(true);
});
