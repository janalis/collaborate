# Internal contract

How the parts of the bundle talk to each other. Every name here is fixed: PHP, Twig and JS rely on it.

## PHP classes

| Class | Role |
| --- | --- |
| `CollaborateBundle` | Config tree; every option is a `collaborate.<path>` parameter. Aliases `CollaboratorProviderInterface`, `DraftAccessCheckerInterface`, `janalis_collaborate.grant_cache` and `janalis_collaborate.hub` (the `mercure.hub.<hub.name>` service). |
| `Identity\CollaboratorProviderInterface` | `id(UserInterface): ?int`, `label()`, `avatarUrl(): ?string`, `authorization(): list<string>`. |
| `Access\DraftAccessCheckerInterface` | `canEdit(UserInterface, 'create'\|'update', class-string): bool`. |
| `Grant::authorizationHash(list<string>)` | The one hash every grant carries (`rolesHash` field / third dot part). |
| `Hub\Topics` | `cursors($room)`, `draft($id)`, `updates()`, under `hub.topic_prefix`. |
| `Hub\HubPublisher` | `available()`, `defer(Update, string $failure)`, publishes on `kernel.terminate`. |
| `Cursor\PageGrant` | `issue(Request, int $userId, int $now, list $authorization)`, `topic($grant, $userId, $now, $authorization)`. |
| `Draft\DraftAccess` | `issue(Scope, $userId, $now, $authorization)`, `verify($grant, UserInterface, $now): SignedScope`, `draftId($class, $identity)`, `topic($id)`, `$enabled`, `$maxRequestBytes`. A scope's `entity` is the entity's FQCN; its `permission` is `create` or `update`. |
| `Hub\RenewSubscriber` | Puts back the flash messages a `X-Collaborate-Renew: 1` render read. |
| `Controller\HubController` | `POST <prefix>/cursors/session`, route `collaborate_session`: the page's one subscriber JWT. |
| `Controller\CursorController` | `POST <prefix>/cursors/events`, route `collaborate_cursor_events`. |
| `Controller\DraftController` | `POST <prefix>/collaborate/{action}`, route `collaborate_draft`, `action` ∈ join, replay, append, discard, reconcile. |
| `Twig\CollaborateExtension` | `collaborate_layer()`, `collaborate_presence()`, `collaborate_enabled()`, `collaborate_grant()`. |
| `Draft\DraftJournal`, `DraftText`, `DraftSave` | The journal (MariaDB/MySQL SQL), plain-text operations, the locked save. |
| `Form\CollaborateFormExtension` + `Form\FormContextInterface` | Field schema, member keys, the `data-collaborate` attribute; the context says which record the root form edits. |
| `Doctrine\ChangeCollector` | The `onFlush` half of the saved-view updates. |
| `EasyAdmin\*` | `EasyAdminFormContext`, `DraftRefreshSubscriber`, `SaveSubscriber`, `UpdatesSubscriber`. |
| `Gedmo\*` | The translatable field-key resolver and the translation-object mapping. |
| `Entity\Draft`, `DraftField`, `DraftEvent` | Tables `collaborate_draft`, `collaborate_draft_field`, `collaborate_draft_event`. |
| `Command\PurgeDraftsCommand` | `collaborate:purge`. |

Services: `config/services/{core,hub,cursors,drafts,easyadmin,gedmo}.php`, explicit definitions (no autowiring of the bundle's namespace). Routes: `config/routes.php`, imported by the application under its own prefix (e.g. `/{_locale}/admin`).

Request attributes start with `_collaborate_`. Headers: `X-CSRF-Token`, `X-Collaborate-Refresh: 1` (a form's action answers its fresh grant and tokens as JSON), `X-Collaborate-Renew: 1` (a background reload for a fresh page grant). CSRF token ids: `collaborate-hub` (session and cursor events) and `collaborate-draft`.

Translation domain `collaborate`; its keys are the English strings.

## Markup

The layer, rendered by `collaborate_layer()` when the user holds the role:

```html
<div data-collaborate-layer aria-hidden="true"
     data-grant="…" data-csrf="…"
     data-session-url="…" data-event-url="…"   <!-- event URL absent when cursors are disabled -->
     data-updates="true"                       <!-- present when updates are enabled -->
     data-config='{"throttle":100,"heartbeat":5000,"expiry":15000,"maxAvatars":5,"renew":240000,"colors":["blue",…]}'></div>
```

Presence, rendered by `collaborate_presence()`:

```html
<div class="collaborate-presence" data-collaborate-presence role="group" aria-label="Also on this page" hidden></div>
```

A shared form carries `data-collaborate` (JSON): `grant`, `url` (with an `__action__` placeholder), `csrf`, `messages`, `isNew`, `editUrl` (with `__record__`), `invitation` (new records), and `config`: `{"replayConnected":30000,"replayDisconnected":5000,"joinRetry":12000,"labelLength":255}`. Collection rows carry `data-collaborate-collection`.

CSS classes: `.collaborate-cursor*`, `.collaborate-caret*`, `.collaborate-presence*`, `.collaborate-bar*`, `.collaborate-conflicts*`; colours by `[data-color]`, field marks by `[data-peer-focus]` / `data-peer-focus-label`.

Storage keys: `collaborate-notice:<draft id>`, `collaborate-update:<path><search>`. Events: `collaborate:applied` (a received value written to a control).

## JS modules (`assets/`, AssetMapper namespace `@janalis/collaborate`)

| Module | Role |
| --- | --- |
| `hub.js` | One Mercure stream per page; members by `channel` (`cursors`, `updates`, `draft`). |
| `cursors/cursors.js`, `carets.js`, `geometry.js`, `presence.js` | Pointers, carets, field marks, header avatars. |
| `updates.js` | Saved-list and saved-detail refresh. |
| `draft/session.js` (`sessionFor`), `bar.js`, `collections.js`, `conflicts.js`, `fields.js`, `hooks.js` (`addRowInitializer`) | Shared drafts. |
| `ckeditor5/collaborate.js` (`Collaborate` plugin), `operation-buffer.js`, `source.js`, `text-change.js` | CKEditor integration. |
| `easyadmin.js` | Entry point: starts cursors, updates and drafts; re-exports `sessionFor` and `addRowInitializer`. |
| `scss/_cursors.scss`, `scss/_draft.scss` | Styles; `_cursors.scss` expects Bootstrap's `scss/functions` and `scss/variables` on the Sass load path. |

Bare imports the host's importmap provides: `ckeditor5`, `@microsoft/fetch-event-source`, `bootstrap/js/src/modal.js`.

A rich-text editor is created by the host. It passes `session.editorConfiguration(field)` into its configuration, calls `session.editorReady(name)` or `session.editorFailed()`, and names its Markdown mode radios `<field id>_view`. A host that builds editors for added collection rows registers them with `addRowInitializer(fn(row))`.
