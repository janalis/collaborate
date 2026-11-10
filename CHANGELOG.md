# Changelog

All notable changes to `janalis/collaborate`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Bridges**, the admin the pages belong to
  ([guide](https://janalis.github.io/collaborate/guide/bridges)):
  `collaborate.bridge`, `auto` by default (`easyadmin` when EasyAdminBundle
  is registered, `none` otherwise), `easyadmin` or `none`. A bridge loads
  its admin's services and sets the default `cursors.regions` and the
  scripts' profile, which reads its markup. The resolved bridge is the
  `collaborate.bridge` parameter and the layer's `bridge`.
- **A Drupal module**, `janalis/collaborate_drupal` (`drupal/`), for Drupal
  11.3+ and 12 on Claro or Gin
  ([guide](https://janalis.github.io/collaborate/guide/drupal)): shared
  drafts of content entity forms (CKEditor 5 fields as rich text), the others
  in Navigation's top bar, refreshed listings, the bell, on the bundle's own
  services in Drupal's container. Configured from
  `/admin/config/content/collaborate` and `settings.php`; Mercure or the
  WebSocket hub (`drush collaborate:hub:serve`); notifications through
  Drupal's queue. The items of multi-value fields are shared as a
  collection, keyed through Drupal's rebuilds ("Add another item", Remove,
  a reorder), and so are paragraphs (any type a peer adds) and the entities
  Inline Entity Form's simple widget edits. On MariaDB, MySQL, PostgreSQL or
  SQLite sites.
- **PostgreSQL and SQLite**: the draft journal speaks each platform's SQL
  where they differ (`Draft\Dialect\`: upserts, row locks, and named locks:
  PostgreSQL's advisory locks, a lock file on SQLite), and the entity mapping
  drops MySQL's binary collations and `DATETIME(6)` on the other platforms
  (`Doctrine\PortableSchema`). MariaDB and MySQL schemas are unchanged.
- A bridge's script profile can add and remove a collection's entries itself
  (`collections.entry`, `create`, `remove`) when its collections have no
  prototype, and name the child it numbers after a reorder
  (`collections.position`).
- The Drupal module is published from `drupal/`, released with the bundle
  under the same version: a split repository for Packagist
  (`janalis/collaborate_drupal`) and the drupal.org project
  (`drupal/collaborate`). Merge requests and issues opened on drupal.org are
  mirrored to GitHub, where the module is developed
  ([contributing](https://janalis.github.io/collaborate/contributing/drupal)).
- `@janalis/collaborate/collaborate.js`, the entry point of any admin, and
  `registerBridge()`, which describes an admin's markup to the scripts.
- `data-collaborate-page="<entity>:<index|detail|edit|new>"` on a page's
  body says what it shows: the saved-view refresh reloads a marked list or
  detail page of any admin.
- `data-collaborate-submitter` on a save button: the bar's Save stands for
  it.
- `Security\CurrentUserInterface`, `Draft\RecordIdentifierInterface` and
  `Draft\FormDraft` (a form's draft whatever builds the form), and the
  `collaborate_trans` filter and `collaborate_csrf()` function the bundle's
  templates now use, for hosts other than a Symfony kernel.

### Changed

- Without a bridge, the defaults are no longer EasyAdmin's: `cursors.regions`
  defaults to `modal`, `header`, `content` and `body` (`dialog[open],
  .modal.show`, `body > header, [role=banner]`, `main, [role=main]`), the
  scripts read the bundle's own markup (`data-collaborate-row`,
  `data-collaborate-entry`), and the saved-view refresh reloads the pages
  that say what they show. EasyAdmin's are unchanged.
- The EasyAdmin services moved to `Janalis\Collaborate\Bridge\EasyAdmin\`
  and `UpdatesSubscriber` to `Janalis\Collaborate\Updates\`;
  `config/services/easyadmin.php` is `bridge_easyadmin.php`.
- The controllers, the hub guard, the Twig extension, the form extension and
  the notification access read the user through `CurrentUserInterface`
  (Symfony's security helper, `SymfonyCurrentUser`), and `DraftSave` the
  record's id through `RecordIdentifierInterface`.
- `_cursors.scss` loads EasyAdmin's header rules from `_easyadmin.scss`.

### Deprecated

- `Janalis\Collaborate\EasyAdmin\{EasyAdminFormContext,SaveSubscriber,DraftRefreshSubscriber,UpdatesSubscriber}`:
  aliases of their moved classes, removed in 0.6.
- `CollaborateBundle::REGIONS`: `Bridge::EasyAdmin->regions()`.

### Fixed

- The `report` draft action, which a page sends when it stops following its
  draft, reached no route: `collaborate_draft` accepts it.

### Upgrading

- A host without EasyAdmin whose pages relied on EasyAdmin's default regions
  or markup sets `cursors.regions`, or `collaborate.bridge: easyadmin`; its
  pages import `@janalis/collaborate/collaborate.js` (`easyadmin.js` reads
  EasyAdmin's markup).
- A host that wants its own lists refreshed marks them
  `data-collaborate-page`.
- A host that constructs the services itself passes a `CurrentUserInterface`
  (`new SymfonyCurrentUser($security)`) where it passed Symfony's
  `Security`.
- A host that overrides the bundle's templates replaces `|trans({}, 'collaborate')`
  with `|collaborate_trans` and `csrf_token()` with `collaborate_csrf()`, or
  keeps Symfony's.

## [0.4.1] - 2026-11-09

### Added

- A page that stops following its draft as incompatible reports why: the
  `report` draft action logs its cause (`refused`, `version`, `replay`,
  `schema` or `prototype`), field, HTTP status and detail with its draft
  and editor at error level on the `collaborate` channel, and the page's
  console names it. Halts the page decided itself left no trace on the
  server.

### Changed

- An append for a field outside the form's grant is refused as
  `Collaborate field "<name>" is not in this form.` instead of the generic
  invalid-event message.

## [0.4.0] - 2026-11-08

### Added

- **A WebSocket hub**, as an alternative to Mercure
  ([guide](https://janalis.github.io/collaborate/guide/websocket-hub)):
  `collaborate.hub.transport: websocket` and a server shipped in the
  bundle, `bin/console collaborate:hub:serve` (ReactPHP: `react/http`,
  `react/socket`, `react/stream`, `ratchet/rfc6455`,
  `symfony/http-client`). Pages subscribe over one socket with a token
  bound to their origin and topics; the server stops delivering when it
  expires, and a renewal that loses a topic stops it at once. The
  application publishes over a private side, every request signed. The
  server handles `SIGTERM` gracefully with `ext-pcntl`, and past 1000
  connections needs `ext-ev` or `ext-event`. Copy-ready proxy and process
  files for nginx, Apache, Caddy, Traefik, HAProxy, systemd, Supervisor,
  Docker Compose, Kubernetes, the Symfony CLI and Upsun are in
  `docs/deploy/`.
- On the WebSocket hub, pages send their pointer, caret and presence over
  their socket: the hub validates each event as the controller does,
  stamps it with the identity the token signed and relays it at once, with
  no request to the application per move and no wait for one before the
  next. `collaborate.hub.websocket.cursor_events: false` posts them to the
  application instead, which checks each against the page grant.
- `collaborate.hub.websocket`: `public_url`, `path`, `publish_url`,
  `publish_socket`, `listen`, `publish_listen`, `socket_mode`, `secret`,
  `previous_secret` (rotation), `allowed_origins`, `trusted_proxies`,
  `cursor_events`,
  `ping_interval`, the connection, message and buffer limits, and
  `shutdown_timeout`.
- The session endpoint's answer names the page's `transport` (`mercure`
  or `websocket`), which picks the client `hub.js` loads, and
  `cursorEvents`, whether the page sends its cursor events over it.
- `Janalis\Collaborate\Cursor\CursorEvents`: the one shape of a cursor
  notice, which `CursorController` and the WebSocket hub both publish.

### Changed

- `collaborate.hub.name` moves to `collaborate.hub.mercure.name`. The
  former key still works and triggers a deprecation; setting both is an
  error. The `collaborate.hub.name` parameter still holds the name.
- `symfony/mercure` and `symfony/mercure-bundle` are optional (suggested):
  only the Mercure transport, still the default, needs them. Without
  MercureBundle registered, that transport fails at container compilation
  with a message saying what to do. `@microsoft/fetch-event-source` is
  loaded only on pages that stream from Mercure.
- The `janalis_collaborate.hub` alias (the Mercure hub service) is gone:
  the bundle publishes and subscribes through
  `Janalis\Collaborate\Hub\Transport\HubTransportInterface`, and asks
  who listens (`notifications.web_push.skip_online`) through
  `PresenceProbeInterface`, both aliased to the configured transport.
- `HubPublisher::defer()` takes a `Janalis\Collaborate\Hub\Notice` (one
  or more topics and a JSON payload, always private) instead of a
  `Symfony\Component\Mercure\Update`, and the deferred notices of a
  request go to the transport in one call. A host decorating
  `HubPublisher` builds `Notice`s.
- Log messages and errors say "hub" where they said "Mercure".

### Upgrading

- On the Mercure transport, run `composer require symfony/mercure-bundle`
  **before** updating the bundle: it is no longer required, so Composer
  would remove it, and `config/bundles.php`, which still registers
  `MercureBundle`, would fail.
- Move `collaborate.hub.name` to `collaborate.hub.mercure.name`.
- A decorator of the Mercure hub service (`mercure.hub.<name>` or
  `janalis_collaborate.hub`) that the bundle published through decorates
  `Janalis\Collaborate\Hub\Transport\HubTransportInterface` instead,
  whose `publish()` receives the `Notice`s.

## [0.3.4] - 2026-11-07

### Fixed

- With `cursors.silent_alone`, a reader who moved while alone tells where
  they are as soon as a first peer answers their hello: their pointer,
  caret or field showed only with their next move or heartbeat, up to five
  seconds later.

## [0.3.3] - 2026-11-07

### Changed

- Web push runs on `minishlink/web-push` 11, over Symfony's HTTP client
  (PSR-18, through `Psr18Client`): Guzzle is no longer needed, and the
  10-second timeout and the refusal of redirects are the client's options.
  9.x passed `openssl_pkey_derive()` a key length PHP 8.5 deprecates, which
  logged a deprecation at every push. The bundle conflicts with
  `minishlink/web-push` below 11: a host moves with
  `composer require minishlink/web-push:^11.0 nyholm/psr7` (or any other
  PSR-17 implementation).

## [0.3.2] - 2026-11-07

### Changed

- **A push shows in the page only while it has the focus.** The service
  worker posts it to the page of the application that has the focus, and
  shows a system notification whenever none has it, open pages hidden or
  behind another window included (before, any open page took it). The bell
  of a page without the focus no longer says a notification on a device web
  push reaches; it still counts it: the service worker tells every open page
  to ask for its list again, when it shows a push and when a click on one
  reads it, whatever the hub told them.
- `notifications.web_push.skip_online` is off by default: on, it would push
  nothing to a user whose page is open but not looked at.

### Fixed

- Turning web push on for the first time subscribes through the active
  service worker: a worker registered moments before was still installing,
  and the switch stayed off although the subscription was made.

## [0.3.1] - 2026-11-07

### Security

- **Web push posts only to push services.** A signed-in user could save any
  https address as the endpoint of their browser, which the server then
  requested at every notification: an internal service, the metadata of a
  cloud instance. An endpoint is now refused unless it is on one of
  `notifications.web_push.endpoint_hosts` (FCM, Mozilla's, Apple's and
  WNS's push services by default) or under one of their domains, on the
  default port, without credentials; a subscription saved earlier on
  another host is forgotten instead of pushed to. The push client follows
  no redirect and gives up after 10 seconds.

## [0.3.0] - 2026-11-07

### Added

- **Notifications**, off until `notifications` is configured
  ([guide](https://janalis.github.io/collaborate/guide/notifications)):
  - a bell (`collaborate_notifications()`), a page of all notifications and
    a page of the topics a user subscribes to, with their scripts
    (`notifications/bell.js`, `page.js`, `topics.js`) and styles
    (`scss/_notifications.scss`);
  - `Notifier::notify()` / `notifyAll()`, which tell the pages of their reader
    over the stream they already hold;
  - topics: unsubscribed, a user is told nothing of a topic, and a
    mandatory one cannot be left;
  - Messenger: with `notifications.bus`, a message per recipient written once
    whatever its deliveries, and a message per push;
  - web push: a system notification only when no page of the application is
    open, written in the language of its reader, and `collaborate:push:setup`,
    which makes the VAPID keys and writes them into `.env.local` or the
    secrets vault (`--check` checks them).
- A **bare page grant**, which a page in no place, or a user who may be
  notified but not collaborate, is given while notifications are on: its
  stream carries their notifications alone. A
  `notifications.stream_guard` (`StreamGuardInterface`) withholds it where
  the host has no hub to stream on.

### Changed

- What a console command or a Messenger worker defers to the hub is
  published once the command or the message is done.
- `PageGrant::topic()` and `Admission::$topic` may be `null` (a bare grant);
  cursor events refuse such a grant.

## [0.2.4] - 2026-11-05

### Changed

- The state bar offers **Save** while the draft holds unsaved changes. It
  submits the form through its usual path, with the button that keeps the
  editor on the page: one marked `data-collaborate-submitter`, else
  EasyAdmin's `saveAndContinue`, else its `saveAndReturn`, else the first. Before this, the bar's only
  button was Discard, and editors working alone took it for the way to deal
  with "unsaved changes", which erased their work.
- **Discard changes** (formerly *Discard shared draft*) comes after Save and
  is styled as a secondary link. It is hidden, not just disabled, in the
  states it cannot act from, the saved state included. Its dialog says that
  the changes are erased and that Save keeps them.
- The messages say what the editor can do: "All changes saved.", "Unsaved
  changes, kept automatically. Click Save to record them.". The new
  translation keys are `save`, and the reworded `saved`, `savedBy`, `draft`,
  `discard` and `confirmDiscard*`; hosts that override these strings need
  the new source keys.

### Fixed

- A save in a non-default locale checkpoints the value each translated field
  holds once the record is persisted, as for every other field, where it
  checkpointed the submitted value. When the save stores a value of its own
  (a slug its listener derives from the new name), the draft and the record
  now agree instead of showing a conflict on the next page.

### Deprecated

- `FieldKeyResolverInterface::savedElsewhere()` is no longer called; it is
  removed in 0.3.

## [0.2.3] - 2026-11-05

### Fixed

- The chips of the presence menu's button are apart in its text, as they are
  drawn: run together, the initials read as one word (`ÉB`), which the
  button's name (`É B: …`) did not hold.

## [0.2.2] - 2026-11-05

### Fixed

- The button of `presence: menu` is named after what it shows (the initials,
  the count of the others, for the stack the window shows) then the peers'
  names, where it was named after the names alone: speech control finds it by
  the text it reads (WCAG 2.5.3 Label in Name, which axe-core 4.14 checks).

## [0.2.1] - 2026-11-05

### Changed

- Allows `symfony/mercure-bundle` 0.7 and `symfony/mercure` 0.10. MercureBundle
  0.7 clears the subscriber cookie of every hub on logout, unless a host sets
  `clear_cookie_on_logout: false`.

## [0.2.0] - 2026-11-03

### Added

- Shared drafts and field marks work with every stock Symfony form theme
  (`form_div_layout`, `form_table_layout`, Bootstrap 3/4/5, Foundation 5/6,
  Tailwind 2, daisyUI 5), not only with `.form-group` rows and EasyAdmin's
  collection markup. A shared form marks its rows (`data-collaborate-row`),
  its collections' widgets (`data-collaborate-entries`,
  `data-collaborate-placeholder`) and their entries
  (`data-collaborate-entry`); EasyAdmin's markup is still read.

## [0.1.2] - 2026-11-03

### Fixed

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
- A peer's event redraws that peer alone: its pointer is measured in the next
  frame rather than as the event comes, the markers and the popup line are
  drawn again only once a peer changes record, popup, name or colour, and a
  caret that did not move is not measured. A frame measures every pointer
  and record before it draws any, looks each region, path and record up once,
  and the layer's announcements and popup lines no longer draw the page again.

### Upgrading

- `collaborate_draft` has an index on `updated_at`, which the purge reads:
  generate a migration (`doctrine:migrations:diff`) or add
  `CREATE INDEX collaborate_draft_updated_at ON collaborate_draft (updated_at)`.

## [0.1.1] - 2026-11-02

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

[Unreleased]: https://github.com/janalis/collaborate/compare/v0.4.1...HEAD
[0.4.1]: https://github.com/janalis/collaborate/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/janalis/collaborate/compare/v0.3.4...v0.4.0
[0.3.4]: https://github.com/janalis/collaborate/compare/v0.3.3...v0.3.4
[0.3.3]: https://github.com/janalis/collaborate/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/janalis/collaborate/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/janalis/collaborate/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/janalis/collaborate/compare/v0.2.4...v0.3.0
[0.2.4]: https://github.com/janalis/collaborate/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/janalis/collaborate/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/janalis/collaborate/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/janalis/collaborate/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/janalis/collaborate/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/janalis/collaborate/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/janalis/collaborate/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/janalis/collaborate/releases/tag/v0.1.0
