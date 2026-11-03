# Changelog

All notable changes to `janalis/collaborate`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `cursors.places`: a `PlaceProviderInterface` names a page's room and is
  asked again at every hub request, so a place the user lost is refused
  before the grant expires.
- Host options describing pages other than EasyAdmin's: `regions`,
  `key_attribute` and `key_scope` (a record placed in whatever element shows
  it), `anchor_attributes`, `anchor_classes`, `caret_regions`,
  `ignored_parameters`.
- `cursors.popups`: the popup of a record a peer has open, and a status
  under its title saying who else has it open.
- `markers`, `edges`, `idle`, `top_layer`, `presence: menu` (find a peer,
  hide pointers), `distinct_colors`, `silent_alone` and `share_stream`.
- `enter(grant)`, `cursorsFor()` and the `collaborate:enter` event: a page
  that replaces its content enters another place without loading.
- `_layer.scss`, the cursor styles as a module that needs no framework;
  `_cursors.scss` loads it.
- A tab sends less often past three peers.

### Fixed

- A page that sent every edit reloads on its own when a compaction replaces
  its generation before it heard of the last Save, instead of stopping in
  *Reset*: compactions record the generations they replaced, and views answer
  them as `rebasedFrom`.
- Packages leave out the tests, documentation sources and tooling.
- On MySQL, which returns JSON objects with their keys sorted, a saved value
  holding an object no longer reads as changed: Save no longer refuses it as
  changed outside the draft, Join reports no conflict for it, a Save records
  no needless batch and Replay recognises the page's digest. MySQL also runs
  in CI.
- The journal's size counts the batches as stored, with their revision and
  author, on MariaDB and MySQL alike, so a draft can no longer pass
  `max_draft_bytes` and a checkpoint finds the size the updates added up to.
- Field states are written with `REPLACE`, not MySQL's deprecated `VALUES()`.
- Join builds the page's view once it has released the draft: decoding a
  long journal no longer holds off the draft's appends past their wait (503).
- `DraftJournal::saved()` and `created()` take the draft's lock unless their
  caller holds it.
- Saved-view refresh follows saves again once a refused page enters another
  place (`collaborate:enter`), and keeps the view of the address the page has
  when it refreshes, after a `history.pushState`.
- Cursors stopped on a layer start again on it, and leave it as they found it.
- A draft session whose form a host removed stops: its replays, listeners and
  hub membership no longer outlive the form, which starts a new session if
  it comes back.

### Upgrading

- `collaborate_draft` has an index on `updated_at`, which the purge reads:
  generate a migration (`doctrine:migrations:diff`) or add
  `CREATE INDEX collaborate_draft_updated_at ON collaborate_draft (updated_at)`.

### Documentation

- Discard resets fields to the values the draft last saved and never reads
  the record; the review of a record saved elsewhere stays open until **Use
  saved record**.
- Pages without EasyAdmin render each form row inside a `form-group`
  element, which field marks outline.

## [0.1.0] - 2026-11-02

### Added

- Shared drafts of Symfony forms: editors of the same record, or of the same
  new-record page through its invitation link, share one durable draft of
  its text, rich-text, choice and collection fields. A journal on MariaDB or
  MySQL orders every change under a per-draft named lock; pages replay it
  over CSRF-protected requests that recheck the user's signed field grant.
- A state bar above each shared form (saved, unsaved, synchronizing,
  interrupted, changed, in conflict, reset, created, refused, full, expired,
  incompatible, unavailable), Discard with confirmation, an invitation link
  for new records, and a review of the differences with a record saved
  elsewhere, where editors keep the draft or use the saved record.
- Save through the form's own validation and persistence, holding the
  draft's lock from its revision check to the response; a save overtaken by
  a peer's edit or by another save returns to the form with a warning.
- A CKEditor 5 plugin, `Collaborate`, that shares the editor's model
  operations and lets CKEditor transform concurrent ones, undo included, and
  keeps a Markdown source mode in step; `sessionFor()`, `editorConfiguration()`,
  `editorReady()`, `editorFailed()` and `addRowInitializer()` for the host's
  editors and collection widgets.
- Shared cursors and presence on any page with the collaboration layer:
  peers' pointers with their platform's shapes, carets and selections in
  text fields and rich text, the field each peer is in, and header avatars
  with a `+N` counter.
- Saved-list and saved-detail refresh after writes elsewhere, limited to the
  pages whose entity the write can alter.
- One Mercure subscription per page for all of the above, with a subscriber
  JWT minted for the topics the page's signed grants admit, renewed in the
  background.
- An EasyAdmin adapter: edit and new CRUD pages share drafts, saves go
  through EasyAdmin's persistence, and index and detail pages refresh.
- Per-locale draft fields for Gedmo translatable properties.
- `collaborate:purge`, which deletes idle drafts.
- Extension points: `CollaboratorProviderInterface` (who a user is),
  `DraftAccessCheckerInterface` (what they may edit) and
  `FormContextInterface` (which record a page edits).
- English and French translations.

[Unreleased]: https://github.com/janalis/collaborate/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/janalis/collaborate/releases/tag/v0.1.0
