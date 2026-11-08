# Notifications

A bell on every page tells a user what concerns them: a count of what they
have not read, the latest notifications, and a page of them all. A page
hears of a new one at once, over the stream it already holds; a browser
with no page of the application open is sent a system notification
through [web push](#web-push). Each notification belongs to a
[topic](#topics) its reader may unsubscribe from. Notifications are off
until `notifications` is configured, and every part of them is optional.

## Setting them up

```yaml
# config/packages/collaborate.yaml
collaborate:
    notifications:
        role: ROLE_USER             # null: collaborate.role
        topics:
            shares:
                label: 'Shares'
                description: 'A record is shared with you.'
            announcements:
                label: 'Announcements'
                mandatory: true      # nobody unsubscribes
        types:
            record_shared:
                topic: shares
                # A translation key; a % is written %% in the configuration.
                sentence: '%%name%% shared “%%record%%” with you'
                label: 'Shared records'   # the filter of the page
                icon: 'fa fa-share'       # classes of an icon, or null
```

```php
// config/routes/collaborate.php: the routes, under the prefix the host wants
$routes->import('@CollaborateBundle/config/routes/notifications.php');
```

```twig
{# in the layout, where the bell goes #}
{{ collaborate_notifications() }}
{{ collaborate_layer() }}
```

```js
import '@janalis/collaborate/notifications/bell.js';
// on the page of all notifications, and on the page of the topics:
import '@janalis/collaborate/notifications/page.js';
import '@janalis/collaborate/notifications/topics.js';
```

```scss
@use 'collaborate/assets/scss/notifications';
```

The three tables are mapped only while notifications are on: create
`collaborate_notification`, `collaborate_notification_preference` and
`collaborate_push_subscription` with a migration (their SQL is in
`docs/schema.sql`). A recipient is the id the
[collaborator provider](./identity-and-access) gives, with no foreign key:
an application may add one to its user table, `ON DELETE CASCADE`, or call
`NotificationRepository::forget($id)` when it removes a user.

## Telling someone

```php
public function __construct(private Notifier $notifier) {}

$this->notifier->notify($user, 'record_shared', ['%name%' => 'Bob', '%record%' => 'Song'], '/library/music');
$this->notifier->notifyAll($administrators, 'record_shared', $parameters);
```

`notify()` takes a user or their id, the name of a configured type (any
other throws), the values of the placeholders of its sentence and where a
click leads. It saves the row (flushing the entity manager, so do it once
what it tells is saved), then tells the pages of its reader through the hub
that their list changed. The hub carries no content: the pages ask for
their list again. A user who left the topic of the type is told nothing,
and nothing is flushed for them: flush what you save yourself rather than
count on `notify()` to do it. With a bus, nothing is flushed either.

## The bell and the pages

`collaborate_notifications()` renders nothing for a visitor nor for a user
without `notifications.role`. Its menu lists the
[`latest`](./configuration#notifications) (20) notifications; the count
stops at `max_count` (99+). Following one marks it read first. The page of
them all (`<prefix>/notifications/all`) lists `page_size` (30) at a time as
its reader scrolls, filtered by unread, type or topic, and reads, unreads
or clears one or all of them. A host whose layout fills another block than
`body` names its own template in `page_template` and `topics_template`,
including `@JanalisCollaborate/notifications/_page.html.twig` and
`_topics.html.twig` where its layout wants them.

The scripts dispatch two events on `document` for the host:

- `collaborate:notification` (`{id, text, url}`), once per new notification,
  whether a page heard of it through the hub or from the service worker: a
  host shows a toast or announces it.
- `collaborate:notifications-changed` (`{unread, total, origin, heard}`),
  after any change.

The styles are the module's custom properties
(`--collaborate-notifications-accent`, `-bg`, `-fg`, `-muted`, `-border`,
`-badge-bg`, `-badge-fg`, `-radius`, `-shadow`, `-font`), which a host maps
onto its own colours; they follow the colour scheme of the system by
default. The accent is both text on `-bg` and the background of `-bg` text
(the active filter): give it a contrast of 4.5:1 or more with `-bg` in both
colour schemes.

## The stream of a page

A page hears of its notifications over the one subscription its layer
already opens. A page with no cursor room (a page the
[place provider](./cursors-and-presence) puts in no place, or a user who
holds `notifications.role` but not `collaborate.role`) is given a **bare
grant**: its stream carries the notifications of its user alone, no cursors
nor saved views. A host that keeps notifications off sees no difference. A
host whose hub may be missing names a
`Janalis\Collaborate\Notification\StreamGuardInterface` in
`notifications.stream_guard`: while it says no, no bare grant is given, and
the bell shows what was told before the page loaded.

## Topics

Every type belongs to a topic, which its reader subscribes to or
unsubscribes from on `<prefix>/notifications/topics`. Unsubscribed, a user
is told nothing of the topic: no row, no bell, no push. A topic is on by
default (`default: true`); a `mandatory` one cannot be left. Choices are
stored only when made, and changing a topic needs the CSRF token of the
page: a forged unsubscribe would silence its user.

## Messenger

With `symfony/messenger` installed and `notifications.bus` naming a bus,
`notify()` dispatches a `SendNotification` per recipient instead of writing.
Route it like any message:

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Janalis\Collaborate\Notification\Messenger\SendNotification: async
            Janalis\Collaborate\Notification\Messenger\SendWebPush: async
```

A message holds scalars alone, and is dated when it was sent. Its key makes
handling it idempotent: a redelivered message writes no second row. Each
browser to push to is a `SendWebPush` of its own, retried by Messenger. What
a worker or a console command publishes is sent once its message or command
is done; the hub (with the WebSocket hub, its `publish_url`) must be
reachable from the worker.

## Web push

When no page of the application is open, a browser is sent a system
notification. The bell of a browser that can shows **Notify me on this
device**, which asks the browser's permission only when it is clicked.

```sh
composer require minishlink/web-push:^11.0 nyholm/psr7
php bin/console collaborate:push:setup --subject=mailto:ops@example.org
```

The command makes a VAPID key pair and writes it, with its subject, into
`.env.local` (`--env-file` names another; `--vault` seals them in the
secrets vault of the environment instead; `--format=env|json` prints them
only). It refuses to replace configured keys without `--force`: browsers
subscribed with the former key are pushed to no more, so `--force` also
removes their subscriptions, unless `--keep-subscriptions`. It prints the
configuration that reads them when web push is off:

```yaml
collaborate:
    notifications:
        web_push:
            enabled: true
            public_key: '%env(WEB_PUSH_PUBLIC_KEY)%'
            private_key: '%env(WEB_PUSH_PRIVATE_KEY)%'
            subject: '%env(WEB_PUSH_SUBJECT)%'
```

`collaborate:push:setup --check` checks the configured pair and subject,
and exits non-zero on a problem: a deployment calls it.

**In the page its reader looks at, else in the system.** The service
worker (`<prefix>/notifications/push/worker.js`, allowed to control the
whole origin) shows a push in the page of the application that has the
focus (its bell tells the host, which shows a toast), and as a system
notification whenever none has it: every page closed, hidden, or behind
another window. The pages left open count it through the hub without
saying it, so it is never shown twice. A click on a system notification
marks it read and opens where it leads. `skip_online` (off) pushes nothing
to a user whose page holds the stream of their notifications, focused or
not, asking the hub: Mercure's subscriptions API (`subscriptions` on in
the hub's configuration), or the WebSocket hub's own, which needs no
setting.

What a push says is the sentence of the notification, translated in the
language of its reader (a
`Janalis\Collaborate\Notification\RecipientLocaleProviderInterface` named
in `notifications.locales`; the default locale otherwise), encrypted to the
browser, through the push service of its vendor. `payload: none` sends an
empty push instead, and the worker says a generic sentence.

- The server reaches the push services (Google, Mozilla, Apple) over HTTPS.
- On iOS and iPadOS, only a web app added to the home screen receives
  pushes: the bundle ships no manifest.
- A host with a service worker of its own names it in `service_worker`, and
  `importScripts()` the bundle's after setting
  `self.collaborateNotifications` as the bundle's route does.
- A browser whose subscription its push service says is gone (404, 410) is
  forgotten; a subscription is re-attached to whoever signs in on the
  browser, and refreshed when the keys change.
- Subscribing needs the CSRF token of the page: a forged subscription would
  hand the notifications of its user to an endpoint of the forger's.
- The server posts to the endpoint a browser hands it, so only an endpoint
  of a push service is accepted (`endpoint_hosts`: Google's, Mozilla's,
  Apple's and Microsoft's by default, their subdomains included), https on
  the default port; a host behind a push service of its own adds its host.
