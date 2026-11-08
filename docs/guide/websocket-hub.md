# WebSocket hub

Every page holds one subscription to a **hub**, which carries the
bundle's notices: cursor events, draft invalidations, saved-view notices
and notifications. The hub has two transports:

- **`mercure`**, the default: a [Mercure](https://mercure.rocks) hub,
  through `symfony/mercure-bundle`. See
  [Installation](./installation#_5-set-up-the-hub).
- **`websocket`**: a WebSocket server shipped in the bundle,
  `bin/console collaborate:hub:serve`, built on ReactPHP. The application
  publishes to it over a private, signed HTTP channel.

The rest of the bundle does not change with the transport: the same
grants, topics, endpoints and scripts, and the same guarantees (the hub
carries invalidations, never content; a lost notice loses nothing).

## Choosing a transport

| | Mercure | WebSocket |
|---|---|---|
| Server | A Mercure hub: FrankenPHP's or Caddy's module, or the standalone hub | `collaborate:hub:serve`, a PHP process next to the application |
| PHP dependencies | `symfony/mercure-bundle` | `react/http`, `react/socket`, `react/stream`, `ratchet/rfc6455`, `nyholm/psr7`, `symfony/http-client` |
| Browser dependency | `@microsoft/fetch-event-source` in the import map | None: the browser's own `WebSocket` |
| Browser connection | Server-sent events, one HTTP connection per page: over HTTP/1.1, the six a browser opens per site (see `share_stream` in [Cursors and presence](./cursors-and-presence)) | One WebSocket per page, outside the six HTTP/1.1 connections |
| Tokens | A JWT signed with the hub's secret, checked when the stream opens | An HMAC token checked at every subscription and renewal; delivery stops when it expires |
| Instances | Whatever the hub supports | **One process** per application |
| Who listens (`skip_online`) | The hub's subscriptions API, when turned on | Built in |
| Proxy | The hub's own TLS, or a path of the application's origin | A path of the application's origin behind its reverse proxy |

Pick Mercure when you already run FrankenPHP or Caddy with its Mercure
module, or need more than one hub instance. Pick the WebSocket hub when
you have no Mercure hub and do not want one: PHP-FPM behind nginx or
Apache, a container platform, a PaaS.

## Install

```sh
composer require react/http react/socket react/stream ratchet/rfc6455 nyholm/psr7 symfony/http-client
```

`symfony/mercure-bundle` is not needed. Two PHP extensions are
recommended for the server:

| Extension | What it gives |
|---|---|
| `ext-pcntl` | A graceful shutdown on `SIGTERM` and `SIGINT`: clients are told the server is going away (1001) and reconnect. Without it (or `ext-ev`, `ext-event`), the server warns at startup and stops abruptly. |
| `ext-ev` (or `ext-event`) | An event loop without `stream_select()`'s limit of 1024 file descriptors: needed past about 1000 connections. |

`publish_socket` (publishing over a unix socket) needs curl
(`ext-curl`), which Symfony's HTTP client then uses.

The import map needs no new entry, and `@microsoft/fetch-event-source`
can go once no page uses Mercure.

## Configuration

```yaml
# config/packages/collaborate.yaml
collaborate:
    hub:
        transport: websocket
        websocket:
            public_url: '%env(COLLABORATE_HUB_PUBLIC_URL)%'
            publish_url: '%env(COLLABORATE_HUB_PUBLISH_URL)%'
            secret: '%env(COLLABORATE_HUB_SECRET)%'
```

```sh
# .env
COLLABORATE_HUB_PUBLIC_URL=/collaborate-hub
COLLABORATE_HUB_PUBLISH_URL=http://127.0.0.1:8091
COLLABORATE_HUB_SECRET=
```

Generate the secret and keep it out of the repository (`.env.local`, or
`bin/console secrets:set COLLABORATE_HUB_SECRET`):

```sh
php -r 'echo bin2hex(random_bytes(32));'
```

- **`transport`** is read when the container is compiled: it cannot come
  from an environment variable.
- **`secret`** is the hub's own, 32 bytes or more: not `kernel.secret`,
  which signs other things and whose length is unknown. It is no container
  parameter, so `debug:container --parameters` does not list it. Its
  length is checked when the hub's services are built and when the server
  starts, not when the container is compiled: an environment variable is
  unknown then.
- **`public_url`** is where browsers connect: a path on the application's
  origin (recommended, see [Same origin](#same-origin)), or a `wss://`
  URL. `ws://` is accepted only for `localhost`, `127.0.0.1`, `[::1]` and
  `*.localhost`. Tokens name it as their audience, written exactly as
  configured.
- **`publish_url`** is the root of the server's private side, `http://` or
  `https://` with no path. With `publish_socket` (a unix socket path),
  requests go through that socket and the URL's host is only the `Host`
  header: `http://localhost`.
- **`listen`** (`127.0.0.1:8090`) and **`publish_listen`**
  (`127.0.0.1:8091`) are the server's two addresses, `host:port` or
  `unix:///path`; they must differ. `path` (the path of `public_url` by
  default) is the only path the browser side upgrades.

Every option is in [Configuration](./configuration#hub-websocket). The
application and the server read the same configuration: run the server
from the same code, with the same environment.

## Running the server

```sh
bin/console collaborate:hub:serve
```

The server boots the application's kernel, logs its event loop and
addresses on the `collaborate` channel, and runs until it is stopped. It
says which packages are missing, if any, and warns when `memory_limit` is
short of what `max_connections` may take, or when it cannot handle
signals. Every five minutes it logs its connections and the notices it
delivered. Run
it under a process manager (systemd, Supervisor, your platform's workers)
that restarts it, and restart it on every deployment.

| Option | Default | Meaning |
|---|---|---|
| `--listen` | `hub.websocket.listen` | The browser-facing address, `host:port` or `unix:///path`. |
| `--publish-listen` | `hub.websocket.publish_listen` | The private address the application publishes to. |
| `--allow-remote-publish` | off | Accept a TCP `publish_listen` that is not a loopback address (`0.0.0.0:8091` in a container). |
| `--shutdown-timeout` | `hub.websocket.shutdown_timeout` | Seconds to wait for clients to close on `SIGTERM`. |
| `--force` | off | Start with `max_connections` above 1000 on the `stream_select()` loop. |

The server refuses to start when:

- the secret is missing or shorter than 32 bytes, or `public_url`,
  `path` or `allowed_origins` is malformed;
- `listen` and `publish_listen` are the same address;
- `publish_listen` is a TCP address other than loopback, without
  `--allow-remote-publish`;
- `max_connections` is above 1000 on the default `stream_select()` loop
  (its limit is 1024 file descriptors), without `--force`: install
  `ext-ev` or `ext-event`, or lower `max_connections`.

On `SIGTERM` or `SIGINT`, it closes both listeners, tells every client
it is going away (close code 1001), and stops once they are gone or after
`shutdown_timeout` seconds (10). Give your process manager a longer stop
timeout. Clients reconnect with a random delay and catch up on their own.

Unix sockets are created with `socket_mode` (`0660`) and removed on
shutdown. A stale socket left by a crash is replaced; a symbolic link, a
file that is no socket, or a socket another server still answers on is
not, and the server refuses to start.

### What the server speaks

The browser side accepts a WebSocket upgrade on `path`, and nothing else.
The page sends its token in its first message, never in the URL:

```json
{"hub":"subscribe","id":1,"token":"…"}
```

The server answers `{"hub":"subscribed","id":1}` or
`{"hub":"error","id":1}`. The same message renews the subscription on the
same socket, replacing its topics and expiry. Notices then arrive as text
frames holding the notice's JSON as published; a top-level `hub` key marks
the server's own messages. Close codes:

| Code | Meaning | The page |
|---|---|---|
| 4401 | The token is invalid or expired, or no subscription came within 10 seconds of connecting. | Asks for a new session at once. |
| 1008 | A protocol violation: a binary frame, another message, malformed JSON. | Waits the longest (30 seconds). |
| 1009 | A message over `max_message_size`. | Reconnects with a delay. |
| 1001 | The server is shutting down. | Reconnects with a random delay. |
| 1006 | The connection dropped: the network, or the server dropping a client that stayed slow or silent (no answer to two pings). | Reconnects with a delay. |

When the session answered `cursorEvents: true`, the page also sends its
pointer, caret and presence over the socket, with no answer:

```json
{"hub":"cursor","event":{"clientId":"…","sequence":12,"kind":"move","style":"mac","anchor":{…}}}
```

See [Cursor events over the socket](#cursor-events-over-the-socket).

The private side serves `POST /publish`, `GET /subscriptions?topic=…`
and `GET /health`; see [The publish channel](#the-publish-channel).

## Same origin

Serve the hub on a path of the application's own origin, behind the
reverse proxy that already serves the application:

```yaml
public_url: /collaborate-hub
```

The page opens `wss://<its host>/collaborate-hub` (`ws://` over plain
HTTP), so there is no CORS, no second certificate or domain, and the same
cookies-free connection whatever the proxy. The proxy forwards the
upgrade to `listen`, a loopback port or a unix socket; see
[Deployment](#deployment).

A content security policy then needs nothing new in theory, since
`connect-src 'self'` covers the page's own `wss:` origin in current
browsers. Older Safari versions did not, so name it explicitly:

```text
Content-Security-Policy: connect-src 'self' wss://admin.example.com
```

A hub on another origin (`wss://hub.example.com/`) works too: the socket
sends no cookie, the token is in the first message, and the token names
the page's origin, so `allowed_origins` is not needed. It needs its own
certificate and a `connect-src` entry.

## Security

### Keys

Two keys are derived from `secret` with HKDF-SHA256: one signs subscriber
tokens, the other the application's publish requests, so a subscriber
token reveals nothing of the publish key.

### Subscriber tokens

The session endpoint signs a token, `base64url(claims).base64url(HMAC)`,
for exactly the topics the page's grants prove. Its claims are readable,
not secret:

| Claim | Holds |
|---|---|
| `v` | `1` |
| `aud` | `public_url` as configured, compared exactly |
| `origin` | The page's origin, as the application saw it (Symfony's own trusted proxies apply) |
| `sub` | The user id, for `max_connections_per_user` alone |
| `topics` | The topics, exact (no wildcard), under `topic_prefix`, at most `max_drafts` + 3 |
| `iat`, `exp` | Issued and expiry times; a token lasts `subscription_ttl` at most |
| `cur` | For a page in a cursor room with cursors and `cursor_events` on: `{room, label, color, avatar}`, its room (one of `topics`) and who the user is there. Left out when it would make the token longer than 8 KiB (a huge avatar URL): the page then posts its events. |

The server checks the signature over the bytes it received before it
decodes them, then every claim, with 5 seconds of clock leeway on `iat`.

### Origin binding

The server refuses an upgrade without an `Origin` or with `Origin: null`,
and, when `allowed_origins` is not empty, one outside it. At each
subscription, the socket's `Origin` (lowercased, default port dropped)
must equal the token's `origin`. A page on another site that obtained a
token cannot use it from its own origin. The check never reads `Host` or
`X-Forwarded-*`, so it holds behind proxies that rewrite them.

### Revocation

Delivery stops when the token expires: the server checks `exp` at every
send, and closes the socket with 4401. Cursor events a page sends over its
socket stop with it. A page renews every
`renew_interval` seconds through the session endpoint, which checks the
role, the page grant, the place and every field grant again; a renewal
with fewer topics (a draft now refused) stops the removed topics at once.

Mercure checks a token only when the stream opens: an open stream keeps
receiving until it closes, past the token's expiry and past a revoked
grant. The WebSocket hub stops within `subscription_ttl` at most, sooner
on renewal.

### What a socket may send

A socket accepts two messages: a subscription, and the page's own cursor
events. Every other write (drafts, saves, notifications) still goes
through the bundle's guarded, CSRF-protected endpoints. Unmasked frames,
reserved bits, binary frames, other messages and oversized ones close the
connection.

### Cursor events over the socket

A pointer move posted to the application is a whole request: the session,
the firewall, the CSRF token, the page grant and the rate limiter, then a
publish to the hub; and the page waits for its answer before it sends the
next move. With `cursor_events` on (the default), the page sends its
moves over its socket instead, and the hub relays them at once:

- The hub validates each event as the controller does (`CursorEvent`),
  and stamps it with the identity the token's `cur` claim signed (user
  id, label, colour, avatar) and its own time, whatever the page sent:
  the notice is the one `POST /cursors/events` would publish.
- An event goes to the token's cursor room alone, never to another topic.
- Each socket may send twice the rate `cursors.throttle` lets a page;
  past it, and past `cursors.max_event_bytes`, events are dropped.

The trade-off is when access is checked. A posted event is checked
against the page grant, the role and the place each time; an event sent
over the socket is checked against the token, which the session endpoint
signed after the same checks. A user who loses access stops sending, as
they stop receiving, at the latest when their token expires
(`subscription_ttl`), and at their next renewal (`renew_interval`). An
application that needs every pointer move checked (a place revoked
mid-session must stop its pages' cursors at once) sets `cursor_events:
false`: pages then post their events, as on Mercure.

The page's departure (`leave`, on unload) is still posted, since the
socket may already be closing.

### Limits against abuse

| Limit | Default |
|---|---|
| `max_connections` | 1000 connections in all (raise it with `ext-ev` or `ext-event`) |
| `max_pending` | 1000 connections that have not subscribed yet; each has 10 seconds to subscribe |
| `max_connections_per_user` | 50 subscribed connections per user (`sub`) |
| `max_connections_per_ip` | 0 (off) |
| `max_message_size` | 16 KiB per message a browser sends |
| `max_buffer` | 1 MiB queued for a slow client, which is then dropped |

A connection may send 10 subscriptions a minute (a page renews every
`renew_interval`, and when it comes back online or into view), and a
client has 5 seconds to send its upgrade request. Sockets still sending
their request count against `max_connections` plus `max_pending`: past
that, new sockets are closed as soon as they are accepted. A connection
that does not finish closing within 2 seconds is cut. A refused upgrade is
answered 503 at `max_connections` or `max_pending`, and 429 at
`max_connections_per_ip`. On the private side, at most 64 sockets are
open at once, and a keep-alive socket idle for 30 seconds is closed.

### Client addresses and trusted proxies

Per-IP limits count the client's address. Behind a proxy, every
connection comes from the proxy: list it in `trusted_proxies` (IPs, CIDRs
or `PRIVATE_SUBNETS`), and the server reads `X-Forwarded-For` from right to
left up to the first untrusted address; no other forwarding header is
read. A peer on a unix socket is a trusted proxy. When the server listens
on a loopback address and `trusted_proxies` is empty, every connection
comes from a local proxy: per-IP limits are turned off, with a warning,
rather than counting every user as one. An IPv6 client is counted by its
/64. A trusted proxy that names no client counts its connections under
its own address, and a unix socket proxy under one shared budget: make the
proxy send `X-Forwarded-For` for per-IP limits to tell clients apart.

`trusted_proxies` here is the hub's, not Symfony's
`framework.trusted_proxies`, which the application still needs for the
token's `origin`.

### The publish channel

The application publishes on the private side, which the network between
them may carry in clear. Every request but `/health` is signed:

```text
X-Collaborate-Signature: t=<unix time>,n=<random nonce>,s=<hex HMAC-SHA256>
```

The HMAC, with the publish key, covers the time, the nonce, the method,
the path and query, and the SHA-256 of the body. A signature more than 60
seconds old or ahead, or whose nonce the server has already seen, is
refused with 401.

| Request | Answer |
|---|---|
| `POST /publish`, body `{"notices":[{"topics":[…],"data":"…"}]}`, 100 notices at most | 204; 400 for a malformed body; 413 over 1 MiB |
| `GET /subscriptions?topic=<topic>` | `{"subscriptions":[{"active":true}]}` when a connection with a valid token listens to the topic, `{"subscriptions":[]}` otherwise; it names nobody |
| `GET /health` | 200 `ok`, unauthenticated |

Keep `publish_listen` on loopback or a unix socket. When the application
runs elsewhere (another container, another host), publish over a private
network only: a Compose network marked `internal`, a Kubernetes
NetworkPolicy, a VPN; and over TLS (`https://` `publish_url` through a
TLS-terminating proxy) when that network is not trusted. Never publish
the port to the internet.

### Rotating the secret

1. Set `previous_secret` to the current secret and `secret` to a new one.
2. Restart the server, then deploy the application.
3. After `subscription_ttl` seconds (and 60), remove `previous_secret`
   and restart the server.

The server verifies tokens and publish signatures with both keys; the
application signs with `secret` alone.

### Logging

The server logs connection ids, counts and close codes on the
`collaborate` channel, never tokens, secrets or payloads. The hot path
logs at debug level only; counters are logged periodically.

### One hub per application

Run one server per application. Two applications that share a secret and
a `topic_prefix` could use each other's tokens: give each its own secret
and its own hub.

## Deployment

The browser side goes behind the application's reverse proxy, on the
`public_url` path; the private side stays private. Whatever the proxy, it
must:

- forward the upgrade (`Upgrade` and `Connection` headers) to the server
  over HTTP/1.1;
- forward `Origin` untouched;
- keep an idle connection open longer than `ping_interval` (25 seconds);
- for the application itself, pass the browser's host and scheme
  (`Host`, `X-Forwarded-Proto`), which the token's `origin` is made of.

Copy-ready files are in
[`docs/deploy/`](https://github.com/janalis/collaborate/tree/main/docs/deploy):

| Stack | File |
|---|---|
| nginx, TCP upstream | [`nginx.conf`](https://github.com/janalis/collaborate/blob/main/docs/deploy/nginx.conf) |
| nginx, unix socket upstream | [`nginx-unix.conf`](https://github.com/janalis/collaborate/blob/main/docs/deploy/nginx-unix.conf) |
| Apache 2.4.47+ | [`apache.conf`](https://github.com/janalis/collaborate/blob/main/docs/deploy/apache.conf) |
| Caddy or FrankenPHP, without Mercure | [`Caddyfile`](https://github.com/janalis/collaborate/blob/main/docs/deploy/Caddyfile) |
| Traefik (Docker labels) | [`traefik.compose.yaml`](https://github.com/janalis/collaborate/blob/main/docs/deploy/traefik.compose.yaml) |
| HAProxy | [`haproxy.cfg`](https://github.com/janalis/collaborate/blob/main/docs/deploy/haproxy.cfg) |
| systemd | [`collaborate-hub.service`](https://github.com/janalis/collaborate/blob/main/docs/deploy/collaborate-hub.service) |
| Supervisor | [`supervisor.conf`](https://github.com/janalis/collaborate/blob/main/docs/deploy/supervisor.conf) |
| Docker Compose | [`compose.yaml`](https://github.com/janalis/collaborate/blob/main/docs/deploy/compose.yaml) |
| Kubernetes | [`kubernetes/`](https://github.com/janalis/collaborate/tree/main/docs/deploy/kubernetes): `hub.yaml` (Deployment, Service, NetworkPolicy), `httproute.yaml` (Gateway API), `ingress-nginx.yaml` (legacy) |
| Symfony CLI | [`symfony.local.yaml`](https://github.com/janalis/collaborate/blob/main/docs/deploy/symfony.local.yaml) |
| Upsun, Platform.sh | [`upsun.yaml`](https://github.com/janalis/collaborate/blob/main/docs/deploy/upsun.yaml) |

### nginx

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    # … the application's server block

    location = /collaborate-hub {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 75s;
        proxy_send_timeout 75s;
    }
}
```

- `location =` matches the path exactly, before any `location ~ \.php$`.
- `proxy_pass` has **no URI** (no path, not even `/`), so the request's
  path reaches the server unchanged. With a URI, nginx replaces the
  matched part: `proxy_pass http://127.0.0.1:8090/;` sends `/`, which the
  server answers with 404 unless `path` says `/`.
- Both timeouts default to 60 seconds; the server's pings keep the
  connection busy every 25. Raise both if you raise `ping_interval`.
- An upgraded connection is not buffered: `proxy_buffering` changes
  nothing here.

### Timeouts and load balancers

The default `ping_interval` (25 seconds) stays under the idle timeouts of
common fronts:

| Front | Idle timeout | |
|---|---|---|
| nginx | 60 s (`proxy_read_timeout`, `proxy_send_timeout`) | Fine |
| AWS Application Load Balancer | 60 s, configurable | Fine |
| Cloudflare | 100 s | Fine; turn WebSockets on for the zone. Cloudflare restarts its edge now and then, closing sockets: pages reconnect. |
| Heroku router | 55 s | Fine |
| Google Cloud external Application Load Balancer | The backend service's `timeoutSec`, 30 s by default, caps a WebSocket's **lifetime**, whatever its traffic | Raise it: `gcloud compute backend-services update <name> --timeout=3600` |
| Azure Application Gateway | May cap connections (backend settings) | Check its backend settings' timeouts against your pages' lifetime |

A front that caps a socket's lifetime does no harm beyond a reconnection
each time: pages reconnect with a random delay and catch up.

### HTTP/2 and HTTP/3 fronts

Browsers open WebSockets over HTTP/1.1, unless the front offers
WebSockets over HTTP/2 (RFC 8441), as Caddy and HAProxy do. Either way,
the front talks to the server over HTTP/1.1 with an upgrade: nothing to
configure beyond the headers above. Fronts that terminate HTTP/2 must
still forward `Origin`.

### Restarts and reconnection storms

A restart of the server makes every page call the session endpoint
again. Pages spread their reconnections over a random delay, but size the
application for a burst of `max_connections` session requests within
seconds after a restart, or restart outside peak hours.

### Application runtimes

The application publishes with Symfony's HTTP client, at most once per
request, after the response is sent (`kernel.terminate`). PHP-FPM opens a
connection per request; FrankenPHP's worker mode and RoadRunner keep it
alive between requests. A unix socket (`publish_socket`) skips TCP
altogether.

### Heroku and Clever Cloud

Run the server as an application of its own, from the same code, one
instance only:

```text
web: php bin/console collaborate:hub:serve --listen=0.0.0.0:$PORT
```

Its `public_url` is then that application's `wss://` URL. The private
side needs a private network between the two applications: Heroku Private
Spaces (DNS service discovery), Clever Cloud network groups; on Heroku's
Common Runtime, dynos cannot reach each other. These setups are not tested
here: check that your platform lets the application reach the hub's
private port, and that only the application can.

## Limits

- **One process.** The server holds every connection in memory; the
  application publishes to that one process. Run exactly one instance per
  application: no replicas, and a `Recreate` (stop, then start) update
  strategy.
- **No history.** The server keeps no notice. A page that was not
  connected misses it and catches up on its own, as with Mercure: drafts
  replay from the journal, saved views refresh once their stream comes
  back, and the bell asks for its list again.
- **Memory.** Count about 45 KiB per connection, plus what is
  queued for slow clients (up to `max_buffer` each, before they are
  dropped). The server warns at startup when `memory_limit` is too small
  for `max_connections`; 10,000 connections need about 450 MiB.
- **File descriptors.** Each connection holds one. Raise the process's
  limit (`LimitNOFILE=` in systemd, `ulimit -n`) above `max_connections`.
  The default `stream_select()` loop handles 1024 at most (`FD_SETSIZE`):
  past 1000 connections, install `ext-ev` or `ext-event`.
- **Slow clients** are dropped when more than `max_buffer` bytes are
  queued for them, or after staying congested too long; they reconnect and
  catch up.

## Performance

Each notice is encoded into a frame once and the same bytes are written
to every subscriber of its topics, each connection once however many of
the notice's topics it holds. Expiry is an integer comparison at send
time; one timer handles pings, deadlines and expiries for all
connections; nothing is compressed.

The bench, `tests/bench/hub-fanout.php` (not run in CI), measures the
latency from publish to receipt. Clients run out of process
(`ratchet/pawl`); notices are published open-loop through the real signed
endpoint, each carrying its send time; it reports latency percentiles,
lost notices, dropped clients, the hub's peak memory (VmHWM), CPU time and
event loop. Its header lists the scenarios and options. On Linux, raise
the descriptor limit first (`ulimit -n 65536`).

Measured with PHP 8.4 and `ext-ev`, in Docker Desktop's Linux VM on a
16-core development machine, the hub on one core and the clients and
publisher sharing the others, over a unix socket, 10-second warm-up then
60 seconds measured. Every notice reached every client that reads in
every run, and no client was dropped:

| Scenario | p50 | p99 | p99.9 | Hub CPU | Hub RSS |
|---|---|---|---|---|---|
| 2,000 connections in 200 rooms, 500 notices/s | 1.7 ms | 19 ms | 113 ms | 20% | 130 MiB |
| 10,000 connections in 1,000 rooms, 2,000 notices/s | 5.1 ms | 387 ms | 553 ms | 51% | 479 MiB |
| 2,000 connections in one room, 50 notices/s (100,000 deliveries/s) | 32 ms | 72 ms | 106 ms | 85% | 126 MiB |
| 2,000 connections in 200 rooms, 500 notices/s, 5% of clients stalled | 1.5 ms | 33 ms | 60 ms | 25% | 123 MiB |
| Cursor moves: 200 pages in rooms of 5, 20 moves/s each, send to peer | 1.2 ms | 6.0 ms | 25 ms | 42% | 44 MiB |

Hub CPU is the share of one core over the run. The tails come partly
from the VM and from clients running on the same machine; a dedicated
Linux host does better. On macOS with the `stream_select()` loop,
600 connections in 60 rooms at 500 notices/s measured 0.58 ms at p50 and
2.3 ms at p99.

What this means for sizing:

- One hub serves thousands of connections across many rooms on less
  than a core, at about 45 KiB of memory per connection.
- Cost grows with deliveries (notices × subscribers), not with
  connections: a single room of 2,000 readers at 50 notices a second
  nearly fills a core, and latency rises as it does.
- A stalled client costs the others nothing: it is dropped once
  `max_buffer` fills, and the clients that read keep their latency.

Run the bench on your own hardware before sizing a deployment.

## Troubleshooting

**The console says "the WebSocket hub could not be reached several times
in a row".** The page's handshakes fail before the server answers: most
often a proxy that does not forward the upgrade. Check that the proxy
sends `Upgrade` and `Connection: upgrade` over HTTP/1.1, and that the
path reaches the server unchanged. A handshake by hand should answer
`101 Switching Protocols`:

```sh
curl -i -N --http1.1 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Origin: https://admin.example.com' \
  https://admin.example.com/collaborate-hub
```

A 404 is a path the server does not upgrade (the `proxy_pass` URI
caveat above, or `path`); a 403 a missing, `null` or refused `Origin`; a
503 a server at its limits or shutting down; a 502 or 504 a server that is
down or a wrong upstream address.

**Pages reconnect over and over with 4401.** The server refuses the
token. In order of likelihood:

- the page's origin is not what the application saw: behind a TLS proxy,
  Symfony sees `http://` unless `framework.trusted_proxies` and
  `X-Forwarded-Proto` are set, and the token names `http://…` while the
  browser sends `Origin: https://…`; a proxy that rewrites `Host` for the
  application does the same;
- the application and the server read a different `secret` or
  `public_url` (another `.env`, a server not restarted since a change);
- the clocks of the application's and the server's hosts differ by more
  than a few seconds.

**Mixed content.** A page over HTTPS cannot open `ws://`: use a relative
`public_url`, or `wss://`.

**Blocked by the content security policy.** Add the hub's origin to
`connect-src`, as `wss://…`.

**Sockets close every 30 or 60 seconds.** A front caps idle time or
lifetime: raise its timeout (see
[Timeouts and load balancers](#timeouts-and-load-balancers)). Every
reconnection costs a session request.

**Connected, but nothing arrives.** The application cannot publish:
look for hub failures on the `collaborate` channel, which pause
publishing for `publish_pause` seconds. Check the private side from the
application's host: `curl http://127.0.0.1:8091/health`.
