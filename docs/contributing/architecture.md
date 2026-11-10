# Architecture

The bundle is a framework-level **core**, admin **bridges** (EasyAdmin's, in
the bundle; Drupal's, the `drupal/` module) and an optional **Gedmo**
adapter. The names that PHP, Twig and JavaScript share (routes,
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
| `docs/deploy/` | Copy-ready proxy, process-manager and platform files for the WebSocket hub. |

## Service files

| File | Loaded | Defines |
|---|---|---|
| `core.php` | always | The default identity and access services, `SymfonyCurrentUser` (aliased as `CurrentUserInterface`), `Topics`, `PageGrant`, `DraftAccess`. |
| `hub.php` | always | `HubPublisher`, `HubGuard`, `HubSessions`, `HubController`, `RenewSubscriber`, the Twig extension. |
| `hub_mercure.php` | with `hub.transport: mercure` (MercureBundle must be registered) | `MercureTransport`, on `mercure.hub.<hub.mercure.name>`; `HubTransportInterface` and `PresenceProbeInterface` aliased to it. |
| `hub_websocket.php` | with `hub.transport: websocket` | `Keys` (its secrets set by `CollaborateBundle`, not parameters), `RequestSigner`, `SubscriberToken`, `WebSocketTransport` (aliased as both interfaces), `HubServeCommand`. Nothing in it names a ReactPHP type. |
| `cursors.php` | always | `CursorController` (it answers 404 when cursors are disabled). |
| `drafts.php` | always | `DraftText`, `DraftJournal`, `DraftSave`, `FormDraft`, the record identifier (`RecordIdentifierInterface`), the field-key resolver, the form type extension, `DraftController`, `PurgeDraftsCommand`, and the saved-view collection and announcement (`ChangeCollector`, `UpdatesSubscriber`). |
| `bridge_easyadmin.php` | when `collaborate.bridge` resolves to `easyadmin` (`auto` with EasyAdminBundle registered) | `EasyAdminFormContext` (aliased as `FormContextInterface`), `DraftRefreshSubscriber`, `SaveSubscriber`, and the autocomplete's mapper for the form extension. |
| `gedmo.php` | when Gedmo's `Translatable` interface exists | `TranslatableFieldKeyResolver`, `TranslationMapper`. |

Everything is registered whatever the feature flags say; the services
read `collaborate.*.enabled` and stay inert when their feature is off.

## PHP

| Namespace | Role |
|---|---|
| `Identity\` | `CollaboratorProviderInterface` and its default, `SecurityCollaboratorProvider`. |
| `Access\` | `DraftAccessCheckerInterface` and its default, `RoleDraftAccessChecker`. |
| `Grant` | The authorization hash every grant carries. |
| `Hub\` | `Topics`, `Notice` (one or more topics and a JSON payload), `HubPublisher` (defers notices, publishes them on `kernel.terminate` in one transport call, pauses after a failure), `HubGuard` (the checks every hub request passes), `HubSessions` and `HubSession` (the topics, resends and refusals of a session request, whatever the transport), `RenewSubscriber` (keeps flash messages across a background renewal). |
| `Hub\Transport\` | `HubTransportInterface` (`publish(list<Notice>)`, `subscribe(HubSession, Request): Subscription`), `PresenceProbeInterface` (`listening($topic)`), `Subscription`, `PublishFailedException`. |
| `Hub\Transport\Mercure\` | `MercureTransport`: private updates, the subscriber JWT, the subscriptions API. |
| `Hub\Transport\WebSocket\` | Shared by the application and the server, with no ReactPHP type: `WebSocketTransport` (signed publish and presence requests, token minting), `Keys` (HKDF derivation and rotation), `SubscriberToken`, `TokenClaims`, `RequestSigner`, `Origin`. |
| `Hub\Server\` | The ReactPHP server, never a container service: `ServerConfig`, `ServerFactory`, `Server` (listeners, signals, shutdown, the shared timer), `BrowserEndpoint` (handshake and subscribe protocol), `PublishEndpoint` (`/publish`, `/subscriptions`, `/health`), `Router` (topic to connections), `Connection`, `ClientAddress`, `Sockets`, `Limits`, `Timings`. |
| `Cursor\` | `PageGrant` (rooms and page grants), `CursorEvent` and `CursorPosition` (validation of what clients send). |
| `Controller\` | `HubController` (session), `CursorController` (events), `DraftController` (draft actions). |
| `Draft\` | `DraftAccess` (field grants and their cache), `DraftJournal` (the journal: one class per protocol, MariaDB/MySQL SQL), `DraftText` (plain-text operations on the server), `DraftSave` (the locked save), field-key resolvers. |
| `Form\` | `CollaborateFormExtension` (the field schema of a Symfony form, stable collection keys, the save hooks) and `FormContextInterface`. |
| `Doctrine\` | `ChangeCollector` (the `onFlush` half of saved-view updates), `RecordIdentifier`, `TranslationMapperInterface`. |
| `Bridge\` | `Bridge`: the bridges, their default regions, how `collaborate.bridge` resolves. `Bridge\EasyAdmin\`: EasyAdmin's services. `EasyAdmin\` holds the deprecated names of before 0.5. |
| `Updates\` | `UpdatesSubscriber`: the response half of saved-view updates. |
| `Security\` | `CurrentUserInterface` (the user and their access decisions) and Symfony's, `SymfonyCurrentUser`. |
| `Gedmo\` | Per-locale field keys and the translation-to-record mapping. |
| `Entity\` | `Draft`, `DraftField`, `DraftEvent`: the mapping of the three tables. |
| `Command\` | `collaborate:purge`, `collaborate:push:setup`, `collaborate:hub:serve` (checks the server's packages, then builds and runs it). |
| `Twig\` | `collaborate_layer()`, `collaborate_presence()`, `collaborate_enabled()`, `collaborate_grant()`. |

## JavaScript

| Module | Role |
|---|---|
| `collaborate.js` | Entry point: starts cursors, updates and drafts; re-exports `sessionFor`, `addRowInitializer` and `registerBridge`. |
| `easyadmin.js`, `drupal.js` | Entry points of the bridges: their profile, then `collaborate.js`. |
| `bridge.js`, `bridges/*.js` | The bridges' script profiles (regions, rows and entries, the page shown, embedded lists, selection, overlays, collection and slug widgets, save buttons), and the active one: the layer's `bridge` when registered. |
| `hub.js` | One hub stream per page, whatever the transport; members join by `channel` (`cursors`, `updates`, `draft`, `notifications`); renewal (in place on a WebSocket), backoff with jitter, resend of unknown grant signatures, background renewal of the page grant. |
| `hub/mercure.js`, `hub/websocket.js` | The two sources `hub.js` loads on demand, after the session names its `transport`: a Mercure stream through `@microsoft/fetch-event-source`, or a WebSocket with its subscribe and acknowledgement messages. |
| `cursors/cursors.js`, `carets.js`, `geometry.js`, `presence.js`, `marks.js`, `turn.js` | Pointers, carets, field marks, header avatars or menu, markers, edge arrows, popup lines, the turn of a tab. |
| `cursors/shapes.js` | The platform cursor shapes, re-exported by `geometry.js`. |
| `updates.js` | Saved-list and saved-detail refresh. |
| `draft/session.js` | `DraftSession`: one ordered network queue per form, join, replay, save, discard, conflicts, grant renewal; `sessionFor()`. |
| `draft/fields.js`, `collections.js`, `conflicts.js`, `bar.js`, `hooks.js` | Field adapters, collection rows, the conflict review, the state bar and dialogs, `addRowInitializer()`. |
| `ckeditor5/collaborate.js` | The `Collaborate` CKEditor plugin. |
| `ckeditor5/operation-buffer.js`, `source.js`, `text-change.js` | Operation batching and transformation, Markdown source reconciliation, text diffs. |
| `scss/_layer.scss`, `_cursors.scss`, `_draft.scss`, `_notifications.scss` | Styles; `_cursors.scss` is the Bootstrap 5 entry. |
| `scss/_easyadmin.scss`, `_drupal.scss` | The bridges' styles: EasyAdmin's header; Drupal's bar, dialog and tokens (built into `drupal/css/collaborate.css`). |

## Drupal module

`drupal/` is its own Composer package, `janalis/collaborate_drupal`, left
out of the bundle's archive. It loads the bundle's own extension into
Drupal's container (`CollaborateServiceProvider`, with `Options` from
`collaborate.settings` and `settings.php`), then `DrupalWiringPass` points
the bundle's references at Drupal's services and the module's adapters
(`Security\`, `DrupalTranslator`, `Storage\Connections`: DBAL with
Drupal's table prefix, an entity manager for the notifications), renames
the kernel tags, and inlines the parameters holding a `%`, which Drupal's
dumper would escape once per level. `Bridge\EntityForms` shares content
entity forms through `Draft\FormDraft`, the framework-free half of the
form extension. `tools/drupal-assets.mjs` builds its stylesheet and its
copy of `@microsoft/fetch-event-source`.

Every module that talks to the server captures `window.fetch` when it is
evaluated, so background requests bypass the host's fetch wrappers; that is
why the entry point must load before them.

## A shared edit, end to end

1. The page renders: the Twig extension issues the page grant into the
   layer; the form extension describes the root form's fields, issues the
   field grant and renders `data-collaborate`.
2. `hub.js` posts the page grant and the field grants to the session
   endpoint and opens one stream for the returned topics with the minted
   token, over the `transport` the answer names.
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

A compaction (on Join) or a purge ends a generation, and a page replaying a
draft of another generation reloads only if it loses nothing. A compaction
leaves one event row, batch id `rebase`, holding the list of the generations
compactions replaced. A replay from a revision before it answers that list as
`rebasedFrom` instead of a batch, and a page of one of those generations
reloads when it has nothing unsent, since all it sent is saved. A draft recreated after a purge lists
none of them, so only a page showing exactly the saved draft reloads.
