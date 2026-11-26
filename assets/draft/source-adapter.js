// @ts-check

/** @typedef {{run: string, offset: number}} Anchor */
/** @typedef {{id: string, after: Anchor|null, text: string}} SourceInsert */
/** @typedef {{run: string, start: number, end: number}} SourceDelete */
/** @typedef {{__className: 'SourceTransaction', id: string, clock: number, inserts: SourceInsert[], deletes: SourceDelete[], actor?: number}} Transaction */
/** @typedef {{__className: 'SourceToggle', id: string, clock: number, target: string, active: boolean, actor?: number}} Toggle */
/** @typedef {Transaction|Toggle} SourceEvent */
/** @typedef {{initial: string, events: SourceEvent[]}} Snapshot */
/** @typedef {{id:string, text:string, transaction:string|null, clock:number}} Run */
/** @typedef {{run:string, start:number, end:number, text:string, visible:boolean, at:number}} Span */

/**
 * Source sessions retain insertion and deletion provenance, so undo cannot erase unseen peer text
 * or resurrect a peer's overlapping deletion. The journal orders events; run identities survive it.
 */
export class SourceAdapter {
  /** @param {string|Snapshot} [source] @param {()=>void} [schedule] */
  constructor(source = '', schedule = () => {}) {
    this.initial = typeof source === 'string' ? source : source.initial;
    if (!this.initial.isWellFormed() || new TextEncoder().encode(this.initial).length > 2_097_152)
      throw new RangeError('Invalid Collaborate initial source.');
    /** @type {SourceEvent[]} */
    this.events = typeof source === 'string' ? [] : [...source.events];
    /** @type {SourceEvent[]} */
    this.pending = [];
    this.schedule = schedule;
    this.version = this.events.length;
    this.revision = 0;
    this.clock = this.events.reduce((clock, event) => Math.max(clock, event.clock), 0);
    this.disposed = false;
    /** @type {{text:string,spans:Span[]}|null} */
    this.projection = null;
    /** @type {Set<(source: SourceAdapter)=>void>} */
    this.listeners = new Set();
    /** @type {Set<ReturnType<SourceAdapter['track']>>} */
    this.ranges = new Set();
    /** @type {string[]} */
    this.undoStack = [];
    /** @type {string[]} */
    this.redoStack = [];
  }

  get value() {
    return this.layout().text;
  }
  get canUndo() {
    return this.undoStack.length > 0;
  }
  get canRedo() {
    return this.redoStack.length > 0;
  }
  hasPending() {
    return this.pending.length > 0;
  }
  snapshot() {
    return { initial: this.initial, events: [...this.events] };
  }

  /** @param {(source:SourceAdapter)=>void} listener */
  subscribe(listener) {
    this.assertActive();
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /** @param {boolean} schedule */
  changed(schedule) {
    this.revision++;
    const errors = [];
    for (const listener of [...this.listeners]) {
      try {
        listener(this);
      } catch (error) {
        errors.push(error);
      }
    }
    if (schedule) {
      try {
        this.schedule();
      } catch (error) {
        errors.push(error);
      }
    }
    if (errors.length) throw errors[0];
  }
  assertActive() {
    if (this.disposed) throw new Error('Collaborate source adapter disposed.');
  }

  /** @param {SourceEvent} event */
  enqueue(event) {
    this.pending.push(event);
    this.projection = null;
    try {
      if (new TextEncoder().encode(this.value).length > 2_097_152)
        throw new RangeError('Collaborate source exceeds 2 MiB.');
    } catch (error) {
      this.pending.pop();
      this.projection = null;
      throw error;
    }
    this.clock = event.clock;
  }

  next() {
    this.assertActive();
    if (!this.pending.length) return null;
    /** @type {(SourceEvent & {baseVersion:number})[]} */
    const operations = [];
    let bytes = 0;
    for (const event of this.pending.slice(0, 64)) {
      const operation = { ...event, baseVersion: this.version + operations.length };
      const size = new TextEncoder().encode(JSON.stringify(operation)).length;
      if (operations.length && bytes + size > 262_144) break;
      operations.push(operation);
      bytes += size;
    }
    return { id: this.pending[0].id, version: this.version, operations };
  }

  /** @param {{id:string,version:number,operations:SourceEvent[]}} batch */
  receive(batch) {
    this.assertActive();
    if (batch.version < this.version) return;
    if (batch.version !== this.version) throw new Error('Collaborate source operation gap.');
    this.projection = null;
    for (const event of batch.operations) {
      this.clock = Math.max(this.clock, event.clock);
      this.pending = this.pending.filter((own) => own.id !== event.id);
      if (!this.events.some((known) => known.id === event.id)) this.events.push(event);
    }
    this.version += batch.operations.length;
    this.changed(this.pending.length > 0);
  }

  /** @param {import('./text-adapter.js').TextSplice[]} splices @param {number} [revision] */
  transact(splices, revision = this.revision) {
    this.assertActive();
    if (revision !== this.revision) throw new Error('Collaborate stale source revision.');
    this.applySplices(splices, this.layout());
  }

  /** @internal @param {import('./text-adapter.js').TextSplice[]} splices @param {{text:string,spans:Span[]}} projection */
  applySplices(splices, projection) {
    this.assertActive();
    const text = projection.text;
    const ordered = [...splices].sort((a, b) => a.start - b.start);
    let previous = null;
    for (const splice of ordered) {
      const { start, end, text: inserted } = splice;
      this.checkRange(start, end, text);
      if (
        typeof inserted !== 'string' ||
        !inserted.isWellFormed() ||
        (previous && (start < previous.end || start === previous.start))
      )
        throw new RangeError('Invalid Collaborate source splice.');
      previous = splice;
    }
    const id = crypto.randomUUID();
    /** @type {Transaction} */
    const event = { __className: 'SourceTransaction', id, clock: this.clock + 1, inserts: [], deletes: [] };
    for (const splice of ordered) {
      if (text.slice(splice.start, splice.end) === splice.text) continue;
      /** @type {Anchor|null} */
      let after = null;
      for (const span of projection.spans) {
        if (!span.visible) continue;
        const spanEnd = span.at + span.text.length;
        if (span.at < splice.start) {
          after = {
            run: span.run,
            offset: span.start + this.codepoints(span.text.slice(0, Math.min(splice.start, spanEnd) - span.at)) - 1,
          };
        }
        const start = Math.max(splice.start, span.at);
        const end = Math.min(splice.end, spanEnd);
        if (start < end) {
          const from = span.start + this.codepoints(span.text.slice(0, start - span.at));
          const to = from + this.codepoints(span.text.slice(start - span.at, end - span.at));
          const last = event.deletes.at(-1);
          if (last?.run === span.run && last.end === from) last.end = to;
          else event.deletes.push({ run: span.run, start: from, end: to });
        }
      }
      if (splice.text) event.inserts.push({ id: `${id}:${event.inserts.length}`, after, text: splice.text });
    }
    if (!event.inserts.length && !event.deletes.length) return;
    const redo = [...this.redoStack];
    this.undoStack.push(id);
    this.redoStack.length = 0;
    try {
      this.enqueue(event);
    } catch (error) {
      this.undoStack.pop();
      this.redoStack = redo;
      throw error;
    }
    this.changed(true);
  }

  undo() {
    this.toggle(this.undoStack, this.redoStack, false);
  }
  redo() {
    this.toggle(this.redoStack, this.undoStack, true);
  }
  /** @param {string[]} from @param {string[]} to @param {boolean} active */
  toggle(from, to, active) {
    this.assertActive();
    const target = from.pop();
    if (!target) return;
    to.push(target);
    try {
      this.enqueue({ __className: 'SourceToggle', id: crypto.randomUUID(), clock: this.clock + 1, target, active });
    } catch (error) {
      to.pop();
      from.push(target);
      throw error;
    }
    this.changed(true);
  }

  /** @param {number} start @param {number} end @param {string} text */
  checkRange(start, end, text) {
    const boundary = (/** @type {number} */ offset) =>
      !(
        offset > 0 &&
        offset < text.length &&
        /[\uD800-\uDBFF]/.test(text[offset - 1]) &&
        /[\uDC00-\uDFFF]/.test(text[offset])
      );
    if (
      !Number.isInteger(start) ||
      !Number.isInteger(end) ||
      start < 0 ||
      end < start ||
      end > text.length ||
      !boundary(start) ||
      !boundary(end)
    )
      throw new RangeError('Invalid Collaborate source range.');
  }

  /**
   * Immutable visible provenance for composition or another deferred editing gesture. Transactions
   * delete only tokens visible at capture time, preserving peer insertions received in the meantime.
   */
  capture() {
    this.assertActive();
    const projection = this.layout();
    return {
      value: projection.text,
      /** @param {import('./text-adapter.js').TextSplice[]} splices */
      transact: (splices) => this.applySplices(splices, projection),
      /** @param {number} start @param {number} end */
      track: (start, end) => this.trackProjection(start, end, projection),
    };
  }

  /** @param {number} start @param {number} end */
  track(start, end) {
    return this.capture().track(start, end);
  }

  /** @internal @param {number} start @param {number} end @param {{text:string,spans:Span[]}} projection */
  trackProjection(start, end, projection) {
    this.assertActive();
    this.checkRange(start, end, projection.text);
    const a = this.anchor(start, projection);
    const b = this.anchor(end, projection);
    const source = this;
    const range = {
      get start() {
        return source.position(a);
      },
      get end() {
        return source.position(b);
      },
      detach: () => {
        this.ranges.delete(range);
      },
    };
    this.ranges.add(range);
    return range;
  }

  /** @param {number} at @param {{text:string,spans:Span[]}} [projection] @returns {Anchor|null} */
  anchor(at, projection = this.layout()) {
    for (const span of projection.spans) {
      if (span.visible && span.at <= at && at < span.at + span.text.length)
        return { run: span.run, offset: span.start + this.codepoints(span.text.slice(0, at - span.at)) };
    }
    return null;
  }

  /** @param {Anchor|null} anchor */
  position(anchor) {
    for (const span of this.layout().spans) {
      if (anchor?.run === span.run && span.start <= anchor.offset && anchor.offset < span.end) {
        return span.at + (span.visible ? this.slice(span.text, 0, anchor.offset - span.start).length : 0);
      }
    }
    return this.value.length;
  }

  /** @param {string} text */
  codepoints(text) {
    return /[\uD800-\uDBFF]/.test(text) ? Array.from(text).length : text.length;
  }

  /** @param {string} text @param {number} start @param {number} end */
  slice(text, start, end) {
    return /[\uD800-\uDBFF]/.test(text) ? Array.from(text).slice(start, end).join('') : text.slice(start, end);
  }

  /** Build run spans, never one object per source character. */
  layout() {
    if (this.projection) return this.projection;
    const events = [...this.events, ...this.pending];
    /** @type {Map<string,Toggle>} */
    const toggles = new Map();
    for (const event of events)
      if (event.__className === 'SourceToggle') {
        const previous = toggles.get(event.target);
        if (!previous || event.clock > previous.clock || (event.clock === previous.clock && event.id > previous.id))
          toggles.set(event.target, event);
      }
    /** @type {Map<string,Run[]>} */
    const children = new Map();
    /** @type {Map<string,SourceDelete[]>} */
    const deleted = new Map();
    for (const event of events) {
      if (event.__className !== 'SourceTransaction') continue;
      for (const insert of event.inserts) {
        const key = insert.after ? `${insert.after.run}/${insert.after.offset}` : '';
        const siblings = children.get(key) || [];
        siblings.push({ id: insert.id, text: insert.text, transaction: event.id, clock: event.clock });
        children.set(key, siblings);
      }
      if (toggles.get(event.id)?.active === false) continue;
      for (const removal of event.deletes) {
        const ranges = deleted.get(removal.run) || [];
        ranges.push(removal);
        deleted.set(removal.run, ranges);
      }
    }
    if ([...children.values()].reduce((count, runs) => count + runs.length, 0) > 10_000)
      throw new RangeError('Collaborate source run limit reached.');
    for (const siblings of children.values())
      siblings.sort((a, b) => b.clock - a.clock || Number(a.id > b.id) - Number(a.id < b.id));
    /** @type {Span[]} */
    const spans = [];
    let at = 0;
    /** @type {Map<string, number[]>} */
    const anchors = new Map();
    for (const key of children.keys()) {
      const slash = key.lastIndexOf('/');
      if (slash < 0) continue;
      const run = key.slice(0, slash);
      const positions = anchors.get(run) || [];
      positions.push(Number(key.slice(slash + 1)) + 1);
      anchors.set(run, positions);
    }
    /** @typedef {{kind:'run',run:Run}|{kind:'span',span:Omit<Span,'at'>}} Job */
    /** @type {Job[]} */
    const stack = [{ kind: 'run', run: { id: 'initial', text: this.initial, transaction: null, clock: 0 } }];
    for (const run of [...(children.get('') || [])].reverse()) stack.push({ kind: 'run', run });
    const visited = new Set();
    while (stack.length) {
      const job = /** @type {Job} */ (stack.pop());
      if (job.kind === 'span') {
        spans.push({ ...job.span, at });
        if (job.span.visible) at += job.span.text.length;
        continue;
      }
      const run = job.run;
      if (visited.has(run.id)) throw new RangeError('Collaborate source anchor cycle.');
      visited.add(run.id);
      // Index a Unicode run once, rather than expanding the whole run for every span.
      const units = /[\uD800-\uDBFF]/.test(run.text) ? Array.from(run.text) : run.text;
      const length = units.length;
      const removals = deleted.get(run.id) || [];
      const points = new Set([0, length, ...(anchors.get(run.id) || [])]);
      for (const removal of removals) {
        points.add(removal.start);
        points.add(removal.end);
      }
      const boundaries = [...points].sort((a, b) => a - b);
      /** @type {Job[]} */
      const jobs = [];
      for (let i = 1; i < boundaries.length; i++) {
        const start = boundaries[i - 1];
        const end = boundaries[i];
        const visible =
          (!run.transaction || toggles.get(run.transaction)?.active !== false) &&
          !removals.some((range) => range.start <= start && start < range.end);
        const piece = units.slice(start, end);
        const text = typeof piece === 'string' ? piece : piece.join('');
        jobs.push({ kind: 'span', span: { run: run.id, start, end, text, visible } });
        for (const child of children.get(`${run.id}/${end - 1}`) || []) jobs.push({ kind: 'run', run: child });
      }
      for (let i = jobs.length - 1; i >= 0; --i) stack.push(jobs[i]);
    }
    this.projection = {
      spans,
      text: spans
        .filter((span) => span.visible)
        .map((span) => span.text)
        .join(''),
    };
    return this.projection;
  }

  destroy() {
    this.disposed = true;
    this.projection = null;
    this.listeners.clear();
    this.ranges.clear();
    this.pending.length = 0;
    this.undoStack.length = 0;
    this.redoStack.length = 0;
  }
}
