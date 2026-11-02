# Configuration

Every option lives under the `collaborate` key. All of them have defaults;
most applications set `drafts.enabled`, `drafts.rich_text` and perhaps
`identity` and `access`. Durations are in seconds unless stated otherwise,
sizes in bytes.

Each option is also a container parameter named after its path
(`collaborate.drafts.max_fields`, `collaborate.hub.topic_prefix`…), with
`collaborate.<branch>` holding a whole branch.

## Reference

```yaml
# config/packages/collaborate.yaml
collaborate:
    identity: janalis_collaborate.identity.security
    access: janalis_collaborate.access.role
    role: ROLE_ADMIN
    system_label: System
    colors: [blue, indigo, purple, pink, red, orange, yellow, green, teal, cyan, black, white, gray, gray-dark]
    hub:
        name: default
        topic_prefix: 'urn:collaborate:'
        subscription_ttl: 300
        renew_interval: 240
        publish_pause: 5
        max_drafts: 8
    cursors:
        enabled: true
        rate_limiter: null
        grant_ttl: 36000
        throttle: 100           # milliseconds
        heartbeat: 5
        expiry: 15
        max_avatars: 5
        max_event_bytes: 4096
        places: null            # service id of a PlaceProviderInterface
        ignored_parameters: [_locale, _token, _switch_user]
        regions:                # name: root selector, in the order they are tried
            modal: '.modal.show'
            header: '.content-top'
            mobile-header: '.responsive-header'
            sidebar: '.ea-sidebar, .sidebar'
            content: '.content-wrapper'
            body: 'body'
        key_attribute: null
        key_scope: body
        anchor_attributes: [data-id, data-column, name, id]
        anchor_classes: false
        caret_regions: []       # every region
        popups: {}              # kind: { where: 'in %%name%%', line: 'Also open by %%names%%.' }
        idle: 0                 # never
        markers: false
        edges: null
        top_layer: false
        presence: avatars       # or menu
        distinct_colors: false
        silent_alone: false
        share_stream: false
    drafts:
        enabled: false
        rate_limiter: null
        grant_ttl: 36000
        signature_ttl: 900
        grant_cache: cache.app
        max_request_bytes: 2097152
        max_update_bytes: 262144
        max_draft_bytes: 10485760
        max_fields: 10000
        max_value_depth: 8
        label_length: 255
        compact:
            bytes: 1048576
            fields: 5000
            idle: 60
        locks:
            save: 10
            writer: 1
        purge:
            clean_days: 7
            unsaved_days: 90
        replay:
            connected: 30
            disconnected: 5
            join_retry: 12
        rich_text:
            block_prefixes: []
            schema_option: null
            ckeditor_version: null
        position_field: position
        sensitive_fields: [password, plainpassword, salt, secret, clientsecret, apikey, apitoken, token, accesstoken, refreshtoken, confirmationtoken, privatekey, totpsecret, backupcodes, recoverycodes]
    updates:
        enabled: null           # follows drafts.enabled
        path_pattern: '#^/admin(?:/|$)#'
        routes: []
        association_depth: 2
        ignored_fields: [updatedAt]
        chunk: 100
```

## Top level

| Option | Default | Meaning |
|---|---|---|
| `identity` | `janalis_collaborate.identity.security` | Service id of a `CollaboratorProviderInterface`: a user's id, name, avatar and what admits them. The default reads a public `id` or `getId()`, the user's string form and its roles. See [Identity and access](./identity-and-access). |
| `access` | `janalis_collaborate.access.role` | Service id of a `DraftAccessCheckerInterface`: whether a user may share the draft of a record of a given class. The default admits every collaborator. |
| `role` | `ROLE_ADMIN` | Role every collaboration endpoint, grant and layer requires. A user without it gets no layer, no presence and no shared form. |
| `system_label` | `System` | Name shown for writes no editor made: a compaction, a reconciliation with a record saved outside the draft. |
| `colors` | Bootstrap's 14 `$colors` names | Color names, at least one. A user's color is `colors[id % count]`. Each name must be a key of the Sass `$colors` map the stylesheet is compiled with. |

## `hub`

| Option | Default | Meaning |
|---|---|---|
| `name` | `default` | The Mercure hub to publish on and mint tokens with (`mercure.hubs.<name>`). |
| `topic_prefix` | `urn:collaborate:` | Prefix of every topic: `<prefix>cursors:<room>`, `<prefix>draft:<id>` and `<prefix>updates`. Give each application sharing a hub its own prefix. |
| `subscription_ttl` | `300` (min 30) | Lifetime of the subscriber JWT the session endpoint mints. |
| `renew_interval` | `240` (min 10) | How often a page asks for a new subscription. Keep it below `subscription_ttl`. |
| `publish_pause` | `5` (min 0) | After a failed publication, how long a worker stops publishing (and answers cursor events with 503), so a hanging hub does not hold a worker per request. |
| `max_drafts` | `8` (min 1) | Field grants one session request may carry: the shared forms of one page. More is refused with 400. |

## `cursors`

| Option | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Shared pointers, carets and presence. When off, the event route answers 404 and the layer serves the other features only. |
| `rate_limiter` | `null` | Service id of a rate limiter factory (for example `limiter.collaborate_cursors`), consumed once per session and cursor request, keyed by user id. Over the limit, requests get 429. |
| `grant_ttl` | `36000` (min 60) | Lifetime of the page grant the layer carries. Page grants admit a page to the hub whether cursors are on or not. |
| `throttle` | `100` (min 16) | Minimum interval between two cursor events of a tab, **milliseconds**. |
| `heartbeat` | `5` (min 1) | Heartbeat interval of an idle tab. |
| `expiry` | `15` (min 2) | Silence after which a peer's pointer and presence leave. |
| `max_avatars` | `5` (min 1) | Peers shown in the header before a `+N` counter. |
| `max_event_bytes` | `4096` (min 512) | Largest cursor event body. |
| `places` | `null` | Service id of a `PlaceProviderInterface` that names a page's room and is asked again at every hub request; `null` keeps the room of the route, its parameters and the query. See [Pages of your own](./cursors-and-presence#pages-of-your-own). |
| `ignored_parameters` | `[_locale, _token, _switch_user]` | Route and query parameters that do not separate rooms, without a place provider. |
| `regions` | EasyAdmin's layout | The regions of a page, `name: selector`, the first one an element is in naming it. Names are lowercase words joined by `-`; events naming another region get 400. |
| `key_attribute` | `null` | Attribute naming a record wherever a page shows it (`data-key`): a pointer on it is placed from the record, in whatever element the peer's page shows it. |
| `key_scope` | `body` | Selector of what holds the records the page shows its readers, for markers, popups and finding a peer. A record outside it is never named to peers. |
| `anchor_attributes` | `[data-id, data-column, name, id]` | Attributes naming an element on the way from its region to a pointer, in order of preference. |
| `anchor_classes` | `false` | Name an element by a class no sibling shares before its rank. |
| `caret_regions` | `[]` | Regions whose text fields share their caret; empty for all. |
| `popups` | `{}` | Kinds of popup a peer may have open on a record, each with `where` (what the presence menu says, `%name%` the record) and `line` (under the popup's title, `%names%` the peers), translated in the `collaborate` domain. In YAML, write `%%name%%`. |
| `idle` | `0` (min 0) | Seconds after which a still pointer is dimmed and its peer said idle, its name showing only while it moves; `0` never. |
| `markers` | `false` | Mark the records peers point at or have a popup of with their initials. |
| `edges` | `null` | Selector of the scrolling panes whose edge shows an arrow for a pointer scrolled out of them. |
| `top_layer` | `false` | Draw pointers in the top layer (a popover), above an open modal `<dialog>`. |
| `presence` | `avatars` | `menu` makes the avatars the button of a menu listing the peers and where they are, finding one, and hiding the pointers. |
| `distinct_colors` | `false` | Draw a peer in the colour farthest from the others' when an earlier peer has theirs or one beside it in `colors`. |
| `silent_alone` | `false` | Send no pointer while nobody else is on the page; a peer who comes says hello and is answered. |
| `share_stream` | `false` | Over HTTP/1.1, let one tab of a browser share its cursor at a time (Web Locks). |

## `drafts`

| Option | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Shared drafts. |
| `rate_limiter` | `null` | Service id of a rate limiter factory, consumed once per draft request, keyed by user id. Over the limit, requests get 429 and the page retries. |
| `grant_ttl` | `36000` (min 60) | Lifetime of a form's field grant. A page renews an expired one from the form's own URL. |
| `signature_ttl` | `900` (min 10) | How long a verified field grant is resolved from its signature alone; pages then send 64 characters instead of the whole grant. |
| `grant_cache` | `cache.app` | PSR-6 pool that remembers verified grants. With several servers, a pool they share avoids full resends. |
| `max_request_bytes` | `2097152` (min 65536) | Largest draft or session request body. A form whose field grant would not fit beside one update is edited without a shared draft, and a warning naming its entity is logged. |
| `max_update_bytes` | `262144` (min 4096) | Largest single update, counted in stored UTF-8 bytes (413 beyond). |
| `max_draft_bytes` | `10485760` (min 65536) | Largest journal of one draft (the draft is then *full*: 413). |
| `max_fields` | `10000` (min 10) | Most field states one draft holds; collection entries added beyond it are refused. |
| `max_value_depth` | `8` (min 1) | Deepest nesting of a value in an update. |
| `label_length` | `255` (min 16) | Longest option label a choice event carries, and longest editor name stored with a save, in characters. |
| `position_field` | `position` | Name of the child that orders a collection entry. A collection whose entries have none stores no order, and its order never counts as a difference with the saved record. |
| `sensitive_fields` | see the reference | Field names whose values never enter a draft, compared lowercased without `_` and `-`. Setting it replaces the list. |

### `drafts.compact`

Join compacts an idle draft whose every field is saved: the saved values
become the new baseline, the journal empties and the generation advances.

| Option | Default | Meaning |
|---|---|---|
| `bytes` | `1048576` (min 1024) | Journal size past which a draft is compacted. |
| `fields` | `5000` (min 1) | Field states past which a draft is compacted to drop removed collection entries. |
| `idle` | `60` (min 0) | Time nobody has written to the draft before a Join compacts it. |

### `drafts.locks`

| Option | Default | Meaning |
|---|---|---|
| `save` | `10` (min 1) | How long a Save waits for another Save of the same draft. |
| `writer` | `1` (min 0) | How long other writers (join, append, discard, reconcile) wait before answering 503; the page retries. |

### `drafts.purge`

Read by `collaborate:purge`.

| Option | Default | Meaning |
|---|---|---|
| `clean_days` | `7` (min 1) | Idle days before a draft with nothing unsaved, or one that has created its record, is deleted. |
| `unsaved_days` | `90` (min 1) | Idle days before any draft is deleted. |

### `drafts.replay`

| Option | Default | Meaning |
|---|---|---|
| `connected` | `30` (min 1) | Replay interval while the page's hub stream is up; it only covers a lost publication. |
| `disconnected` | `5` (min 1) | Replay interval while the stream is down. |
| `join_retry` | `12` (min 0) | How long a page retries a busy Join (429 or 503) before falling back to ordinary editing. Keep it above `locks.save`. |

### `drafts.rich_text`

| Option | Default | Meaning |
|---|---|---|
| `block_prefixes` | `[]` | Form block prefixes (a type's `getBlockPrefix()`, or any of its parents') rendered as CKEditor fields. Their draft holds CKEditor model operations instead of plain text. |
| `schema_option` | `null` | Name of a boolean form option that selects a reduced editor build. Fields where it is `true` get a `basic` schema, the others `full`; the two never share a draft. |
| `ckeditor_version` | `null` | CKEditor version written into every text field's schema. `null` reads the `ckeditor5` version pinned in `importmap.php`; with neither, rendering a shared form fails with an explanation. |

## `updates`

The saved-list and saved-detail refresh ([Saved-view refresh](./saved-views)).

| Option | Default | Meaning |
|---|---|---|
| `enabled` | `null` | `null` follows `drafts.enabled`. |
| `path_pattern` | `#^/admin(?:/\|$)#` | Regular expression of the request paths whose Doctrine writes announce updates. Adapt it when your admin lives elsewhere, for example `#^/[a-z]{2}/admin(?:/\|$)#` under a locale prefix. |
| `routes` | `[]` | Other route names whose writes announce updates, such as a sort endpoint outside the admin path. |
| `association_depth` | `2` (min 0) | How many associations away, in either direction, a changed class makes other classes' views stale. Two hops cross a join entity. |
| `ignored_fields` | `[updatedAt]` | Changed fields that alone never announce an update. |
| `chunk` | `100` (min 1) | Drafts named per notice to the edit pages of other changed records. |

## Rate limiters

Both limiters take a factory from `framework.rate_limiter`:

```yaml
framework:
    rate_limiter:
        collaborate_cursors:
            policy: sliding_window
            limit: 1200
            interval: '1 minute'
        collaborate_drafts:
            policy: sliding_window
            limit: 600
            interval: '1 minute'

collaborate:
    cursors:
        rate_limiter: limiter.collaborate_cursors
    drafts:
        rate_limiter: limiter.collaborate_drafts
```

The limits above are an example. Size them to your users: one tab sends up
to ten cursor events a second while its pointer moves (`cursors.throttle`),
a typing editor sends its changes in batches 150 ms apart, and every open
form replays the draft every `drafts.replay.connected` seconds.
