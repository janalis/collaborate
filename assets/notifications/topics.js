// @ts-check
// The topics a user is notified of (templates/notifications/_topics.html.twig): a switch each, which posts its
// change at once with the topics' CSRF token. The switch moves before the server answers, and moves back when
// it refused (a mandatory topic, a session gone).
//
// The status under the list says the outcome when the markup gives the sentences (`data-subscribed`,
// `data-unsubscribed`, `data-failed` on `.collaborate-notification-topics__status`, `%topic%` standing for
// the topic's label); without them it says nothing, the switch alone telling.
import { backgroundFetch, isAccepted } from '../http.js';
import { settingsOf } from './shared.js';

/**
 * Starts the switches of a topics list.
 *
 * @param {Element} root
 * @param {{ fetcher?: typeof fetch }} [options]
 */
export function initTopics(root, { fetcher = backgroundFetch } = {}) {
  const settings = settingsOf(root, 'data-collaborate-notification-topics');
  const status = /** @type {HTMLElement | null} */ (root.querySelector('.collaborate-notification-topics__status'));
  // The latest change of each switch: an earlier answer arriving after it changes nothing.
  /** @type {WeakMap<HTMLInputElement, number>} */
  const turns = new WeakMap();

  /**
   * @param {string} key
   * @param {HTMLInputElement} input
   */
  function say(key, input) {
    if (!status) return;
    const sentence = status.dataset[key];
    const label = input.closest('label')?.querySelector('.collaborate-notification-topic__label')?.textContent.trim();
    status.textContent = sentence ? sentence.replace('%topic%', label ?? input.name) : '';
  }

  /** @param {HTMLInputElement} input */
  async function send(input) {
    const on = input.checked;
    const turn = (turns.get(input) ?? 0) + 1;
    turns.set(input, turn);
    let ok = false;
    try {
      const response = await fetcher(/** @type {string} */ (on ? input.dataset.subscribe : input.dataset.unsubscribe), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': settings.topicsToken ?? '',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      ok = isAccepted(response);
    } catch {
      ok = false;
    }
    if (turns.get(input) !== turn) return;
    if (!ok) input.checked = !on;
    say(ok ? (on ? 'subscribed' : 'unsubscribed') : 'failed', input);
  }

  /** @param {Event} event */
  function change(event) {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('input[role="switch"]')) send(input);
  }

  root.addEventListener('change', change);
  return { stop: () => root.removeEventListener('change', change) };
}

const started = new WeakSet();

/** Starts every topics list of the page that has not started yet. */
export function startTopics() {
  for (const root of document.querySelectorAll('[data-collaborate-notification-topics]')) {
    if (started.has(root)) continue;
    started.add(root);
    initTopics(root);
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startTopics);
else startTopics();
