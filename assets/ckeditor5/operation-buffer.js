// @ts-check
import { MergeOperation, MoveOperation, NoOperation, transformOperationSets } from 'ckeditor5';

/**
 * @typedef {import('ckeditor5').Model} Model
 * @typedef {import('ckeditor5').Operation} Operation
 * @typedef {import('ckeditor5').Batch} Batch
 */

/**
 * Local operations journaled together, under the id their acknowledgement carries.
 *
 * @typedef {{ id: string, operations: Operation[], sent: boolean }} Group
 */

/**
 * A batch of the journal: the operations, as JSON, applied from `version` on.
 *
 * @typedef {{ id: string, version: number, operations: unknown[] }} JournalBatch
 */

/**
 * What an editor gone held unsent: its groups, as JSON, made from `version` on.
 *
 * @typedef {{ version: number, groups: { id: string, operations: unknown[] }[] }} Held
 */

/** Optimistic client of an append-only, compare-and-swap operation journal. */
export class OperationBuffer {
  /**
   * @param {Model} model
   * @param {() => void} [changed]
   */
  constructor(model, changed = () => {}) {
    this.model = model;
    this.changed = changed;
    /** @type {((text: string) => void) | undefined} What its owner does of a saved text the draft adopts. */
    this.adopt = undefined;
    this.version = model.document.version;
    /** @type {Group[]} */
    this.pending = [];
    this.applying = false;
    /** @type {WeakMap<Batch, Group>} */
    this.batches = new WeakMap();
    /** @type {WeakSet<Operation>} */
    this.remoteOperations = new WeakSet();
    /** @type {Group | null} the group an editor gone held, taken over whole: its operations go to it */
    this.holding = null;
    /**
     * @param {unknown} _event
     * @param {[Operation]} args
     */
    this.capture = (_event, [operation]) => {
      if (this.remoteOperations.has(operation) || !operation.isDocumentOperation) return;
      if (this.holding) {
        this.holding.operations.push(operation);
        return;
      }
      // Applied operations belong to a batch.
      const batch = /** @type {Batch} */ (operation.batch);
      let group = this.batches.get(batch);
      // Reused native batches can interleave; extend only the latest journal
      // group so its operations remain contiguous in document version order.
      // Keep merges isolated: their removed-node position must survive a
      // matching merge from another client, including merges produced by undo.
      if (
        !group ||
        this.pending.at(-1) !== group ||
        group.sent ||
        operation instanceof MergeOperation ||
        group.operations[0] instanceof MergeOperation
      ) {
        group = { id: crypto.randomUUID(), operations: [], sent: false };
        this.batches.set(batch, group);
        this.pending.push(group);
      }
      group.operations.push(operation);
    };
    this.change = () => {
      if (!this.applying && this.pending.length) changed();
    };
    model.on('applyOperation', this.capture, { priority: 'lowest' });
    model.document.on('change', this.change);
  }

  /** Unlike next(), marks no group as sent. */
  hasPending() {
    return this.pending.length > 0;
  }

  next() {
    const group = this.pending[0];
    if (!group) return null;
    group.sent = true;
    return {
      id: group.id,
      version: this.version,
      operations: group.operations.map((operation) => operation.toJSON()),
    };
  }

  /** @returns {Held} what this editor holds unsent, for the field's next editor should this one go */
  held() {
    return {
      version: this.version,
      groups: this.pending.map((group) => ({
        id: group.id,
        operations: group.operations.map((operation) => operation.toJSON()),
      })),
    };
  }

  /**
   * Takes over what an editor gone held, made on this editor's version: its edits applied as this editor's own,
   * each group whole under its id, so the draft's acknowledgement, or another's edit to rebase them on, meets them
   * as any of its own.
   *
   * @param {Held} held
   */
  hold(held) {
    for (const { id, operations } of held.groups) {
      const group = { id, operations: [], sent: false };
      this.pending.push(group);
      this.holding = group;
      try {
        this.model.change((writer) => {
          for (const json of operations) {
            // Made on the version this editor is on: its document's own, built as that one was.
            const operation = this.model.createOperationFromJSON(/** @type {any} */ (json));
            writer.batch.addOperation(operation);
            this.model.applyOperation(operation);
          }
        });
      } finally {
        this.holding = null;
      }
    }
  }

  /** @param {JournalBatch} batch */
  receive(batch) {
    if (batch.version < this.version) return;
    if (batch.version !== this.version) throw new Error('Collaborate operation gap.');
    const own = this.pending[0];
    if (own?.id === batch.id) {
      if (own.operations.length !== batch.operations.length) throw new Error('Collaborate acknowledgement mismatch.');
      this.pending.shift();
    } else {
      let remote = batch.operations.map((json) => this.model.createOperationFromJSON(json));
      for (const group of this.pending) {
        const result = transformOperationSets(remote, group.operations, {
          document: this.model.document,
          padWithNoOps: true,
          useRelations: true,
        });
        const acceptedChanges = remote.filter((operation) => !(operation instanceof NoOperation));
        const localChanges = group.operations.filter((operation) => !(operation instanceof NoOperation));
        const accepted = acceptedChanges.length === 1 ? acceptedChanges[0] : null;
        const local = localChanges.length === 1 ? localChanges[0] : null;
        if (
          accepted instanceof MergeOperation &&
          local instanceof MergeOperation &&
          accepted.sourcePosition.isEqual(local.sourcePosition) &&
          accepted.targetPosition.isEqual(local.targetPosition) &&
          !accepted.graveyardPosition.isEqual(local.graveyardPosition) &&
          result.operationsA.every((operation) => operation instanceof NoOperation) &&
          result.operationsB.every((operation) => operation instanceof NoOperation)
        ) {
          // CKEditor collapses matching merges to no-ops. Their empty element
          // can still occupy different graveyard positions; later undo uses
          // those positions. Align it with the accepted journal before replay.
          result.operationsA[0] = /** @type {any} */ (
            new MoveOperation(
              local.graveyardPosition,
              1,
              accepted.graveyardPosition.getTransformedByOperation(local),
              result.operationsA[0].baseVersion
            )
          );
        }
        remote = result.operationsA;
        group.operations = result.operationsB;
        group.sent = false;
      }
      this.applying = true;
      try {
        this.model.enqueueChange({ isLocal: false, isUndoable: false }, (writer) => {
          for (const operation of remote) {
            writer.batch.addOperation(operation);
            this.remoteOperations.add(operation);
            this.model.applyOperation(operation);
          }
        });
      } finally {
        this.applying = false;
      }
    }
    this.version += batch.operations.length;
    if (this.pending.length) this.changed();
  }

  destroy() {
    this.model.off('applyOperation', this.capture);
    this.model.document.off('change', this.change);
  }
}
