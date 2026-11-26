import { textChange } from '@janalis/collaborate/ckeditor5/text-change.js';
import { SourceAdapter } from '@janalis/collaborate/draft/source-adapter.js';

// A deliberately small host transport: one serialized queue polls the durable journal.
// Production hosts can wake this same replay loop with a private hub notification.
const root = document.querySelector('#shared-text');
const session = JSON.parse(root.dataset.session);
const source = document.querySelector('#source');
const preview = document.querySelector('#preview');
const status = document.querySelector('#status');
const field = session.state.fields.source;
const adapter = new SourceAdapter({ initial: field.initial, events: field.sourceEvents || [] });
let revision = session.state.revision;
let digest = session.state.digest;
let stopped = false;
let composing = false;
const receive = (event) => {
  if (event.revision <= revision) return;
  adapter.receive(event);
  revision = event.revision;
};
const render = () => {
  // A remote edit maps the active selection through the shared model.
  if (source.value !== adapter.value) source.value = adapter.value;
  preview.textContent = adapter.value;
  document.querySelector('#undo').disabled = !adapter.canUndo;
  document.querySelector('#redo').disabled = !adapter.canRedo;
};
adapter.subscribe(render);
render();
const input = () => {
  if (composing) return;
  const { prefix, end, inserted } = textChange(adapter.value, source.value);
  adapter.transact([{ start: prefix, end, text: inserted }]);
};
source.addEventListener('input', input);
source.addEventListener('compositionstart', () => {
  composing = true;
});
source.addEventListener('compositionend', () => {
  composing = false;
  input();
});
document.querySelector('#wrap').addEventListener('click', () => {
  const start = source.selectionStart;
  const end = source.selectionEnd;
  if (start === end) return;
  adapter.transact([
    { start, end: start, text: '[' },
    { start: end, end, text: ']' },
  ]);
});
document.querySelector('#undo').addEventListener('click', () => adapter.undo());
document.querySelector('#redo').addEventListener('click', () => adapter.redo());
const request = async (action, payload) => {
  const response = await fetch(root.dataset.endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token },
    body: JSON.stringify({ action, grant: session.grant, ...payload }),
  });
  if (response.status === 409) return null;
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
};
const tick = async () => {
  if (stopped) return;
  try {
    if (!composing) {
      const state = await request('replay', { since: revision, digest });
      if (!state || state.generation !== session.state.generation) throw new Error('Reload to recover this session.');
      const selection =
        document.activeElement === source ? adapter.track(source.selectionStart, source.selectionEnd) : null;
      try {
        for (const event of state.events) receive(event);
        digest = state.digest;
        if (selection) source.setSelectionRange(selection.start, selection.end);
      } finally {
        selection?.detach();
      }
      const batch = adapter.next();
      if (batch) {
        const event = await request('append', {
          event: { kind: 'operations', field: 'source', generation: state.generation, seen: revision, ...batch },
        });
        if (event) receive(event);
      }
      render();
      status.textContent = adapter.hasPending() ? 'Saving' : 'Saved';
    }
  } catch (error) {
    status.textContent = `Disconnected: ${error.message}`;
    if (/HTTP (401|403)/.test(error.message)) stopped = true;
  } finally {
    if (!stopped) setTimeout(tick, 150);
  }
};
window.addEventListener('pagehide', () => {
  stopped = true;
  adapter.destroy();
});
tick();
