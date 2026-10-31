# Testing

The bundle is tested at four levels: PHPUnit for the PHP code, Vitest for
the browser modules, PHPUnit again for the bundle inside two small
applications, and Playwright for real browsers on those applications.

## PHPUnit

`phpunit.xml.dist` defines these suites:

| Suite | Directory | What it covers |
|---|---|---|
| `unit` | `tests/Unit` | Every class on its own: grants, topics, controllers, the form extension, the EasyAdmin and Gedmo adapters, the configuration tree. |
| `functional` | `tests/Functional` | A test kernel (`tests/Fixtures`) with a real MariaDB: the journal's SQL, locks and limits, the entity mapping against `docs/schema.sql`, Mercure tokens, page renewal. |
| `integration` | `tests/Integration` | The bundle inside the test applications of `tests/Application` (with and without EasyAdmin), over HTTP: what a page renders, what its scripts ask the endpoints, what a save publishes. |
| `tools` | `tests/Tools` | The release tool, `tools/relprep.php`. |

The functional and integration suites need a MariaDB (or MySQL 8) server.
They read its URL from `COLLABORATE_DATABASE_URL`, whose default in
`phpunit.xml.dist` is
`mysql://root@127.0.0.1:3306/collaborate_test?serverVersion=10.11.0-MariaDB`;
the tests create their own tables. For example:

```sh
docker run -d --name collaborate-db -p 3306:3306 \
  -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=collaborate_test mariadb:11.4
make test                    # every suite
make test-unit               # no database needed
make test PHPUNIT_ARGS='--filter DraftJournalTest'
COLLABORATE_DATABASE_URL='mysql://user:pass@127.0.0.1:3306/collab?serverVersion=8.0.40' make test
```

The integration suite creates a database per test application
(`collaborate_<app>_test`), so its user needs the right to create
`collaborate_*` databases.

Every test declares what it covers (`#[CoversClass]`), and PHPUnit fails on
warnings, risky tests and deprecations. With PCOV, pass
`-d pcov.directory=.` so `tools/` is measured along with `src/`:

```sh
php -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit --coverage-text
make coverage                # PHPUnit (HTML in var/coverage) and Vitest
```

## Mutation testing

`make infection` runs [Infection](https://infection.github.io) over `src/`
with the configuration in `infection.json5.dist` (copy it to the ignored
`infection.json5` to change it locally). Every PHPUnit suite takes part
(unit, functional and integration), so it needs the database too. Reports go
to `var/infection/`; pass Infection options with `ARGS`, for example
`make infection ARGS="--filter=src/Draft"`.

CI runs the PHP suites on PHP 8.4 and 8.5 with Symfony 7.4 and 8.1, against
MariaDB 11.4, and once against MariaDB 10.11.

## Vitest

The browser modules are tested with Vitest in jsdom, under
`tests/vitest/`, which mirrors `assets/`. The draft tests run real
CKEditor models: concurrency, undo, replay, source edits and field
adapters. The host's bare imports (`ckeditor5`,
`@microsoft/fetch-event-source`, `bootstrap/js/src/modal.js`) resolve from
`node_modules`.

```sh
make test-js             # vitest run (npm test)
npm run test:coverage    # with V8 coverage, in var/coverage-vitest
make lint-js             # Biome (npm run lint)
```

## The test applications

`tests/Application` holds two applications built on one shared kernel
(`Shared/AppKernel.php`), the same `Article` and `Tag` entities, forms and
CKEditor wiring:

| Application | Kernel | What it shows |
|---|---|---|
| `Plain` | `PlainKernel` | A host without EasyAdmin: its own controller, form context (`PlainFormContext`), templates and collection markup. |
| `EasyAdmin` | `EasyAdminKernel` | A dashboard and CRUD controllers, with the layout override. |

Both sign users in by user name without a password (`alice`, `bob`). Their
console recreates an application's database and loads its fixtures:

```sh
make app-setup
```

It builds the stylesheets and an ES module copy of
`@microsoft/fetch-event-source` (`tests/Application/bin/setup-assets.mjs`),
installs EasyAdmin's public files, and recreates both applications'
databases with their fixtures
(`COLLABORATE_APP=plain|easyadmin APP_ENV=e2e php tests/Application/bin/console.php app:setup`).

## Playwright

`tests/playwright` drives both applications in Chromium with two signed-in
editors at once: presence, pointers and carets, shared text and rich text,
saves, conflicts and the saved-view refresh. `playwright.config.js` starts
FrankenPHP with `tests/Application/Caddyfile`, which serves the plain
application on port 8710 and the EasyAdmin one on 8720 (`PLAIN_PORT`,
`EASYADMIN_PORT`), each with its own Mercure hub on its own origin.

```sh
npx playwright install chromium
make e2e                              # app-setup, then both projects
make e2e ARGS="--project=plain"       # one application
```

It needs `frankenphp` on the `PATH` (or `FRANKENPHP=/path/to/frankenphp`)
and `COLLABORATE_DATABASE_URL`.
Scenarios run one at a time, since presence is shared by everyone on a
page.
