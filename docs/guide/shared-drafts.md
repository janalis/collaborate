# Shared drafts

A shared draft is the common, durable state of one record's form while
people edit it. This page describes what editors see and what the bundle
guarantees.

## Which forms are shared

A form is shared when all of these hold:

- [`drafts.enabled`](./configuration#drafts) is on;
- it is the root form of a page the [form context](./without-easyadmin#the-form-context)
  describes (with EasyAdmin: a CRUD edit or new page), built on the very
  record the context names;
- the user is a collaborator holding the [role](./configuration#top-level),
  and `DraftAccessCheckerInterface::canEdit()` allows `update` (edit page)
  or `create` (new page) on the record's class;
- its field grant fits in [`drafts.max_request_bytes`](./configuration#drafts)
  beside one update.

The draft of an edit page belongs to its record: everyone editing that
record shares it. The draft of a new-record page belongs to that page:
independent new forms have distinct drafts, and the bar's **Copy invitation
link** button puts the page's URL with its `draft` key on the clipboard so
another editor can join it (where the clipboard refuses, the bar shows the
link selected for copying).

Shared fields are the mapped, enabled fields of the form: text controls,
rich text, choices, checkboxes, radios, dates and numbers, and collections
(entries added, removed and reordered, and their own fields). Password,
file, hidden, button and unmapped fields, and names in
[`drafts.sensitive_fields`](./configuration#drafts), never enter the draft.
Text controls share the line breaks they show (LF).

## The state bar

Above each shared form, a bar shows the draft's state in its own color and
icon. A visually hidden live region announces each state once, except the
synchronization every send passes through.

The bar's actions follow its state, and an action a state does not offer is
hidden. While the draft holds changes the record does not (unsaved,
synchronizing, interrupted, changed meanwhile), the bar offers **Save**
first, then **Discard changes**. A saved draft offers neither, so an editor
working alone never sees the button that erases changes as the bar's only
action.

| State | Color | Meaning |
|---|---|---|
| Connecting | grey | The page is joining the draft; the form's controls are inert until the draft and its editors are ready. |
| Saved | green | "All changes saved." Every change in the draft is saved in the record. After a peer's Save: "All changes saved by …". |
| Unsaved | blue | "Unsaved changes, kept automatically. Click Save to record them." The draft holds changes the record does not; they survive a reload, and Save records them. |
| Synchronizing | blue | The page is sending its edits. |
| Interrupted | amber | The network or the server failed (no answer, 429 or 5xx). Edits stay on the page and are sent when the connection returns; Save can be repeated. |
| Changed meanwhile | amber | The server refused an edit made on an older state (409); the page reads the draft again. |
| In conflict | amber | The record was saved outside this draft; see [Conflicts](#conflicts-with-the-saved-record). |
| Reset | amber | Another editor discarded the draft (or it was purged) while this page held unsent or unsaved changes, or it was compacted while this page held unsent ones. Local content stays; copy it before reloading. |
| Created | green | This new-record draft created its record (here or on a peer's page); an **Open saved record** button leads to it. |
| Refused | red | The server refused an action for another reason; copy unsaved content and reload. |
| Full | red | The draft reached a [limit](#limits-and-compaction); synchronization and Save pause. |
| Access expired | red | The user's grant was refused and could not be renewed. |
| Incompatible | red | This page's editor cannot apply the draft's operations (another CKEditor version or build); see [Schema compatibility](#schema-compatibility). |
| Unavailable | grey | The draft could not be joined or an editor failed to mount: the form falls back to ordinary editing, and its save counts as an outside change for other drafts. |

When a page reloads on its own (after its own Discard, after choosing the
saved record, or because a peer's Discard or a compaction reset what it
showed), it shows a dismissible notice once saying why, kept for one load
in `sessionStorage` under `collaborate-notice:<draft id>`.

## Editing together

- Edits are sent in batches 150 ms after a change. Each accepted batch is
  stored in the journal and announced on the hub; peers then read it back
  from the server. Notifications never contain content.
- Text and rich text merge by operational transformation: each field is a
  CKEditor model (one paragraph for a plain text field), and concurrent
  operations are transformed against each other in every browser, so
  everyone converges on the same text. Text typed inside a span a peer
  deleted is deleted with it everywhere. Undo and redo stay local and
  native.
- Other values (choices, checkboxes, dates…) are last-writer-wins per
  field, in journal order. A value typed but not yet sent survives a peer's
  update until it is sent.
- Collection entries have stable identities: adding, removing and
  reordering rows on one page does the same on the others, keeps the
  focused row in place and renumbers hidden `position` children in the
  order shown.
- While the hub stream is up, a page also replays every
  [`drafts.replay.connected`](./configuration#drafts-replay) seconds (30) to
  cover a lost publication; while it is down, every
  `drafts.replay.disconnected` seconds (5). A hub outage loses nothing that
  was committed; a network outage keeps unsent edits on the open page. Unsent
  edits do not survive closing or reloading the page.
- Leaving a page through the back/forward cache suspends it; coming back
  renews its subscription, refreshes its grant and saved baseline and sends
  what was kept.

## Saving

Save submits the form through the application's normal path (EasyAdmin's,
or your controller's), with the draft's generation and revision. The form's
own buttons and the bar's **Save** take the same path: the bar's Save
submits the form with the button that keeps the editor on the page. That is
a submit button marked `data-collaborate-submitter` if there is one, else
EasyAdmin's `saveAndContinue` button, else its `saveAndReturn` button, else
the form's first submit button.
Buttons outside the form that name it with `form="…"` count.
Saving goes as follows:

1. The page sends its pending edits, then renews its field grant and, on an
   edit page, compares the draft with the saved record again (the
   [refresh request](./without-easyadmin#refresh-requests)).
2. The server takes the draft's named lock, waiting up to
   [`drafts.locks.save`](./configuration#drafts-locks) seconds (10) for
   another Save, and checks that the draft is at the revision the page
   sent.
3. Validation and persistence run as usual. Once the record is persisted
   and the response is ready, the draft records the saved values and who
   saved them, the lock is released, and peers hear that the draft is saved.

If a peer's edit landed between the page's last read and the server's
check, or another Save still holds the lock, the server redirects back to
the form with a warning: the edits are already in the draft, and Save can
be repeated. A validation error keeps the draft and the submitted values.

A new-record draft creates its record once: the first successful Save
creates it, and every page of that draft then shows **Open saved record**
while keeping its local content.

A Save that is posted stops sending further edits from that page, so they
cannot be mistaken for a peer's; a page restored from the browser cache
sends them.

## Discarding

**Discard changes** asks for confirmation (Cancel is focused). The dialog
warns that the unsaved changes are erased, the editor's own and those of
everyone editing the record, and that Save keeps them instead. It is offered
only in the states that hold changes it can succeed in discarding
(unsaved, synchronizing, interrupted, changed, in conflict, full).

Discard resets the fields of its form that hold edits, or that a **Keep
shared draft** kept against the saved record, to the values the draft last
saved. The pages showing one of those fields learn who reset it: a page
showing exactly the saved draft reloads on its own, a page with unsent or
unsaved changes keeps its local content in the *Reset* state. An edit sent
before its page heard of the Discard is refused.

Discard never reads the record: when it was saved outside the draft since,
the fields Discard resets can still differ from it, and the
[review](#conflicts-with-the-saved-record) stays open until **Use saved
record** takes the saved values.

## Conflicts with the saved record

When the record is saved outside the draft (by another form, a script, an
import, a save without JavaScript), open edit pages hear of it on their
draft's topic and compare again, once a second at most. Differences appear
under **Differences with the saved record**:

- each conflicting field is named by its label (a collection entry's after
  its collection's) and shows both versions as read: a choice by its
  option labels, a checkbox as Yes or No, rich text without markup, a
  collection entry as present or removed, a collection as reordered;
- the words the draft removed and added are marked (up to 2,000 words a
  field);
- **Keep shared draft** keeps the fields that have draft edits and takes
  the saved value for the other conflicting fields, so Save does not revert
  the outside write; **Use saved record** (confirmed in its own dialog)
  replaces the draft's conflicting changes by the saved values for
  everyone.

Outside writes are never silently merged into fields someone is editing. A
Save blocked by conflicts brings the review into view and focus. A
collection whose entries have no
[`position_field`](./configuration#drafts) stores no order, so its order
never counts as a difference.

## Limits and compaction

| Limit | Default | When reached |
|---|---|---|
| One update | 256 KiB (`max_update_bytes`), in stored UTF-8 bytes | The update is refused (413) and the draft shows *Full*. |
| Nesting of a value | 8 levels (`max_value_depth`) | The update is refused. |
| Journal of one draft | 10 MiB (`max_draft_bytes`) | The draft is *Full*: synchronization and Save pause; copy unsent content before discarding. |
| Field states | 10,000 (`max_fields`) | New collection entries are refused until the draft is saved and a later Join compacts it, dropping removed entries. |

A Discard deletes the journal of the fields it resets and keeps their
states; **compaction** empties the whole journal. When a page
joins a draft that is over [`drafts.compact.bytes`](./configuration#drafts-compact)
(1 MiB), or over `drafts.compact.fields` (5,000) field states some of which
belong to removed collection entries, and every field is saved and nobody
has written to it for `drafts.compact.idle` seconds (60), the saved values
become the new baseline, removed entries are dropped, the journal empties
and the generation advances. Every edit an open page sent is then in the
saved values, so a page with nothing unsent reloads on its own, even one
that had not heard of the last Save yet; one with unsent edits keeps them
and asks to copy them before reloading.

## Purge

`collaborate:purge` (run it daily, see
[Installation](./installation#_4-schedule-the-purge)) deletes drafts nobody
wrote to for `drafts.purge.clean_days` (7) that hold no unsaved change or
have created their record, and any draft idle for
`drafts.purge.unsaved_days` (90). A draft whose lock is held is skipped.

A draft starts at a random generation, so one recreated after a purge never
matches what a page still open on the purged draft holds: that page reloads
on its own when it showed exactly the saved draft, and otherwise stops and
keeps its content, asking to copy it before reloading.

## Schema compatibility

Every text and rich-text field records the schema of the editor that
produced its operations: `ckeditor5-<version>:collaborate-2:<text|rich>:<full|basic>`.
CKEditor operations recorded by one release are not replayed into another,
so:

- **Upgrading CKEditor is a schema change.** A field whose changes are all
  saved adopts the new schema on the next Join and starts its journal again
  from the saved value. A field with unsaved operations refuses the new
  editor: its form falls back to ordinary editing until the draft is saved
  or discarded from a page still on the old version.
- **Two editor builds never share a draft field**: name the option that
  selects the reduced build in
  [`drafts.rich_text.schema_option`](./configuration#drafts-rich-text).
- A corrupt or incompatible model pauses synchronization and Save and keeps
  the page readable, so unsent content can be copied.

Deploy a CKEditor upgrade like a schema migration: preferably when drafts
are saved, and expect open pages on the old version to stop following
their drafts.

## Translated fields

With `gedmo/doctrine-extensions` installed, a field mapped to a property
that has Gedmo's `#[Translatable]` attribute keeps one draft state per
locale (the request's locale), so pages of two locales share the record's
draft without sharing that field. New records are created in the kernel's
default locale. A saved Gedmo translation row (an `AbstractTranslation`
entity) counts as a change of the record it translates.
