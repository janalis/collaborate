# Installation

## 1. Require the bundle

```sh
composer require janalis/collaborate
```

Symfony Flex registers `Janalis\Collaborate\CollaborateBundle`. Without
Flex, add it to `config/bundles.php`:

```php
return [
    // …
    Janalis\Collaborate\CollaborateBundle::class => ['all' => true],
];
```

The bundle maps its Doctrine entities, its Twig namespace
(`@JanalisCollaborate`) and, when AssetMapper is installed, its `assets/`
directory as `@janalis/collaborate`. You do not configure any of these.

## 2. Import the routes

The bundle's routes go under a prefix of your choice, usually your admin's:

::: code-group

```php [config/routes/collaborate.php]
<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('@CollaborateBundle/config/routes.php')->prefix('/admin');
};
```

```yaml [config/routes/collaborate.yaml]
collaborate:
    resource: '@CollaborateBundle/config/routes.php'
    prefix: /admin
```

:::

This adds three `POST` routes:

| Route | Path | Purpose |
|---|---|---|
| `collaborate_session` | `<prefix>/cursors/session` | The page's subscriber JWT and its topics. |
| `collaborate_cursor_events` | `<prefix>/cursors/events` | Pointer, caret and presence events (404 when cursors are disabled). |
| `collaborate_draft` | `<prefix>/collaborate/{action}` | Draft requests; `action` is `join`, `replay`, `append`, `discard` or `reconcile`. |

The prefix may hold a locale (`/{_locale}/admin`): the locale does not
separate cursor rooms. The routes must sit behind the firewall that signs in
your collaborators, since every one of them requires the configured
[role](./configuration#top-level) and a valid session.

## 3. Create the tables

Shared drafts use three tables, mapped by the bundle's entities
`Janalis\Collaborate\Entity\{Draft,DraftField,DraftEvent}`:

| Table | Holds |
|---|---|
| `collaborate_draft` | One row per draft: its generation, revisions, size, created record and last saver. |
| `collaborate_draft_field` | One row per field: its schema, saved value and current state. |
| `collaborate_draft_event` | The journal: one row per accepted batch of changes. |

With DoctrineMigrationsBundle, generate a migration as usual:

```sh
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

[`docs/schema.sql`](https://github.com/janalis/collaborate/blob/main/docs/schema.sql)
holds the reference DDL if you write migrations by hand. The journal needs a
MariaDB or MySQL 8 connection (the `database_connection` service); any other
platform fails with a clear error on the first draft request.

You can skip this step if you only want cursors and presence: the tables
are used by shared drafts alone, which are off by default.

## 4. Schedule the purge

Every visit of a new-record page opens a draft, so the tables only stay
bounded with the purge running, once a day:

```sh
20 3 * * * cd /path/to/app && bin/console collaborate:purge --no-interaction
```

It deletes the drafts nobody wrote to for
[`drafts.purge.clean_days`](./configuration#drafts-purge) (7) that hold no
unsaved change or have created their record, and any draft idle for
`drafts.purge.unsaved_days` (90). A draft whose lock is held is in use and
skipped.

## 5. Set up the Mercure hub

The bundle publishes through `symfony/mercure-bundle` and mints subscriber
tokens with the hub's own token factory, so the hub needs a JWT secret (or
a factory) in its configuration:

```yaml
# config/packages/mercure.yaml
mercure:
    hubs:
        default:
            url: '%env(MERCURE_URL)%'
            public_url: '%env(MERCURE_PUBLIC_URL)%'
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
                publish: ['*']
```

The same secret signs the publisher and subscriber tokens, so the hub must
accept it for both. Recent versions of `symfony/mercure` speak the Mercure
protocol 1.0, whose tokens also need `iss`, `aud`, `sub` and `client_id`
claims (`jwt.claims` in the hub's configuration, with `aud` set to the hub
URL); follow the version of the library and of the hub you run.

Browsers subscribe with a bearer token in the `Authorization` header. Serve
the hub from the application's own origin (`/.well-known/mercure`) to avoid
CORS, or allow the application's origin on the hub.

The bundle publishes **private** updates and the hub needs no history: it
keeps nothing and every page catches up from the journal on its own.

### FrankenPHP or Caddy

FrankenPHP ships the Mercure module. A site block serving the application
and its hub on the same origin, with the protocol 1.0 issuer syntax the
bundle's own end-to-end applications use:

```text
example.com {
    root * /path/to/app/public
    encode zstd gzip

    mercure {
        transport local
        issuer my-app {
            publisher {
                jwt {env.MERCURE_JWT_SECRET} HS256
            }
            subscriber {
                jwt {env.MERCURE_JWT_SECRET} HS256
            }
        }
    }

    php_server
}
```

With this block, set `jwt.claims.iss` (and `sub`, `client_id`) to `my-app`
and `jwt.claims.aud` to the hub's URL in `mercure.yaml`. Older hubs take
`publisher_jwt` and `subscriber_jwt` directives instead of `issuer` blocks;
see the documentation of your hub version. Do not enable anonymous
subscribers: nothing the bundle publishes is meant for them.

## 6. Front-end dependencies

The bundle's JavaScript is a set of native ES modules under
`@janalis/collaborate/…`. Your import map must provide the bare imports it
uses:

| Import | Used by |
|---|---|
| `ckeditor5` | Drafts (every text field is a one-paragraph CKEditor model) and the rich-text plugin. |
| `@microsoft/fetch-event-source` | The page's Mercure stream (it sends the bearer token). |
| `bootstrap/js/src/modal.js` | The Discard and "use saved record" confirmations. Only this module, never the whole `bootstrap` package, so EasyAdmin's own Bootstrap is not registered twice. |

`ckeditor5` and `@microsoft/fetch-event-source` come from the registry like
any package (`bin/console importmap:require ckeditor5
@microsoft/fetch-event-source`). The modal is Bootstrap's *source* module,
which imports its siblings by relative path: serve Bootstrap's `js/src`
directory as an AssetMapper path and point the entry at it, for example:

```yaml
# config/packages/asset_mapper.yaml
framework:
    asset_mapper:
        paths:
            assets/: ''
            vendor/twbs/bootstrap/js/src: vendor/bootstrap   # or node_modules/bootstrap/js/src
```

```php
// importmap.php
return [
    // …
    'bootstrap/js/src/modal.js' => ['path' => 'vendor/bootstrap/modal.js'],
];
```

Every page that loads the entry point needs all three, even with drafts off:
the entry point loads the draft modules, which import `ckeditor5`.

The pinned `ckeditor5` version is part of every text field's schema (see
[Schema compatibility](./shared-drafts#schema-compatibility)). If your
CKEditor does not come from the import map, set
[`drafts.rich_text.ckeditor_version`](./configuration#drafts-rich-text).

Then wire the page: [Getting started](./getting-started).
