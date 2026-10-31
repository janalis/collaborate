# Releasing

Releases are cut from GitHub Actions in one click. Write user-visible
changes under `## [Unreleased]` in `CHANGELOG.md` as you go
([Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format, semantic
versioning).

## Cutting a release

Run **Actions → release → Run workflow** on `main` with a version: `X.Y.Z`,
or `patch` / `minor` / `major` to bump the latest `vX.Y.Z` tag (the first
release bumps `0.0.0`). Tick *dry run* to run everything without pushing or
publishing. The workflow (`.github/workflows/release.yml`):

1. runs `make install` and `make verify` against a MariaDB service;
2. runs `tools/relprep.php`, which turns `[Unreleased]` into
   `[X.Y.Z] - <date>`, opens a fresh empty `[Unreleased]` above it, updates
   the comparison links at the bottom of the changelog and extracts the
   release notes; it refuses an empty `[Unreleased]` section and a version
   that is not greater than the latest tag;
3. commits `Release vX.Y.Z`, creates the annotated tag `vX.Y.Z` and pushes
   both atomically to `main`;
4. creates the GitHub release from the tag with the extracted notes;
5. asks Packagist to update `janalis/collaborate` (when its secrets are
   set; Packagist's GitHub hook picks the tag up anyway);
6. rebuilds and deploys this documentation site, so the changelog and the
   version in the navigation bar are current.

A failed publish can be retried with *Re-run failed jobs*; the tag is
kept. A dry run uploads the notes it would publish as the `release-notes`
artifact.

To preview the notes locally without touching the changelog:

```sh
cp CHANGELOG.md /tmp/CHANGELOG.md
php tools/relprep.php --bump patch --changelog /tmp/CHANGELOG.md --notes /dev/stdout
```

## Documentation site

The site is built with [VitePress](https://vitepress.dev) from `docs/` and
deployed to GitHub Pages by `.github/workflows/docs.yml`: on every push to
`main` that touches `docs/` or `CHANGELOG.md`, after each release, and on
demand (*Run workflow*). Pull requests build it without deploying, which
catches dead links. `docs/internals/` is kept out of the site.

```sh
npm ci --prefix docs
npm --prefix docs run dev      # live preview on http://localhost:5173/collaborate/
npm --prefix docs run build    # production build in docs/.vitepress/dist
```

## One-time setup

- Submit `https://github.com/janalis/collaborate` on
  [packagist.org](https://packagist.org/packages/submit) and enable its
  GitHub hook, which then updates on every tag. Optionally add the
  `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` repository secrets (a Packagist
  API token) so the release workflow pings it at once.
- **Settings → Pages → Build and deployment → Source: GitHub Actions.**
- If `main` is protected, let GitHub Actions bypass the protection (or the
  rule set) so the release commit and tag can be pushed.
