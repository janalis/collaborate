import { MergeOperation, MoveOperation, NoOperation, transformOperationSets } from 'ckeditor5';

/** Optimistic client of an append-only, compare-and-swap operation journal. */
export class OperationBuffer {
  constructor(model, changed = () => {}) {
    this.model = model;
    this.changed = changed;
    this.version = model.document.version;
    this.pending = [];
    this.applying = false;
    this.batches = new WeakMap();
    this.remoteOperations = new WeakSet();
    this.capture = (_event, [operation]) => {
      if (this.remoteOperations.has(operation) || !operation.isDocumentOperation) return;
      const batch = operation.batch;
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
          result.operationsA[0] = new MoveOperation(
            local.graveyardPosition,
            1,
            accepted.graveyardPosition.getTransformedByOperation(local),
            result.operationsA[0].baseVersion
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
