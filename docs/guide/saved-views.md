# Saved-view refresh

When a write is saved, pages showing what it changed hear of it and catch
up. It is on when [`updates.enabled`](./configuration#updates) is, which
follows `drafts.enabled` unless set.

## What is announced

A Doctrine listener (`onFlush`) collects what a write changes, for requests
whose path matches [`updates.path_pattern`](./configuration#updates)
(`#^/admin(?:/|$)#`) or whose route is listed in `updates.routes`. Once the
response is ready, and only for a successful one, the bundle publishes:

- on the saved-views topic (`<prefix>updates`), the **entities** whose views
  the write can alter: the changed classes, their parents and subclasses,
  and the classes up to [`updates.association_depth`](./configuration#updates)
  (2) associations away in either direction. A changed Gedmo translation
  counts as a change of the record it translates; a change limited to
  `updates.ignored_fields` (`updatedAt`) announces nothing. Entities are
  named by their short class name.
- on each changed record's draft topic, a notice that open edit pages of
  that record compare their draft with the saved record again (see
  [Conflicts](./shared-drafts#conflicts-with-the-saved-record)). A record
  changes when its own row, one of its translations, an entry of its
  collections or the other side of one of its many-to-many associations
  changes. Notices are grouped [`updates.chunk`](./configuration#updates)
  (100) drafts at a time.
- on the saved draft's own topic, when the write was a shared draft's Save,
  that it is saved.

Notices name classes and drafts, never content.

## What refreshes

The refresh reads EasyAdmin's markup, so it applies to EasyAdmin pages:

- An **index or detail** page whose entity (EasyAdmin's `ea-index-<Entity>`
  or `ea-detail-<Entity>` body class) is among the announced entities
  reloads, keeping its URL, scroll position, ticked batch checkboxes and the
  focused row.
- A **form page** does not reload: it only refreshes lists embedded in it
  (`.field-embedded-list[data-src]` elements). Its own record is followed
  through its draft.

A page refreshes at most once a second, counted from the first notice, so a
stream of saves cannot postpone it forever. It waits while a refresh would
disrupt someone: a hidden tab, an open modal, offcanvas or dropdown, a
focused control other than a row checkbox, or search and filter text typed
and not submitted. A navigation the user started is never cancelled; if the
page stays (a download), the refresh runs on the next interaction.

The hub keeps no history, so a page whose stream was lost refreshes once
the stream comes back, as if a save had concerned it. The first opening of
the stream refreshes nothing, however many attempts it took.
