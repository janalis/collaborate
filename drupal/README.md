# Collaborate

Real-time collaborative editing for Drupal's administration: editors of the
same node share one draft of its form instead of overwriting each other, and
see who else is there and where they are typing.

Two editors open the same article. Without this module, the second to save
either overwrites the first or gets *"The content has been modified by
another user"* and loses their changes. With it, both edit one shared draft,
kept on the server as they type: each sees the other's changes, caret and
field, and one Save records the work of both.

**Documentation:** <https://janalis.github.io/collaborate/guide/drupal>

## Features

- **Shared drafts of content entity forms**: nodes, terms, media, block
  content, users… Text fields, CKEditor 5 fields as rich text (concurrent
  edits merged, not overwritten), selects, checkboxes, radios,
  autocompletes, the items of multi-value fields (added, removed,
  reordered), [Paragraphs](https://www.drupal.org/project/paragraphs) and
  the entities [Inline Entity Form](https://www.drupal.org/project/inline_entity_form)'s
  simple widget edits. The draft survives a reload or a closed tab. Save
  goes through the entity form's own validation and submission.
- **Presence and cursors**: the top bar shows who else is on the page; their
  pointers, carets, selections and the field each one is in are drawn in
  their color.
- **Conflict review**: when the entity is saved outside the draft (from a
  browser without JavaScript, say), the draft shows what differs, word by
  word, and the editor keeps the draft or takes the saved version.
- **New content drafted together**: a new node's form has an invitation
  link; it is created once, whoever saves it.
- **Refreshed listings**: the content overview, views and entity pages
  reload when a save elsewhere changes what they show.
- **Notifications**: a bell in the top bar, topics each editor subscribes
  to, delivered through Drupal's queue, and web push.

Screenshots of each, and how they behave, are in the
[Drupal guide](https://janalis.github.io/collaborate/guide/drupal).

## Who it is for

Sites where several people edit content in the administration, on **Claro**
or **Gin**. The front-end theme is not involved: nothing changes for
visitors.

## Requirements

- Drupal 11.3 or later, or 12, on PHP 8.4+.
- MariaDB 10.6+, MySQL 8, PostgreSQL 16+ or SQLite 3.35+.
- A hub, which carries the edits between browsers: FrankenPHP's built-in
  [Mercure](https://mercure.rocks) hub, any Mercure hub, or the module's own
  WebSocket server (`drush collaborate:hub:serve`). No Node.js service.
- [Navigation](https://www.drupal.org/docs/develop/core-modules-and-themes/core-modules/navigation-module)
  for the top bar items, or the module's two blocks in the administration
  theme's header.

Drafts and notifications are stored in the site's own database, in the
module's tables. Nothing is sent to a third-party service.

## Installation

```sh
composer require drupal/collaborate
drush pm:install collaborate
```

Then:

1. Name the hub in `settings.php` ([how](https://janalis.github.io/collaborate/guide/drupal#the-hub)).
2. Grant the *Collaborate* permission (and *Receive notifications*) to the
   editors' roles.
3. Adjust the options at **Configuration → Content authoring → Collaborate**
   (`/admin/config/content/collaborate`).

Until a hub is named, pages render as before and the status report says
what is missing.

## Limits

- Inline Entity Form's **complex** widget is not shared: the forms it opens
  are each editor's own.
- A field's `#states` that names another field's item by its position no
  longer finds it: the items' controls are named by their key.
- The administration only: front-end forms are not shared.

## Similar modules

- [Content Lock](https://www.drupal.org/project/content_lock) locks a node
  while one editor has it open: the others wait. Collaborate lets them edit
  together.
- [Autosave Form](https://www.drupal.org/project/autosave_form) keeps one
  editor's unsaved changes. Collaborate keeps a draft shared by every
  editor of the entity.
- [Conflict](https://www.drupal.org/project/conflict) resolves conflicting
  saves after the fact. Collaborate avoids most of them, and reviews the
  rest before saving.

## Project status

- Under active development, released with the
  [janalis/collaborate](https://github.com/janalis/collaborate) Symfony bundle
  it runs on, under the same version. Not yet covered by drupal.org's
  security advisory policy.
- The bundle's code is fully covered by its tests (every statement, and a
  100% mutation score), and the module is tested with PHPUnit (unit, kernel and
  functional, on MariaDB, PostgreSQL and SQLite) and in the browser on Claro.
- MIT licensed.

## Documentation

- [Drupal guide](https://janalis.github.io/collaborate/guide/drupal): install,
  the hub, configuration, multi-value fields, Paragraphs, limits.
- [Online documentation](https://janalis.github.io/collaborate/): shared
  drafts, rich text, cursors and presence, notifications, the WebSocket hub,
  every configuration option.
- [Changelog](https://github.com/janalis/collaborate/blob/main/CHANGELOG.md).

## Contributing

The module is developed on GitHub, in the `drupal/` directory of
[janalis/collaborate](https://github.com/janalis/collaborate): this
repository is split from it, and its releases are tagged from there.

Merge requests opened here are welcome: each one is mirrored to a pull
request on GitHub, its commits kept with their authors, and reviewed there.
New commits on the merge request update the pull request; when it is merged
or closed, the merge request is told and closed. Issues opened here are
mirrored as GitHub issues.
