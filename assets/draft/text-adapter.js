// @ts-check
import { Model, ModelLivePosition } from 'ckeditor5';
import { OperationBuffer } from '../ckeditor5/operation-buffer.js';

/** @typedef {{start: number, end: number, text: string}} TextSplice */

/**
 * Shared plain text independent of any DOM control. Offsets are UTF-16; transactions address
 * the current local revision, not the acknowledged journal version. One transaction is one batch.
 */
export class TextAdapter extends OperationBuffer {
  /** @param {string} [initial] @param {() => void} [schedule] */
  constructor(initial = '', schedule = () => {}) {
    const model = new Model();
    const root = model.document.createRoot();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    model.change((writer) => {
      writer.append(writer.createElement('paragraph'), root);
      if (initial) writer.insertText(initial, /** @type {import('ckeditor5').ModelElement} */ (root.getChild(0)), 0);
    });
    super(model, schedule);
    this.paragraph = /** @type {import('ckeditor5').ModelElement} */ (root.getChild(0));
    this.disposed = false;
    /** @type {Set<(adapter: TextAdapter) => void>} */
    this.listeners = new Set();
    /** @type {Set<ReturnType<TextAdapter['track']>>} */
    this.ranges = new Set();
    this.notify = () => {
      for (const listener of this.listeners) listener(this);
    };
    model.document.on('change:data', this.notify);
  }

  get value() {
    return Array.from(
      this.paragraph.getChildren(),
      (node) => /** @type {import('ckeditor5').ModelText} */ (node).data
    ).join('');
  }

  get revision() {
    return this.model.document.version;
  }

  /** @param {(adapter: TextAdapter) => void} listener */
  subscribe(listener) {
    this.assertActive();
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /**
   * Validate every splice before mutation. Ranges must not overlap; equal insertion offsets are
   * rejected so ordering never depends on sort stability. Boundaries cannot split a surrogate pair.
   * @param {TextSplice[]} splices @param {number} [revision]
   */
  transact(splices, revision = this.revision) {
    this.assertActive();
    if (revision !== this.revision) throw new Error('Collaborate stale text revision.');
    const source = this.value;
    const ordered = [...splices].sort((a, b) => a.start - b.start);
    let previous = null;
    for (const splice of ordered) {
      const { start, end, text } = splice;
      if (
        !Number.isInteger(start) ||
        !Number.isInteger(end) ||
        start < 0 ||
        end < start ||
        end > source.length ||
        typeof text !== 'string' ||
        !text.isWellFormed() ||
        !this.boundary(source, start) ||
        !this.boundary(source, end) ||
        (previous && (start < previous.end || start === previous.start))
      )
        throw new RangeError('Invalid Collaborate text splice.');
      previous = splice;
    }
    this.model.change((writer) => {
      for (const { start, end, text } of ordered.reverse()) {
        if (source.slice(start, end) === text) continue;
        if (end > start)
          writer.remove(
            writer.createRange(
              writer.createPositionAt(this.paragraph, start),
              writer.createPositionAt(this.paragraph, end)
            )
          );
        if (text) writer.insertText(text, this.paragraph, start);
      }
    });
  }

  /** A live range follows local and transformed remote operations until detached. */
  /** @param {number} start @param {number} end */
  track(start, end) {
    this.assertActive();
    const source = this.value;
    if (
      !Number.isInteger(start) ||
      !Number.isInteger(end) ||
      start < 0 ||
      end < start ||
      end > source.length ||
      !this.boundary(source, start) ||
      !this.boundary(source, end)
    )
      throw new RangeError('Invalid Collaborate text range.');
    const from = ModelLivePosition.fromPosition(this.model.createPositionAt(this.paragraph, start));
    const to = ModelLivePosition.fromPosition(this.model.createPositionAt(this.paragraph, end));
    const range = {
      get start() {
        return from.offset;
      },
      get end() {
        return to.offset;
      },
      detach: () => {
        from.detach();
        to.detach();
        this.ranges.delete(range);
      },
    };
    this.ranges.add(range);
    return range;
  }

  /** @param {string} source @param {number} offset */
  boundary(source, offset) {
    return !(
      offset > 0 &&
      offset < source.length &&
      /[\uD800-\uDBFF]/.test(source[offset - 1]) &&
      /[\uDC00-\uDFFF]/.test(source[offset])
    );
  }

  assertActive() {
    if (this.disposed) throw new Error('Collaborate text adapter disposed.');
  }

  destroy() {
    if (this.disposed) return;
    this.disposed = true;
    for (const range of this.ranges) range.detach();
    this.listeners.clear();
    this.model.document.off('change:data', this.notify);
    super.destroy();
    this.model.destroy();
  }
}
