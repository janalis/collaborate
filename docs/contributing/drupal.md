# The Drupal module

The module lives in `drupal/` and is published as a repository of its own,
in two places: the GitHub split
[janalis/collaborate_drupal](https://github.com/janalis/collaborate_drupal)
(Packagist: `janalis/collaborate_drupal`) and the drupal.org project
`collaborate` (`drupal/collaborate` on packages.drupal.org). Both are
written by the workflows only: GitHub is where its code is merged.

## The split

`.github/workflows/drupal-split.yml` splits `drupal/` with its history
(`git subtree split --prefix=drupal`) on every push to `main` that touches
it, and pushes it as the `<major>.x` branch (`0.x`) to both repositories.
The split is deterministic, so every push fast-forwards. Before pushing, it
checks that the split is a Drupal module (`collaborate.info.yml`), that
its `composer.json` validates, and that its logo is one drupal.org takes.
*Run workflow* with *dry run* splits and checks without pushing.

## The project page

drupal.org shows the project's logo from `logo.png` at the root of its
default branch: `drupal/logo.png`, a PNG of 512×512 pixels and at most
10 KB, rendered from the documentation's logo:

```sh
rsvg-convert -w 512 -h 512 docs/public/logo.svg -o /tmp/logo.png
magick /tmp/logo.png -strip -colors 64 PNG8:drupal/logo.png
```

The rest of the page is edited by hand on drupal.org (*Edit* on the
project), which has no API for it:

- **Description**: `drupal/README.md`, rendered as HTML (GitHub's rendering
  of the file), and its first paragraph as the *Summary*, which listings and
  Project Browser show. Paste it again when the README changes.
- **Images**: the Drupal screenshots, `docs/public/screenshots/drupal*.png`,
  with their alt texts from the guide; replace them when the screenshots
  workflow retakes them.
- **Resources**: the documentation links (the Drupal guide, the online
  documentation, the configuration), the changelog, and the guide as the
  screenshots link.
- **Categories**: Content editing experience, Administration tools.

Once the module has a stable release, opt it into drupal.org's security
advisory coverage (its security review): Project Browser and many site
builders look for the shield.

## Releases

The module is released with the bundle, under the same version: the
[release workflow](./releasing) makes `drupal/composer.json` require the
released bundle (`^X.Y.Z`, `tools/relprep.php --module`) in the release
commit, then calls the split with the tag `X.Y.Z` (drupal.org's tags carry
no `v`), pings Packagist for `janalis/collaborate_drupal`, and opens an
issue, **Publish the Drupal module X.Y.Z on drupal.org**.

drupal.org has no API to create a release page: a maintainer opens the
project's releases, adds a release for the pushed tag and pastes the notes
the issue holds. The `drupalorg` workflow closes the issue once drupal.org
lists the release.

Two things the form does not say:

- drupal.org escapes what a `<code>` holds itself. Notes pasted as HTML (the
  GitHub release's, rendered) keep `<` and `>` as they are inside `<code>`:
  `&lt;entity&gt;` there would show as written.
- The first release of a series (a new minor or major) is unsupported until
  the series is ticked **Supported** under the project's **Administer
  releases**; until then drupal.org shows it as unsupported and does not
  recommend it.

## Contributions on drupal.org

`.github/workflows/drupalorg.yml` runs `tools/drupalorg.php` every 30
minutes:

| Command | What it does |
|---|---|
| `mrs` | Each open merge request targeting `0.x` becomes a pull request, `drupalorg/mr-<iid>`: its commits are replayed onto `main` under `drupal/` (`git format-patch`, `git am -3 --directory=drupal`), with their authors, titled `[drupal.org !<iid>] …` and labelled `drupal.org`. The merge request is told where its review happens; new commits update the pull request. Commits that do not apply get the merge request asked, once, to rebase. |
| `issues` | Each new issue of the project becomes an issue, `[drupal.org #<nid>] …`, linking back. drupal.org has no write API: its status is not synced. |
| `releases` | Closes the release issues drupal.org lists the release of. |
| `closed <pr>` | Run when a mirrored pull request closes: its merge request is told (merged, or closed) and closed, and the branch deleted. |

It keeps no state: a mirrored pull request carries
`<!-- drupalorg-mr: <iid> @ <sha> -->` in its body, a mirrored issue
`<!-- drupalorg-issue: <nid> -->`. A merge request's commits only ever
change `drupal/`, so a contribution cannot change the workflows; CI runs on
its pull request, and a maintainer reviews and merges it as any other.

Run it by hand with `--dry-run`, which reads drupal.org and GitHub and
prints what it would write (`gh` signed in, from a checkout):

```sh
php tools/drupalorg.php mrs --repo janalis/collaborate --dry-run
php tools/drupalorg.php issues --repo janalis/collaborate --dry-run
```

Until the drupal.org project exists, each command notes it and does
nothing; without the deploy keys, the split pushes nowhere.

## One-time setup

| Where | What | Secret |
|---|---|---|
| GitHub | Create the empty repository `janalis/collaborate_drupal`, with a deploy key allowed to write | `SPLIT_DEPLOY_KEY` (its private key) |
| Packagist | Submit `https://github.com/janalis/collaborate_drupal` | `PACKAGIST_USERNAME`, `PACKAGIST_TOKEN` (already set for the bundle) |
| drupal.org | Create the full project `collaborate`; add an SSH key with push access to `git@git.drupal.org:project/collaborate.git`; set its default branch to `0.x` once pushed | `DRUPALORG_SSH_KEY` |
| git.drupalcode.org | A personal access token with the `api` scope, to comment on and close merge requests | `DRUPALORG_GITLAB_TOKEN` |
| GitHub | A GitHub App or fine-grained token (contents, pull requests and issues: write) that pushes the mirrored branches: what the workflow's own token pushes runs no workflow, CI included | `DRUPALORG_MIRROR_TOKEN` |

On the drupal.org project page, say that the module is developed on
GitHub, and link its issue queue there.
