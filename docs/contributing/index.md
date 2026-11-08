# Contributing

Thanks for helping! The most useful bug reports name what each editor did,
in order, and what each page showed, with the smallest form and page that
show it. Please
[open an issue](https://github.com/janalis/collaborate/issues/new/choose);
the templates ask for the versions involved. Pull requests get a checklist
that matches the ground rules below.

## Setup

You need PHP 8.4 or later with `pdo_mysql`, Composer, Node 20 or later, a
MariaDB (or MySQL 8) server for the functional tests, and, for the browser
tests, [FrankenPHP](https://frankenphp.dev).

```sh
git clone https://github.com/janalis/collaborate
cd collaborate
make install           # Composer, npm and the test applications' assets
npm ci --prefix docs   # markdownlint and the documentation site
make verify
```

See [Testing](./testing) for the database and the test applications.

## Make targets

`make help` lists them all.

| Target | What it does |
|---|---|
| `make install` | `composer install`, `npm ci`, and the test applications' assets. |
| `make verify` | **Definition of done**: `validate`, `fmt-check`, `lint`, `analyze`, `test` and `test-js`. |
| `make validate` | `composer validate --strict`. |
| `make fmt` / `make fmt-check` | Apply Mago's formatting and Biome's fixes / check Mago's formatting. |
| `make lint` | Mago's linter, Biome, markdownlint, editorconfig-checker and actionlint (see [Code style](#code-style)). Set a tool's variable empty to skip it (`make lint MDLINT=`). |
| `make analyze` | Mago's static analysis. |
| `make test` | Every PHPUnit suite (`PHPUNIT_ARGS="--filter …"`); `make test-unit`, `make test-functional` and `make test-integration` run one. See [Testing](./testing). |
| `make test-js` / `make lint-js` | Vitest / Biome and TypeScript. |
| `make test-tools` | The release tool's tests (no database needed). |
| `make coverage` | PHPUnit with PCOV coverage (HTML in `var/coverage`) and Vitest with coverage. |
| `make infection` | Mutation testing of `src/` against every PHPUnit suite, reports in `var/infection/`. |
| `make app-setup` / `make e2e` | Prepare the test applications, then run the Playwright scenarios (`ARGS="--project=plain"`). |
| `make docs` / `make docs-dev` | Build or serve this site. |
| `make release-notes` | Print the notes the next release would publish (`BUMP=patch`, `minor`, `major` or `X.Y.Z`), without touching `CHANGELOG.md`. |
| `make clean` | Remove caches, reports and generated test application files. |

The database tests read `COLLABORATE_DATABASE_URL` from the environment, or
from a local `phpunit.xml` when you keep one.

## Code style

| Files | Tool | Rules |
|---|---|---|
| PHP | [Mago](https://mago.carthage.software) | `mago.toml`: formatting (120 columns), linting with the Symfony and PHPUnit integrations, and static analysis over `src`, `tests`, `config` and `tools`. |
| JavaScript | [Biome](https://biomejs.dev) | `biome.json`: two spaces, single quotes, semicolons, 120 columns. |
| JavaScript types | [TypeScript](https://www.typescriptlang.org) | `tsconfig.json`: the modules that start with `// @ts-check` are type-checked from their JSDoc, the others by their tests alone; `typings/` describes what the DOM library lacks. |
| Markdown | [markdownlint](https://github.com/DavidAnson/markdownlint) | `.markdownlint-cli2.jsonc`: the default rules, without line length, inline HTML and code-span spacing checks. |
| All text files | [editorconfig-checker](https://github.com/editorconfig-checker/editorconfig-checker) | `.editorconfig`: UTF-8, LF, final newline, no trailing whitespace; four spaces in PHP and Twig, two in JavaScript, Sass, YAML and Markdown, tabs in `Makefile` and `Caddyfile`. |
| GitHub workflows | [actionlint](https://github.com/rhysd/actionlint) | Workflow syntax and expressions. Third-party actions are pinned to a commit. |

- Fix findings rather than silencing them; no new baseline entries.
- Prose uses US English (`behavior`, `color`); identifiers keep their own
  spelling (`colors`).
- In PHP, import classes with `use`; in JavaScript, relative imports carry
  their `.js` extension, since browsers load the modules as they are.
- The bundle's JavaScript ships as written: no bundler, no transpiler, no
  polyfills. Modules use only the bare imports the host's import map
  provides (`ckeditor5`, `bootstrap/js/src/modal.js`, and
  `@microsoft/fetch-event-source`, which `hub/mercure.js` alone imports,
  on demand, so a host on the WebSocket hub need not map it).

## Ground rules

- **Tests come with the change.** Every changed PHP behavior has PHPUnit
  coverage and every changed module Vitest coverage; a change to what
  editors see gets a Playwright scenario.
- **The contract is shared.** Routes, request attributes, headers, CSRF
  ids, markup attributes, storage keys and events are read by PHP, Twig and
  JavaScript at once. Change them together and update
  [`docs/internals/contract.md`](https://github.com/janalis/collaborate/blob/main/docs/internals/contract.md).
- **Content never travels through the hub.** Notifications carry ids,
  kinds and revisions; content is read back over authenticated requests.
- **Drafts lose nothing.** A change that could drop an accepted edit, or
  overwrite an outside save silently, is a bug.
- **Configuration and docs move together.** A new option gets its
  `->info()` in the configuration tree, a row in
  [Configuration](/guide/configuration), and a changelog line.
- **Changelog**: record every user-visible change under `## [Unreleased]`
  in `CHANGELOG.md`.
- **Documentation** describes current behavior, in the present tense.

## Where to go next

- [Architecture](./architecture): what lives where, and a shared edit end
  to end.
- [Testing](./testing): the suites, the database and the test applications.
- [Releasing](./releasing): cutting a version and publishing the site.
