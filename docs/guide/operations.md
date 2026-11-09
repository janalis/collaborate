# Operations and security

## Endpoints and checks

| Route | Checks | Body limit |
|---|---|---|
| `collaborate_session` | role, collaborator id, remembered session, CSRF `collaborate-hub`, page grant, cursor rate limiter | `drafts.max_request_bytes` |
| `collaborate_cursor_events` | the same; 404 when cursors are disabled | `cursors.max_event_bytes` |
| `collaborate_draft` | role, collaborator id, CSRF `collaborate-draft`, draft rate limiter, field grant (and `canEdit()`) | `drafts.max_request_bytes` |

All answers carry `Cache-Control: no-store`. A rate limiter that refuses a
request answers 429 with `Retry-After: 1`. Request headers the bundle uses:
`X-CSRF-Token`, `X-Collaborate-Refresh: 1` (a form's action answers its
fresh grant and tokens) and `X-Collaborate-Renew: 1` (a background reload
for a fresh page grant, whose flash messages are kept).

## Hub topics

All topics start with [`hub.topic_prefix`](./configuration#hub)
(`urn:collaborate:`):

| Topic | Carries |
|---|---|
| `<prefix>cursors:<room>` | Cursor and presence events of one room (an HMAC of the page's route and query). |
| `<prefix>draft:<id>` | Invalidations of one draft (`append`, `discard`, `reconcile`, `saved`, `record`). The id is a SHA-256 of the record's class and id, or of `new:<key>` for a new-record page. |
| `<prefix>updates` | Saved-view notices: the short names of the entities a write can alter. |

Every notice is published **private**: with Mercure, a private update;
with the WebSocket hub, only to the sockets whose token names its topic.
Payloads hold ids, kinds and
revisions; the only user data they carry are cursor events' names, colors
and avatar URLs. Draft content is only ever read over the authenticated
draft endpoint.

Give each application that shares a Mercure hub its own `topic_prefix`,
and do not allow anonymous subscribers on the hub. Run one
[WebSocket hub](./websocket-hub#one-hub-per-application) per application,
with its own secret.

## Subscriber tokens

The session endpoint mints a subscriber token for the topics the page
grant and the verified field grants admit, valid
[`hub.subscription_ttl`](./configuration#hub) seconds (300): a JWT signed
with the Mercure hub's own token factory, or an HMAC token signed with the
WebSocket hub's secret. Its answer names the `transport` the page connects
with. Pages renew it every `hub.renew_interval` seconds (240). With
Mercure, the page keeps the open stream until its replacement is open, so
no event is lost in between; an event both streams carry arrives twice,
which every feature tolerates. With the WebSocket hub, the page renews on
the open socket, which replaces its topics at once. A page carries at most
[`hub.max_drafts`](./configuration#hub) field grants (8).

Mercure checks a token when a stream opens; the WebSocket hub stops
delivery when it expires and at every renewal drops the topics the page
lost. See [Revocation](./websocket-hub#revocation).

Publications happen on `kernel.terminate`, after the response is sent and
the session saved, so a slow hub never holds the session lock. A failed
publication is logged on the `collaborate` channel and pauses that worker's
publishing for `hub.publish_pause` seconds. Drafts do not depend on
publications: pages replay on their own (every 30 seconds while connected,
5 while not).

## Database

The journal runs on the `database_connection` service and needs MariaDB or
MySQL: it uses named locks (`GET_LOCK`), `INSERT IGNORE`,
`ON DUPLICATE KEY UPDATE` and `SELECT … FOR UPDATE`.

- **Named locks** are named
  `collaborate:<16 hex characters of the database name's hash>:<md5 of the draft id>`.
  They are global to the database server, so the database-name hash keeps
  the same record's draft in two databases (staging and production, test
  instances) on separate locks.
- Every writer of a draft (join, append, discard, reconcile, Save) holds
  its lock. Save holds it from its revision check to the response, outside
  any transaction, so the application's flush commits on its own and its
  post-flush work sees committed rows; it waits up to `drafts.locks.save`
  seconds (10) for another Save. The others wait up to `drafts.locks.writer`
  seconds (1), then answer 503; the page sends again a second later.
- Long-running workers (FrankenPHP worker mode, RoadRunner) keep their
  connection and any lock an unfinished request left: the journal releases
  them when the container resets its services (`kernel.reset`).
- A batch the server already accepted is answered with its stored result
  when a page retries it.
- A Save whose draft checkpoint fails after the record is committed keeps
  its response and logs the error; the stale baseline then shows to editors
  as a difference with the saved record.

## Deploying

- **Tables and purge.** Create the [tables](./installation#_3-create-the-tables)
  and schedule [`collaborate:purge`](./installation#_4-schedule-the-purge)
  before turning `drafts.enabled` on.
- **`kernel.secret`.** Page and field grants, and cursor rooms, are signed
  with it. Rotating it invalidates every open page's grants: pages renew
  their page grant from the page itself and their field grants from their
  form, or stop with *access expired*.
- **Several servers.** Point [`drafts.grant_cache`](./configuration#drafts)
  at a pool all servers share; otherwise a request reaching another server
  costs one full grant resend (412). Named locks need all servers to use the
  same database server.
- **Grant renewal.** Page and field grants last 10 hours; subscriber tokens
  5 minutes. A user whose `authorization()` changes (a role removed, a
  group left) is refused on their next request, and only a page that still
  admits them issues a new grant.
- **CKEditor upgrades** change field schemas: see
  [Schema compatibility](./shared-drafts#schema-compatibility).
- **Logs.** Hub failures, oversize forms and failed checkpoints are logged
  on the `collaborate` Monolog channel, at error level, with every page
  that stops following its draft as incompatible: its draft, editor and
  cause (`refused`: the server refused a batch, with its HTTP status and
  field; `version`: an editor initialized from another model version than
  the draft stored; `replay`: an event the page could not apply; `schema`:
  a field's schema changed; `prototype`: a collection entry arrived with no
  prototype to render it), and the page's console names it too. A refused
  batch also leaves the request's own error, which names a field outside
  the form's grant.
- **WebSocket hub.** Run `collaborate:hub:serve` as one supervised
  process, restarted on every deployment, behind the application's proxy:
  see [Deployment](./websocket-hub#deployment).

## Security notes

- Grants are HMAC-SHA256 signed with `kernel.secret` and bound to the user
  id and a hash of their authorization; the client never chooses a topic,
  record or field.
- Every draft request checks `DraftAccessCheckerInterface::canEdit()` again,
  and every request checks the role.
- Avatar URLs that are not on the page's own origin (another site, a
  `javascript:` or `data:` URL) are dropped by the browser code, and labels
  are rendered as text, so a forged notice can inject neither markup nor a
  URL.
- Field names in [`drafts.sensitive_fields`](./configuration#drafts), and
  password, hidden and file controls, never enter a draft or a cursor event.
