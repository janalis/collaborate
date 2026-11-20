import { Model } from 'ckeditor5';
import { OperationBuffer } from '../../../assets/ckeditor5/operation-buffer.js';

// Every peer of a draft ends on the same document, whatever the edits and however late each hears of the others':
// the journal accepts a batch only at its current version (DraftJournal::append()), a page sends again once it has
// replayed what it missed. 100% mutation score proves each line is tested, not that the peers converge.

/** A small deterministic generator (mulberry32): a failing seed replays. */
function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state + 0x6d2b79f5) >>> 0;
    let t = state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function peer() {
  const model = new Model();
  model.document.createRoot();
  model.schema.register('paragraph', { inheritAllFrom: '$block' });
  model.schema.extend('$text', { allowAttributes: ['bold'] });
  model.change((writer) => {
    const paragraph = writer.createElement('paragraph');
    writer.append(paragraph, model.document.getRoot());
    writer.insertText('abcdef', paragraph, 0);
  });
  return { model, buffer: new OperationBuffer(model), heard: 0, inflight: null };
}

const paragraph = (p) => p.model.document.getRoot().getChild(0);
const content = (p) => Array.from(paragraph(p).getChildren(), (node) => node.toJSON());

function edit(p, next) {
  const length = paragraph(p).maxOffset;
  const at = Math.floor(next() * (length + 1));
  const kind = next();
  p.model.change((writer) => {
    if (kind < 0.5 || length === 0) {
      writer.insertText('xyz'[Math.floor(next() * 3)].repeat(1 + Math.floor(next() * 2)), paragraph(p), at);
      return;
    }
    const end = Math.min(length, at + 1 + Math.floor(next() * 3));
    const start = Math.min(at, end - 1);
    const range = writer.createRange(
      writer.createPositionAt(paragraph(p), start),
      writer.createPositionAt(paragraph(p), end)
    );
    if (kind < 0.8) writer.remove(range);
    else writer.setAttribute('bold', next() < 0.5, range);
  });
}

function simulate(seed, { peers = 3, steps = 80 } = {}) {
  const next = random(seed);
  const all = Array.from({ length: peers }, peer);
  const journal = { version: all[0].buffer.version, batches: [] };
  const hear = (p) => {
    const batch = journal.batches[p.heard++];
    p.buffer.receive(batch);
    if (p.inflight === batch.id) p.inflight = null;
  };
  const send = (p) => {
    // A batch the journal holds is not sent again before the page has heard it.
    if (p.inflight || !p.buffer.hasPending()) return;
    const batch = JSON.parse(JSON.stringify(p.buffer.next()));
    // Refused at another version: the page replays, which rebases what it holds.
    if (batch.version !== journal.version) return;
    journal.batches.push(batch);
    journal.version += batch.operations.length;
    p.inflight = batch.id;
  };
  try {
    for (let step = 0; step < steps; step++) {
      const p = all[Math.floor(next() * peers)];
      const action = next();
      if (action < 0.45) edit(p, next);
      else if (action < 0.7) send(p);
      else if (p.heard < journal.batches.length) hear(p);
    }
    // Everyone hears everything and sends what is left, until nothing is.
    for (let round = 0; all.some((p) => p.buffer.hasPending() || p.heard < journal.batches.length); round++) {
      if (round > 100) throw new Error(`seed ${seed}: the peers never settled`);
      for (const p of all) {
        while (p.heard < journal.batches.length) hear(p);
        send(p);
      }
    }
    return all.map((p) => ({ content: content(p), version: p.model.document.version }));
  } finally {
    for (const p of all) {
      p.buffer.destroy();
      p.model.destroy();
    }
  }
}

test.each(Array.from({ length: 30 }, (_, seed) => seed + 1))('peers converge on seed %i', (seed) => {
  const [first, ...others] = simulate(seed);
  for (const other of others) expect(other).toEqual(first);
});
