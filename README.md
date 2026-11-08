<p align="center">
  <img src="docs/public/logo.svg" alt="collaborate" width="160">
</p>

<h1 align="center">collaborate</h1>

<p align="center">
  <strong>Live collaboration for Symfony admin pages.</strong><br>
  Shared form drafts · presence and cursors · saved-view refresh · notifications · over Mercure or WebSockets, no Node service
</p>

<p align="center">
  <a href="https://github.com/janalis/collaborate/actions/workflows/ci.yml"><img src="https://github.com/janalis/collaborate/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://janalis.github.io/collaborate/"><img src="https://github.com/janalis/collaborate/actions/workflows/docs.yml/badge.svg" alt="Docs"></a>
  <a href="https://github.com/janalis/collaborate/releases"><img src="https://img.shields.io/github/v/release/janalis/collaborate?sort=semver" alt="Latest release"></a>
  <a href="https://packagist.org/packages/janalis/collaborate"><img src="https://img.shields.io/packagist/v/janalis/collaborate" alt="Packagist"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/janalis/collaborate" alt="MIT license"></a>
</p>

<p align="center">
  <a href="https://janalis.github.io/collaborate/"><strong>Documentation</strong></a> ·
  <a href="https://janalis.github.io/collaborate/guide/getting-started">Getting started</a> ·
  <a href="https://janalis.github.io/collaborate/guide/configuration">Configuration</a> ·
  <a href="https://janalis.github.io/collaborate/guide/rich-text">Rich text</a> ·
  <a href="https://janalis.github.io/collaborate/guide/easyadmin">EasyAdmin</a>
</p>

---

`janalis/collaborate` is a Symfony bundle that lets several people work on
the same admin page at once:

- **Shared drafts.** Editors of the same record share one durable draft.
  Text, rich text (CKEditor 5's own operations and transformations, undo
  included), choices and collections stay in sync. Save goes through the
  form's usual validation and persistence; a conflicting save elsewhere opens
  a review, and Discard resets the draft for everyone.
- **Presence and cursors.** The header shows who else is on the page; their
  pointers, carets, selections and the field each one is in are drawn in
  their color.
- **Saved-view refresh.** Lists and detail pages reload when a save
  elsewhere changes what they show.
- **Notifications.** A bell on every page, topics each user subscribes to,
  Messenger delivery, and web push when no page of the application is open.

It runs on PHP and a hub: a [Mercure](https://mercure.rocks) hub
(FrankenPHP's built-in hub works), or the WebSocket server the bundle ships
(`bin/console collaborate:hub:serve`, on ReactPHP). Draft content lives in
MariaDB or MySQL; the hub only carries invalidations and presence, never
form content. The bundle is a
framework-level core plus an EasyAdmin adapter: without EasyAdmin, a small
form-context service is all a page needs.

## Install

```sh
composer require janalis/collaborate
```

Requirements: PHP 8.4+, Symfony 7.4 or 8, Doctrine ORM 3 on MariaDB or
MySQL 8, Symfony AssetMapper, and a hub: `symfony/mercure-bundle` and a
Mercure hub, or the bundled
[WebSocket server](https://janalis.github.io/collaborate/guide/websocket-hub)
(`react/http`, `react/socket`, `react/stream`, `ratchet/rfc6455`).
See [Installation](https://janalis.github.io/collaborate/guide/installation)
for the routes, the tables, the purge cron and the hub.

## Quick start

```php
// config/routes/collaborate.php
return static function (RoutingConfigurator $routes): void {
    $routes->import('@CollaborateBundle/config/routes.php')->prefix('/admin');
};
```

```yaml
# config/packages/collaborate.yaml
collaborate:
    drafts:
        enabled: true
        rich_text:
            block_prefixes: [rich_text]   # form types rendered as CKEditor fields
```

```twig
{# in the admin layout #}
{{ collaborate_presence() }}   {# where the avatars go, e.g. the header #}
{{ collaborate_layer() }}      {# once, before </body> #}
```

```js
// the page's script, before anything that wraps window.fetch
import '@janalis/collaborate/easyadmin.js';
```

With EasyAdmin, edit and new pages then share drafts. Rich-text editors get
the `Collaborate` CKEditor plugin and the draft's configuration from
`sessionFor(field)`; see
[Getting started](https://janalis.github.io/collaborate/guide/getting-started)
and [Rich text and scripts](https://janalis.github.io/collaborate/guide/rich-text).

## Configuration

Every option lives under the `collaborate` key, with defaults that work on a
standard admin: who a user is and what they may edit, the hub and its
topics, cursor timing, draft limits, compaction, purge and locks, and the
saved-view refresh. See
[Configuration](https://janalis.github.io/collaborate/guide/configuration)
for the full reference and
[Identity and access](https://janalis.github.io/collaborate/guide/identity-and-access)
for the two services most applications provide.

## Contributing

Contributions are welcome: bug reports with the smallest page or form that
shows the problem, fixes and tests. Read [CONTRIBUTING.md](CONTRIBUTING.md)
and the
[contributor guide](https://janalis.github.io/collaborate/contributing/).

```sh
make install        # Composer, npm and the test applications' assets
make verify         # composer validate, mago fmt/lint/analyze, PHPUnit, Vitest
```

## License

[MIT](LICENSE).
