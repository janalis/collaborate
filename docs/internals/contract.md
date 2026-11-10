# Internal contract

How the parts of the bundle talk to each other. Every name here is fixed: PHP, Twig and JS rely on it.

## PHP classes

| Class | Role |
| --- | --- |
| `CollaborateBundle` | Config tree; every option is a `collaborate.<path>` parameter, but `hub.websocket.secret` and `previous_secret` (set as `Keys`'s arguments); `collaborate.hub.name` holds `hub.mercure.name`. Aliases `CollaboratorProviderInterface`, `DraftAccessCheckerInterface`, `janalis_collaborate.grant_cache`, and, by `hub.transport`, loads `hub_mercure.php` (alias `janalis_collaborate.mercure_hub` to `mercure.hub.<hub.mercure.name>`; MercureBundle must be registered) or `hub_websocket.php`. |
| `Hub\Transport\HubTransportInterface`, `PresenceProbeInterface` | Aliased to the transport: `MercureTransport` or `WebSocketTransport`. `publish(non-empty-list<Notice>)` throws `PublishFailedException` naming the first notice not published; `subscribe(HubSession, Request): Subscription` (`transport`, `url`, `token`); `listening(string $topic): bool`. |
| `Identity\CollaboratorProviderInterface` | `id(UserInterface): ?int`, `label()`, `avatarUrl(): ?string`, `authorization(): list<string>`. |
| `Access\DraftAccessCheckerInterface` | `canEdit(UserInterface, 'create'\|'update', class-string): bool`. |
| `Grant::authorizationHash(list<string>)` | The one hash every grant carries (`rolesHash` field / third dot part). |
| `Hub\Topics` | `cursors($room)`, `draft($id)`, `updates()`, under `hub.topic_prefix`. |
| `Hub\Notice` | `new Notice(string\|list<string> $topics, string $data)`: one notice to the subscribers of any of its topics (each once); `data` is JSON. Always private. |
| `Hub\HubPublisher` | `available()`, `defer(Notice, string $failure)`, publishes on `kernel.terminate`, every deferred notice in one `HubTransportInterface::publish()` call. |
| `Hub\HubSessions` | `open(Admission): HubSession` (`userId`, `topics`, `resend`, `denied`, and `cursorRoom` with its `CursorIdentity` for a page in a cursor room with cursors on). |
| `Cursor\PageGrant` | `issue(Request, int $userId, int $now, list $authorization): ?string` (null: a page in no place), `topic($grant, $userId, $now, $authorization)`, `verify(…): array{topic, place}`. Without a place provider a grant is `user.expiry.authorization.room.signature`; with one, `user.expiry.authorization.room.place.signature` (`place`: base64url JSON). |
| `Cursor\PlaceProviderInterface` | `place(Request): ?array`, `canEnter(UserInterface, array $place): bool`; aliased `janalis_collaborate.places` from `cursors.places`. `Hub\HubGuard` asks `canEnter()` at every request. |
| `Cursor\CursorEvent`, `CursorPosition` | Validate an event against the configured regions, popup kinds (`popup: {kind, key}`, only when `cursors.popups` names some) and caret regions. |
| `Draft\DraftAccess` | `issue(Scope, $userId, $now, $authorization)`, `verify($grant, UserInterface, $now): SignedScope`, `draftId($class, $identity)`, `topic($id)`, `$enabled`, `$maxRequestBytes`. A scope's `entity` is the entity's FQCN; its `permission` is `create` or `update`. |
| `Hub\RenewSubscriber` | Puts back the flash messages a `X-Collaborate-Renew: 1` render read. |
| `Controller\HubController` | `POST <prefix>/cursors/session`, route `collaborate_session`: the page's one subscription, JSON `{transport, url, topics, token, userId, resend, denied, cursorEvents}`; `transport` is `mercure` or `websocket`; `cursorEvents`, whether the page sends its cursor events over its WebSocket. |
| `Controller\CursorController` | `POST <prefix>/cursors/events`, route `collaborate_cursor_events`. |
| `Controller\DraftController` | `POST <prefix>/collaborate/{action}`, route `collaborate_draft`, `action` ∈ join, replay, append, discard, reconcile, report. |
| `Twig\CollaborateExtension` | `collaborate_layer()`, `collaborate_presence()`, `collaborate_enabled()`, `collaborate_grant()`. |
| `Draft\DraftJournal`, `DraftText`, `DraftSave`, `FormDraft` | The journal (MariaDB/MySQL SQL), plain-text operations, the locked save, a form's draft whatever builds the form; a host without a bridge calls `DraftSave::completed($record)` once it has persisted the record. |
| `Form\CollaborateFormExtension` + `Form\FormContextInterface` | Field schema, member keys, the `data-collaborate` attribute; the context says which record the root form edits. |
| `Doctrine\ChangeCollector` | The `onFlush` half of the saved-view updates. |
| `Bridge\EasyAdmin\*` | `EasyAdminFormContext`, `DraftRefreshSubscriber`, `SaveSubscriber` (loaded with the `easyadmin` bridge). `Updates\UpdatesSubscriber`, the response half of the saved-view updates, is loaded always, from `drafts.php`. |
| `Gedmo\*` | The translatable field-key resolver and the translation-object mapping. |
| `Entity\Draft`, `DraftField`, `DraftEvent` | Tables `collaborate_draft`, `collaborate_draft_field`, `collaborate_draft_event`. |
| `Command\PurgeDraftsCommand` | `collaborate:purge`. |
| `Command\HubServeCommand` | `collaborate:hub:serve` (`--listen`, `--publish-listen`, `--allow-remote-publish`, `--shutdown-timeout`, `--force`), with `hub.transport: websocket`. |

Services: `config/services/{core,hub,hub_mercure,hub_websocket,cursors,drafts,bridge_easyadmin,gedmo}.php` (and the notification files), explicit definitions (no autowiring of the bundle's namespace). Routes: `config/routes.php`, imported by the application under its own prefix (e.g. `/{_locale}/admin`).

Request attributes start with `_collaborate_`; a bridge records a save's changes in `_collaborate_data_changed` (class names, or Drupal's entity types) and `_collaborate_records_changed` (draft ids). Headers: `X-CSRF-Token`, `X-Collaborate-Refresh: 1` (a form's action answers its fresh grant and tokens as JSON: EasyAdmin's `DraftRefreshSubscriber`, or the host's controller), `X-Collaborate-Renew: 1` (a background reload for a fresh page grant). CSRF token ids: `collaborate-hub` (session and cursor events) and `collaborate-draft`.

Translation domain `collaborate`; its keys are the English strings.

## WebSocket hub

Browser side, on `hub.websocket.path` (the path of `public_url` by default): a `GET` upgrade with an `Origin`, no body. Text frames, JSON:

- client → server, `{"hub":"subscribe","id":<int>,"token":"<token>"}`: the first within 10 s, at most 10 a minute. A later one (a renewal) replaces the connection's topics, expiry and cursor room at once; its `sub` must be the first one's.
- client → server, `{"hub":"cursor","event":{…}}` (the body of `POST <prefix>/cursors/events` without `grant`), only when the session answered `cursorEvents: true`: no answer. The server validates it as `CursorEvent` does, stamps it with the token's `cur` identity and its own time (`CursorEvents::notice()`, the controller's shape), and relays it to the token's cursor room alone, the sender included. Ignored before a subscription, without a `cur` claim, invalid, over `cursors.max_event_bytes`, or past twice the client's `cursors.throttle` rate; an `event` that is no object closes the socket (1008).
- no other message: anything else closes the socket (1008).
- server → client: `{"hub":"subscribed","id":<int>}` or `{"hub":"error","id":<int>,"code":1008|4401}` (a refused renewal leaves the socket open; a refused first subscribe closes it with that code: 1008 when the user has too many connections, 4401 otherwise); notices as the notice's `data`, verbatim. A top-level `hub` key marks the server's own messages.
- close codes: 4401 (token refused or expired, no subscription in time; the page asks for a new session at once), 1008 (protocol violation; the page waits 30 s), 1009 (message too big), 1001 (shutdown); a slow or silent client is dropped without a close frame (1006).
- handshake refusals: 404 (path), 405 (method), 400 (a body), 403 (`Origin` missing, `null`, or outside `allowed_origins`), 429 (per-IP cap), 503 (`max_connections`, `max_pending`, shutting down).

Subscriber token: `base64url(claims).base64url(HMAC-SHA256(subscribe key, claims part))`, claims `{v:1, aud, origin, sub, topics, iat, exp, cur?}` (`aud`: `public_url` as written; `origin`: normalized, the session request's scheme and host; `cur`: `{room, label, color, avatar}`, the page's cursor room, one of `topics`, and who the user is there, when cursors and `hub.websocket.cursor_events` are on and the token stays within 8 KiB). Keys: `hash_hkdf('sha256', secret, 32, 'collaborate-hub-subscribe' | 'collaborate-hub-publish')`, each of `secret` and `previous_secret`.

Private side, on `publish_listen`; every request but `/health` carries `X-Collaborate-Signature: t=<unix time>,n=<32 hex nonce>,s=<hex HMAC-SHA256(publish key, "<t>\n<n>\n<METHOD>\n<path?query>\n<hex sha256(body)>")>`, within ±60 s, its nonce never seen before (401 otherwise):

- `POST /publish`, `Content-Type: application/json`, `{"notices":[{"topics":[…],"data":"<JSON>"}]}`, 1 to 100 notices, topics under `topic_prefix`, `data` JSON without a top-level `hub` key → 204 (400 malformed, 413 over 1 MiB, 411 chunked).
- `GET /subscriptions?topic=<topic>` → `{"subscriptions":[{"active":true}]}` when a subscribed connection with a valid token holds the topic, `{"subscriptions":[]}` otherwise; it names nobody.
- `GET /health` → 200 `ok`, unsigned.

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

A shared form carries `data-collaborate` (JSON): `grant`, `url` (with an `__action__` placeholder), `csrf`, `messages`, `isNew`, `editUrl` (with `__record__`), `invitation` (new records), and `config`: `{"replayConnected":30000,"replayDisconnected":5000,"joinRetry":12000,"labelLength":255}`. The rows of the collections in the field schema carry `data-collaborate-collection`; a collection the schema leaves out (unmapped, disabled, sensitive, or under such a form) has none, and its rows stay as rendered. Through the attributes every stock form theme renders, a shared form also marks the row of each field below it, prototypes included, with `data-collaborate-row` (`row_attr`); the widget of each such collection with `data-collaborate-entries` (its path) and `data-collaborate-placeholder` (its `prototype_name`) (`attr`); and each of its entries, the prototype included, with `data-collaborate-entry` (`row_attr`). The scripts read these first, then the bridge's own markup: EasyAdmin's `.form-group`, `.field-collection-item`, `.ea-form-collection-items` and a `data-prototype` / `data-form-type-name-placeholder` on the collection's row; Drupal's `.js-form-item` and multi-value table rows.

The layer's `data-config` always names the resolved `bridge` (`none`, `easyadmin`, `drupal`), whose script profile reads the page. A page says what it shows with `data-collaborate-page="<entity>:<index|detail|edit|new>"` on its body (the entity may be left out); the saved-view refresh compares the entity with the short class names (Drupal: entity types) a save announces. A host's save button may carry `data-collaborate-submitter`: the bar's Save stands for it.

The layer's `data-config` also carries, only when they differ from their defaults, the host options (`regions`, `keyAttribute`, `keyScope`, `anchorAttributes`, `anchorClasses`, `caretRegions`, `popups` (`{kind: {where, line}}`, translated), `idle` (ms), `markers`, `edges`, `topLayer`, `presence`, `distinctColors`, `silentAlone`, `shareStream`) and then `messages` (`showPointers`, `idle`, `on`, `here`, `is`, `line`, `where`). With `top_layer` the layer is `popover="manual"`; it carries `data-hidden` (pointers hidden by the reader) and `data-labels="moving"` (with `idle`).

Host markup the scripts read: the key attribute (`cursors.key_attribute`) on records, `data-collaborate-label` (a record's name in the presence menu), `data-collaborate-popup="<kind>"` beside the key attribute, `[data-collaborate-popup-presence]` (where the popup's status goes; the bundle marks the line it writes `data-collaborate-popup-line`).

CSS classes: `.collaborate-cursor*`, `.collaborate-caret*`, `.collaborate-presence*`, `.collaborate-marker*`, `.collaborate-edge`, `.collaborate-popup-presence*`, `.collaborate-pulse`, `.collaborate-announce`, `.collaborate-bar*`, `.collaborate-conflicts*`; colours by `[data-color]`, field marks by `[data-peer-focus]` / `data-peer-focus-label`.

Storage keys: `collaborate-notice:<draft id>`, `collaborate-update:<path><search>`, `collaborate.pointers` (`shown`/`hidden`). Events: `collaborate:applied` (a received value written to a control), `collaborate:enter` (`detail.grant`: enter another place). Web Lock: `collaborate-cursors` (`share_stream`).

## JS modules (`assets/`, AssetMapper namespace `@janalis/collaborate`)

| Module | Role |
| --- | --- |
| `hub.js` | One hub stream per page; members by `channel` (`cursors`, `updates`, `draft`, `notifications`). Loads `hub/<transport>.js` as the session names it. |
| `hub/mercure.js` (`mercureSource`), `hub/websocket.js` (`websocketSource`, `websocketUrl`) | `source(url, {signal, token, topics, fetch, onopen(handle?), onmessage({data})}) → Promise`: resolves on abort, rejects on failure (a WebSocket's with the close `code`). The WebSocket handle's `renew(token)` subscribes again on the same socket. |
| `markup.js` | The form markup the scripts read, whatever the form theme: a field's row, a collection's entries, its prototype and placeholder. |
| `cursors/cursors.js` (`initCursors`, `cursorsFor`), `carets.js`, `geometry.js` (`configureGeometry`), `presence.js`, `marks.js`, `turn.js`, `shapes.js` | Pointers, carets, field marks, header avatars or menu, markers, edge arrows, popup lines, the turn of a tab. |
| `updates.js` | Saved-list and saved-detail refresh. |
| `draft/session.js` (`sessionFor`), `bar.js`, `collections.js`, `conflicts.js`, `fields.js`, `hooks.js` (`addRowInitializer`) | Shared drafts. |
| `ckeditor5/collaborate.js` (`Collaborate` plugin), `operation-buffer.js`, `source.js`, `text-change.js` | CKEditor integration. |
| `collaborate.js` | Entry point: starts cursors, updates and drafts; re-exports `sessionFor`, `addRowInitializer` and `registerBridge`. `easyadmin.js` and `drupal.js` register their bridge's profile first. |
| `scss/_layer.scss`, `scss/_cursors.scss`, `scss/_draft.scss` | Styles; `_layer.scss` is a module (`$colors`, `$contrasts`) needing no framework, which `_cursors.scss` loads with Bootstrap's colours (it expects Bootstrap's `scss/functions` and `scss/variables` on the Sass load path). |

Bare imports the host's importmap provides: `ckeditor5`, `bootstrap/js/src/modal.js`, and `@microsoft/fetch-event-source` with the Mercure transport (imported on demand by `hub/mercure.js`).

A rich-text editor is created by the host. It passes `session.editorConfiguration(field)` into its configuration, calls `session.editorReady(name)` or `session.editorFailed()`, and names its Markdown mode radios `<field id>_view`. A host that builds editors for added collection rows registers them with `addRowInitializer(fn(row))`.
