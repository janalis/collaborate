# Headless text sessions

`Janalis\Collaborate\Draft\HeadlessDraft` shares one UTF-8 text source between custom interfaces.
The host constructs the service with its `DraftAccess`, `DraftJournal`, collaborator provider and clock.
It must configure a `RecordDraftAccessCheckerInterface` that checks the current user's rights to the
signed record identity; the default role-only checker cannot authorize headless grants.

The host's authenticated endpoint resolves the resource, captures `$sourceReadStartedAt = microtime(true)`
before reading its persisted source, then calls
`open($user, $entityClass, $recordIdentity, $source, $sourceReadStartedAt)`. The timestamp defaults
to the current time when omitted. Passing the time captured before the storage read prevents a
concurrent save checkpoint from being mistaken for an external storage change. The result contains a compact `grant` and the
journal `state`. Initial source is never embedded in the grant. A live journal retains unsaved edits;
a journal recreated after purge starts from the host's current persisted source. Source is limited to
2 MiB. Reopen through this host endpoint after a missing-journal conflict; never initialize it from a
browser-supplied initial value.

If `state.conflicts.source` is present, storage has changed outside the shared draft. Show that
conflict to the user before persisting either version. Resolve it explicitly with
`reconcile($grant, $user, $generation, $revision, $persistedSource, $keep, $sourceReadStartedAt)`,
using a fresh server-side storage read and its start time. `keep: true` keeps the shared source as
unsaved against the new persisted baseline; `keep: false` replaces it with persisted source and
drops its provenance. Reopen adapters from the returned snapshot after replacement, preserving
any pending local work for recovery. Reconciliation rejects an outdated generation or revision.

Subsequent host endpoints call `replay($grant, $user, $since, $digest)` and
`append($grant, $user, $event)`. Every call verifies current record access. The text field is named
`source`; operations use the existing journal event protocol, including generation, revision and
model version. Appends enforce the resulting source-size limit atomically under the journal lock.
The normal form draft endpoints reject headless grants. Pass the previous state's `digest` to
replay so unchanged snapshots omit `fields` contents while still returning newly accepted events.
Omitting the optional digest returns the full snapshot, including source provenance.

The host endpoints must enforce authentication, CSRF, request-size bounds, rate limiting, and
subscription authorization. Publish revision notifications through the configured hub only after a
successful append. Do not publish the text itself. Hosts own persistence validation. After storing an immutable snapshot successfully, call
`checkpointIfCurrent($grant, $user, $generation, $revision, $source)` to mark only that exact snapshot
clean under the journal lock. If peers have advanced the draft, it returns false and leaves their
edits unsaved. A clean saved draft can compact at a later idle open, resetting its generation; clients
with pending local edits must retain their recovery source rather than silently adopting that reset. The service does not automatically
save a document, settle a journal, or grant corpus/publication rights.

## JavaScript text adapter

Import `SourceAdapter` from `@janalis/collaborate/draft/source-adapter.js` (or the corresponding
AssetMapper URL). It stores one canonical plain-text source independently of DOM controls:

```js
const text = new SourceAdapter(initialSource, () => scheduleAppend());
const stop = text.subscribe(() => render(text.value));
const revision = text.revision;
text.transact([
    {start: 0, end: 3, text: '<seg>'},
    {start: 7, end: 11, text: '</seg>'},
], revision);
```

`value` is the current optimistic text. `revision` is the local model revision, including pending
changes; it is distinct from the acknowledged journal version. A transaction addresses UTF-16
start-inclusive/end-exclusive offsets in that exact revision. The adapter validates every splice
before applying any mutation: offsets must be integral and in bounds, ranges must not overlap, and
neither offsets nor inserted strings may split or contain an unpaired surrogate. Two insertions at
the same offset must be combined by the caller. Splices are applied together as one journal batch,
regardless of the distances between them. An outdated revision throws; the host must recompute or
transform the intended edit against current state. Identical replacements are no-ops.

`subscribe(listener)` observes source changes and returns an unsubscribe function. A host should
suppress editor echo while applying adapter changes. Listener and scheduler errors propagate after
the adapter commits the change and invokes the remaining notifications; callers must not retry an
edit solely because an observer threw. `track(start, end)` returns a live range with
`start`, `end`, and `detach()`; its offsets follow local and transformed remote operations. Detach a
range when its selection or gesture ends. Revalidate structural intent after source changes: tracked
positions do not prove that a particular XML element still exists.

`undo()` and `redo()` share a local history across all interfaces attached to this adapter.
`canUndo` and `canRedo` expose availability. Each transaction is one undo step. Remote edits are not
added to local undo history. Undoing inserted text retains peer insertions within that text, and
undoing a deletion restores its surviving source position. These guarantees concern text operations,
not tree validity: concurrent edits and undo can leave XML structurally invalid. Validate the source
and suspend visual structural actions when the source cannot be parsed.

The adapter exposes `next()` / `receive()` scheduling with the journal, using source-provenance operations rather than native editor operations. The scheduler is called when local
operations need delivery. `next()` returns a bounded batch of pending operations without consuming them;
`receive(batch)` applies an ordered accepted batch or acknowledges the adapter's matching batch.
Use the journal event's field version for this protocol, not its global revision. On append conflict,
replay accepted batches before retrying the pending provenance operations with their updated base versions. `hasPending()` reports
unacknowledged operations. A scheduler must also handle network retry and the session's generation,
global revision, and field name (`source`). It must not interpret successful local rendering as a
server acknowledgement. The adapter belongs to a headless `source` field, not a native form `text` field. It does not
open a network connection or persist a document by itself.

Call `destroy()` when the interface closes. It detaches live ranges, removes listeners and model
resources, and rejects further transactions, tracking and subscriptions. Flush or retain pending work
through the session before destroying the adapter; disposal does not silently save it.

## Source provenance and undo

Headless fields use the `source` schema and `SourceAdapter`. Normal form text fields retain their
existing text-operation protocol. Source transactions insert immutable text runs anchored to run
codepoint positions, and delete intervals of those runs. Undo toggles the author's transaction;
it does not remove another author's insertion anchored inside that transaction. Overlapping deletion
masks remain effective until each author undoes their own deletion. Redo reactivates the same runs
and anchors. Insertion siblings are ordered by descending logical clock and ascending run identifier.
When a replacement is undone, a peer insertion anchored inside the replacement remains at that hidden
run's anchor, before the restored original text; the peer insertion is never deleted.

Snapshots expose `fields.source.initial`, `value`, and `sourceEvents`. Initialize the source adapter
from `{initial: field.initial, events: field.sourceEvents || []}`. The events already contain the full
provenance state: do not replay the snapshot's journal events on top of it. Native source offsets in
transactions are codepoints; public editor ranges are UTF-16. Actor metadata is assigned by the server;
a source toggle can only name that actor's transaction.

The server stores runs and deletion intervals rather than per-character objects. It limits a source
to 10,000 runs with iterative insertion traversal, in addition to the source-specific journal and request limits. Encoded source updates are bounded to 16 MiB and source journal history to 32 MiB; decoded source remains at most 2 MiB, including invalid XML drafts. Host request caps must accommodate this bounded JSON escaping overhead. Ordinary form limits are unchanged. These
limits fail atomically. A saved, idle journal can be compacted; that starts a new generation and drops
historical undo provenance, so existing sessions must reopen rather than replay old undo commands.

Database transport limits can impose a lower effective storage capacity than these protocol limits.
A field stores baseline, current and saved values plus provenance together; JSON escaping increases
the stored payload. The bundle does not change database server settings.

## Runnable example

The Host test application exposes `/admin/text/{record}` for the `alice` and `bob` test users.
The page contains source editing, wrap controls and shared undo. Its authenticated host endpoints
use CSRF-protected serialized journal requests; ordinary Host form drafts stay disabled.

Run its two-browser regression with:

```sh
make app-setup
npx playwright test tests/playwright/host-headless.spec.js --project=host
```

## Provenance and snapshots

The native form `TextAdapter` continues using CKEditor operations; it does not expose collaborative
undo. Headless `SourceAdapter` uses run identities and transaction-specific deletion marks. Initial
text is one implicit run. Insertions are runs anchored to a codepoint of another run; siblings order
by descending logical clock, then ascending run id. Undo hides only its insertion runs and removes
only its deletion marks. Descendant peer insertions remain visible. Redo restores the same anchors.
A peer insertion inside replaced text stays anchored to that inserted run when the replacement is
undone; it is not interpolated into the restored original text.

Initialize a reopened adapter from `{initial: field.initial, events: field.sourceEvents}` returned
by the server. This reconstructs the acknowledged source and its identities. Do not also replay the
same events as new changes. `snapshot()` returns acknowledged provenance only; pending local edits
remain outside that snapshot. Source operations use codepoint offsets internally, while the public
transaction API uses UTF-16. The server attaches actor identities and rejects toggling another
actor's transaction. Persist provenance together with the journal; flattening an active session to a
new initial string would invalidate outstanding anchors and undo targets. Compaction must retain
those identities or reset the session generation and explicitly reconcile every participant.
