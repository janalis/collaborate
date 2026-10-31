// The modal module alone, never the `bootstrap` package: EasyAdmin's own Bootstrap already registers
// the data-api handlers, and a second registration toggles every dropdown twice.
import Modal from 'bootstrap/js/src/modal.js';

/**
 * How each draft state shows: its alert variant, its icon (none: a spinner), whether a screen reader hears it,
 * and whether Discard can succeed from it.
 */
export const STATES = {
  connecting: { variant: 'secondary', icon: null, announce: true, discard: false },
  saved: { variant: 'success', icon: 'fa-circle-check', announce: true, discard: true },
  draft: { variant: 'info', icon: 'fa-pen', announce: true, discard: true },
  pending: { variant: 'info', icon: null, announce: false, discard: true },
  offline: { variant: 'warning', icon: 'fa-plug', announce: true, discard: true },
  changed: { variant: 'warning', icon: 'fa-rotate', announce: true, discard: true },
  conflict: { variant: 'warning', icon: 'fa-code-compare', announce: true, discard: true },
  discarded: { variant: 'warning', icon: 'fa-rotate-left', announce: true, discard: false },
  created: { variant: 'success', icon: 'fa-circle-check', announce: true, discard: false },
  failed: { variant: 'danger', icon: 'fa-triangle-exclamation', announce: true, discard: false },
  full: { variant: 'danger', icon: 'fa-database', announce: true, discard: true },
  denied: { variant: 'danger', icon: 'fa-lock', announce: true, discard: false },
  incompatible: { variant: 'danger', icon: 'fa-triangle-exclamation', announce: true, discard: false },
  unavailable: { variant: 'secondary', icon: 'fa-link-slash', announce: true, discard: false },
};

const element = (tag, className = '', text = '') => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
};

/** The form's collaboration bar: its state on the left, its actions on the right. */
export class CollaborateBar {
  constructor(messages, onDiscard) {
    this.messages = messages;
    this.element = element('div', 'collaborate-bar alert d-flex flex-wrap align-items-center gap-2 py-2 mb-3');
    this.element.dataset.collaborateBar = '';
    this.icon = element('span', 'collaborate-bar__icon');
    this.icon.setAttribute('aria-hidden', 'true');
    this.text = element('span', 'collaborate-bar__text');
    this.text.dataset.collaborateStatus = '';
    // One polite region, written only when what it says changes: a flush flips the state on every keystroke.
    this.announcer = element('span', 'visually-hidden');
    this.announcer.setAttribute('role', 'status');
    this.announcer.dataset.collaborateAnnouncer = '';
    this.actions = element('span', 'collaborate-bar__actions ms-auto d-flex flex-wrap gap-2');
    this.discard = element('button', 'btn btn-sm btn-secondary text-danger', messages.discard);
    this.discard.type = 'button';
    this.discard.dataset.collaborateDiscard = '';
    this.discard.addEventListener('click', onDiscard);
    this.actions.append(this.discard);
    this.element.append(this.icon, this.text, this.announcer, this.actions);
    this.key = null;
    this.busy = false;
    this.announced = '';
  }

  setState(key, text) {
    const state = STATES[key] || STATES.failed;
    this.key = key;
    for (const variant of new Set(Object.values(STATES).map(({ variant: name }) => name)))
      this.element.classList.toggle(`alert-${variant}`, variant === state.variant);
    this.icon.className = state.icon
      ? `collaborate-bar__icon fa fa-fw ${state.icon}`
      : 'collaborate-bar__icon spinner-border spinner-border-sm';
    this.text.textContent = text;
    if (state.announce) this.announce(text);
    this.gate();
  }

  announce(text) {
    if (text === this.announced) return;
    this.announced = text;
    this.announcer.textContent = text;
  }

  /** Discard waits for its own request, and stays off in the states it cannot succeed from. */
  setBusy(busy) {
    this.busy = busy;
    this.gate();
  }

  gate() {
    this.discard.disabled = this.busy || !STATES[this.key]?.discard;
  }

  /** A dismissible line under the state, for what happened before this page loaded. */
  notice(text) {
    this.noticeElement?.remove();
    this.noticeElement = element('div', 'collaborate-bar__notice alert alert-light alert-dismissible py-2 mb-0 w-100');
    this.noticeElement.dataset.collaborateNotice = '';
    this.noticeElement.append(element('span', '', text));
    const close = element('button', 'btn-close py-2');
    close.type = 'button';
    close.setAttribute('aria-label', this.messages.close);
    close.addEventListener('click', () => {
      this.noticeElement?.remove();
      this.noticeElement = null;
    });
    this.noticeElement.append(close);
    this.element.append(this.noticeElement);
    this.announce(text);
  }

  /** Copies the absolute invitation URL; where the clipboard refuses, shows it selected for copying by hand. */
  invitation(url) {
    const href = new URL(url, location.href).href;
    const button = element('button', 'btn btn-sm btn-secondary');
    button.type = 'button';
    button.dataset.collaborateInvite = '';
    const label = element('span', '', this.messages.copyInvitation);
    const icon = element('i', 'fa fa-fw fa-copy me-1');
    icon.setAttribute('aria-hidden', 'true');
    button.append(icon, label);
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(href);
      } catch {
        this.manualInvitation(href);
        return;
      }
      label.textContent = this.messages.copied;
      this.announce(this.messages.invitationCopied);
      clearTimeout(this.copiedTimer);
      this.copiedTimer = setTimeout(() => {
        label.textContent = this.messages.copyInvitation;
      }, 2000);
    });
    this.discard.before(button);
    return button;
  }

  manualInvitation(href) {
    let field = this.element.querySelector('[data-collaborate-invitation-url]');
    if (!field) {
      const wrapper = element('label', 'collaborate-bar__invitation w-100 small');
      wrapper.append(element('span', 'd-block mb-1', this.messages.copyInvitationFallback));
      field = element('input', 'form-control form-control-sm');
      field.readOnly = true;
      field.dataset.collaborateInvitationUrl = '';
      wrapper.append(field);
      this.element.append(wrapper);
    }
    field.value = href;
    field.focus();
    field.select();
  }

  openRecord(href) {
    const link = element('a', 'btn btn-sm btn-primary', this.messages.openRecord);
    link.href = href;
    link.dataset.collaborateOpenRecord = '';
    this.discard.before(link);
    return link;
  }
}

let dialog = null;

/** Asks before an action every editor of the record sees; resolves whether it was confirmed. */
export function confirmAction({ title, body, confirm, cancel }) {
  if (!dialog?.root.isConnected) {
    const root = element('div', 'modal fade');
    root.tabIndex = -1;
    root.dataset.collaborateConfirm = '';
    root.setAttribute('aria-hidden', 'true');
    root.setAttribute('aria-labelledby', 'collaborate-confirm-title');
    const content = element('div', 'modal-content');
    const header = element('div', 'modal-header');
    const heading = element('h2', 'modal-title fs-5');
    heading.id = 'collaborate-confirm-title';
    header.append(heading);
    const text = element('p', 'mb-0');
    const main = element('div', 'modal-body');
    main.append(text);
    const footer = element('div', 'modal-footer');
    const no = element('button', 'btn btn-secondary');
    no.type = 'button';
    no.dataset.collaborateConfirmCancel = '';
    const yes = element('button', 'btn btn-danger');
    yes.type = 'button';
    yes.dataset.collaborateConfirmAccept = '';
    footer.append(no, yes);
    content.append(header, main, footer);
    const frame = element('div', 'modal-dialog modal-dialog-centered');
    frame.append(content);
    root.append(frame);
    // Outside every form: a form still starting up is inert, and the dialog must not be.
    document.body.append(root);
    dialog = { root, heading, text, no, yes, modal: new Modal(root, { backdrop: true, keyboard: true }) };
    root.addEventListener('shown.bs.modal', () => dialog.no.focus());
  }
  dialog.heading.textContent = title;
  dialog.text.textContent = body;
  dialog.yes.textContent = confirm;
  dialog.no.textContent = cancel;
  return new Promise((resolve) => {
    // Bootstrap ignores a hide while the dialog still fades in: only the hide that goes ahead decides,
    // so a Confirm click it ignored is not taken for a later Cancel or Escape.
    let accepted = false;
    let accepting = false;
    const accept = () => {
      accepting = true;
      dialog.modal.hide();
      accepting = false;
    };
    const dismiss = () => dialog.modal.hide();
    const hiding = () => {
      accepted = accepting;
    };
    const hidden = () => {
      dialog.yes.removeEventListener('click', accept);
      dialog.no.removeEventListener('click', dismiss);
      dialog.root.removeEventListener('hide.bs.modal', hiding);
      dialog.root.removeEventListener('hidden.bs.modal', hidden);
      resolve(accepted);
    };
    dialog.yes.addEventListener('click', accept);
    dialog.no.addEventListener('click', dismiss);
    dialog.root.addEventListener('hide.bs.modal', hiding);
    dialog.root.addEventListener('hidden.bs.modal', hidden);
    dialog.modal.show();
  });
}
