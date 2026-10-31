# Changelog

All notable changes to `janalis/collaborate`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/janalis/collaborate/commits/main
