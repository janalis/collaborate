# Architecture

The bundle is a framework-level **core** and two optional **adapters**,
EasyAdmin and Gedmo. The names that PHP, Twig and JavaScript share (routes,
attributes, headers, CSRF ids, storage keys, events) are listed in the
[internal contract](https://github.com/janalis/collaborate/blob/main/docs/internals/contract.md);
keep it current when you change one.

## Layout

| Path | Contents |
|---|---|
| `src/CollaborateBundle.php` | The configuration tree, the container parameters (`collaborate.<path>`), the service aliases and the prepended Doctrine, Twig and AssetMapper configuration. |
| `config/services/*.php` | Explicit service definitions, no autowiring of the bundle's namespace. |
| `config/routes.php` | The three routes, imported by the application under its own prefix. |
| `src/` | PHP, by concern (below). |
| `templates/` | The layer and presence markup (`@JanalisCollaborate`). |
| `translations/` | The `collaborate` domain, English and French; keys are the English strings. |
| `assets/` | Native ES modules and Sass partials, served by AssetMapper as `@janalis/collaborate`. |
| `docs/schema.sql` | Reference DDL of the three tables. |

## Service files

| File | Loaded | Defines |
|---|---|---|
| `core.php` | always | The default identity and access services, `Topics`, `PageGrant`, `DraftAccess`. |
| `hub.php` | always | `HubPublisher`, `HubGuard`, `HubController`, `RenewSubscriber`, the Twig extension. |
| `cursors.php` | always | `CursorController` (it answers 404 when cursors are disabled). |
| `drafts.php` | always | `DraftText`, `DraftJournal`, `DraftSave`, the field-key resolver, the form type extension, `DraftController`, `PurgeDraftsCommand`, and the saved-view collection and announcement (`ChangeCollector`, `UpdatesSubscriber`). |
| `easyadmin.php` | when EasyAdminBundle is registered | `EasyAdminFormContext` (aliased as `FormContextInterface`), `DraftRefreshSubscriber`, `SaveSubscriber`. |
| `gedmo.php` | when Gedmo's `Translatable` interface exists | `TranslatableFieldKeyResolver`, `TranslationMapper`. |

Everything is registered whatever the feature flags say; the services
read `collaborate.*.enabled` and stay inert when their feature is off.

## PHP

| Namespace | Role |
|---|---|
| `Identity\` | `CollaboratorProviderInterface` and its default, `SecurityCollaboratorProvider`. |
| `Access\` | `DraftAccessCheckerInterface` and its default, `RoleDraftAccessChecker`. |
| `Grant` | The authorization hash every grant carries. |
| `Hub\` | `Topics`, `HubPublisher` (publishes on `kernel.terminate`, pauses after a failure), `HubGuard` (the checks every hub request passes), `RenewSubscriber` (keeps flash messages across a background renewal). |
| `Cursor\` | `PageGrant` (rooms and page grants), `CursorEvent` and `CursorPosition` (validation of what clients send). |
| `Controller\` | `HubController` (session), `CursorController` (events), `DraftController` (draft actions). |
| `Draft\` | `DraftAccess` (field grants and their cache), `DraftJournal` (the journal: one class per protocol, MariaDB/MySQL SQL), `DraftText` (plain-text operations on the server), `DraftSave` (the locked save), field-key resolvers. |
| `Form\` | `CollaborateFormExtension` (field schema, stable collection keys, the `data-collaborate` attribute, the save hooks) and `FormContextInterface`. |
| `Doctrine\` | `ChangeCollector` (the `onFlush` half of saved-view updates), `RecordIdentifier`, `TranslationMapperInterface`. |
| `EasyAdmin\` | The adapter, and `UpdatesSubscriber` (the response half of saved-view updates). |
| `Gedmo\` | Per-locale field keys and the translation-to-record mapping. |
| `Entity\` | `Draft`, `DraftField`, `DraftEvent`: the mapping of the three tables. |
| `Command\` | `collaborate:purge`. |
| `Twig\` | `collaborate_layer()`, `collaborate_presence()`, `collaborate_enabled()`, `collaborate_grant()`. |

## JavaScript

| Module | Role |
|---|---|
| `easyadmin.js` | Entry point: starts cursors, updates and drafts; re-exports `sessionFor` and `addRowInitializer`. |
| `hub.js` | One Mercure stream per page; members join by `channel` (`cursors`, `updates`, `draft`); renewal, resend of unknown grant signatures, background renewal of the page grant. |
| `cursors/cursors.js`, `carets.js`, `geometry.js`, `presence.js` | Pointers, carets, field marks, header avatars, and the platform cursor shapes. |
| `updates.js` | Saved-list and saved-detail refresh. |
| `draft/session.js` | `DraftSession`: one ordered network queue per form, join, replay, save, discard, conflicts, grant renewal; `sessionFor()`. |
| `draft/fields.js`, `collections.js`, `conflicts.js`, `bar.js`, `hooks.js` | Field adapters, collection rows, the conflict review, the state bar and dialogs, `addRowInitializer()`. |
| `ckeditor5/collaborate.js` | The `Collaborate` CKEditor plugin. |
| `ckeditor5/operation-buffer.js`, `source.js`, `text-change.js` | Operation batching and transformation, Markdown source reconciliation, text diffs. |
| `scss/_cursors.scss`, `scss/_draft.scss` | Styles. |

Every module that talks to the server captures `window.fetch` when it is
evaluated, so background requests bypass the host's fetch wrappers; that is
why the entry point must load before them.

## A shared edit, end to end

1. The page renders: the Twig extension issues the page grant into the
   layer; the form extension describes the root form's fields, issues the
   field grant and renders `data-collaborate`.
2. `hub.js` posts the page grant and the field grants to the session
   endpoint and opens one stream for the returned topics with the minted
   JWT.
3. `DraftSession` joins the draft (`collaborate/join`): the journal creates
   or reads it under its named lock and answers the field states and events.
4. An edit becomes CKEditor model operations (or a value), batched and sent
   with `collaborate/append`; the journal checks it against the grant's
   fields and the limits, stores it and answers its revision; the
   controller defers a notice to the draft topic.
5. Peers receive the notice, replay from their revision and transform the
   operations into their models.
6. Save posts the form with the generation and revision; `DraftSave` locks
   and checks, the application persists, `DraftSave::completed()` and
   `finish()` checkpoint the draft and release the lock; `ChangeCollector`
   and `UpdatesSubscriber` announce what changed.
