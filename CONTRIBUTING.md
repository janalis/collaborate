# Contributing to collaborate

Thanks for your interest! The full contributor guide is at
**<https://janalis.github.io/collaborate/contributing/>** (sources in
[`docs/contributing/`](docs/contributing/)).

## Reporting a problem

Open an issue and pick a template. Include:

- what each editor did, in order, and what each page showed;
- the smallest form type, entity and page that show the problem, and your
  `collaborate` configuration;
- the versions of the bundle, PHP, Symfony, EasyAdmin (if used), CKEditor,
  the database server, and the hub (Mercure and its version, or the
  WebSocket server);
- errors from the browser console and from the `collaborate` log channel.

A lost edit, an outside save silently overwritten, or content reaching the
hub are treated as bugs of the highest priority. Report security
issues privately, through the repository's **Security → Report a
vulnerability**.

## Before opening a pull request

```sh
make install           # Composer, npm and the test applications' assets
npm ci --prefix docs   # once: markdownlint (and the docs site)
make verify            # composer validate, mago fmt/lint/analyze, PHPUnit, Vitest
```

- PHPUnit's functional suites need a MariaDB or MySQL 8 server; see
  [Testing](https://janalis.github.io/collaborate/contributing/testing).
- Changed behavior comes with tests: PHPUnit for PHP, Vitest for the
  browser modules, Playwright for what editors see.
- Names shared by PHP, Twig and JavaScript are listed in
  [`docs/internals/contract.md`](docs/internals/contract.md); keep it
  current.
- User-visible changes get a line under `## [Unreleased]` in
  `CHANGELOG.md`.

See [Make targets](https://janalis.github.io/collaborate/contributing/#make-targets)
for the full list.
