# Bridges

The bundle shares any page's pointers and presence, and any form's draft,
through markup of its own (`data-collaborate-*`). A **bridge** is what it
knows of an admin beyond that:

- the services that say which record a form edits and when it is saved;
- the **regions** a pointer is placed from (`cursors.regions`);
- the **script profile** that reads the admin's markup: its rows and
  collection items, the page a list or record is shown on, its embedded
  lists, its row selection, its overlays, its collection and slug widgets,
  and its save buttons.

| Bridge | Admin | Selected by |
|---|---|---|
| `none` | Any Symfony page: your controllers, your form context | `collaborate.bridge: none`, or `auto` without EasyAdmin |
| `easyadmin` | [EasyAdmin](./easyadmin) 4.24+ or 5 | `collaborate.bridge: easyadmin`, or `auto` with EasyAdminBundle registered |
| `drupal` | [Drupal](./drupal) 11.3+ or 12, on Claro or Gin | Installing the `janalis/collaborate_drupal` module |

## Choosing the bridge

```yaml
# config/packages/collaborate.yaml
collaborate:
    bridge: auto   # the default: easyadmin when EasyAdminBundle is registered, none otherwise
```

`auto` reads the bundles the kernel **registers**: EasyAdmin installed as a
dependency of another package does not select its bridge. Set `none` to
leave EasyAdmin's adapter out of an application that registers EasyAdmin
for other pages. Setting `easyadmin` without EasyAdminBundle registered
stops the container from compiling. `drupal` is refused: Drupal does not
boot the bundle, its module builds the services itself.

The resolved bridge is the `collaborate.bridge` parameter, and the layer
tells it to the page's scripts (`data-config`).

## What each bridge brings

| | `none` | `easyadmin` | `drupal` |
|---|---|---|---|
| Form context | Yours ([No bridge](./without-easyadmin#the-form-context)) | `EasyAdminFormContext` | The module's entity forms |
| Save hook | Your controller calls `DraftSave::completed()` | `SaveSubscriber` | The module's submit handler |
| Default regions | `modal`, `header`, `content`, `body` | `modal`, `header`, `mobile-header`, `sidebar`, `content`, `body` | `modal`, `header`, `sidebar`, `content`, `body` |
| Rows, entries | `data-collaborate-row`, `data-collaborate-entry` | and `.form-group`, `.field-collection-item` | and Form API's `.js-form-item`, multi-value table rows |
| Saved-view refresh | Pages marked `data-collaborate-page` | and EasyAdmin's index and detail pages, embedded lists | Content listings, views, entity pages |
| Script entry | `collaborate.js` | `easyadmin.js` | The module's |

The regions of each bridge, as the configuration reference lists them:

```yaml
# none
modal: 'dialog[open], .modal.show'
header: 'body > header, [role=banner]'
content: 'main, [role=main]'
body: body
# easyadmin
modal: .modal.show
header: .content-top
mobile-header: .responsive-header
sidebar: '.ea-sidebar, .sidebar'
content: .content-wrapper
body: body
# drupal (Claro, Gin)
modal: .ui-dialog
header: '#toolbar-bar, .gin-secondary-toolbar, .region-header'
sidebar: '.layout-region--secondary, .gin-sidebar'
content: '.region-content, .layout-region--main, main'
body: body
```

`cursors.regions` replaces them; leave it empty for the bridge's.

## The script entries

Import one entry per page, before anything that wraps `window.fetch`:

```js
import '@janalis/collaborate/collaborate.js'; // no bridge, or whichever the layer names
import '@janalis/collaborate/easyadmin.js';   // EasyAdmin's profile, then every module
```

Each entry starts the cursors, the saved-view refresh and the shared drafts,
and exports `sessionFor()`, `addRowInitializer()` and `registerBridge()`.
The layer names the bridge the server resolved; when its profile is
registered, the scripts read the page with it, else with the profile the
entry registered, else with no bridge's.

An admin of your own can describe its markup the same way:

```js
import { registerBridge } from '@janalis/collaborate/bridge.js';

registerBridge({
  name: 'none', // the layer's bridge whose profile this replaces
  rows: ['.my-field'],
  entries: ['.my-collection-item'],
  selection: 'input.row-select',
  submitters: ['save'],
});
import '@janalis/collaborate/collaborate.js';
```

## Marking a page

The saved-view refresh reloads a list or a record's detail page when a save
elsewhere changes what it shows. A page says what it shows on its body:

```html
<body data-collaborate-page="Article:index"> <!-- a list of articles -->
<body data-collaborate-page="Article:detail"> <!-- one article -->
<body data-collaborate-page="index">          <!-- a list, refreshed for any save -->
```

The entity name is the short class name the bundle announces (`Article`
for `App\Entity\Article`); under Drupal, the entity type (`node`). The
kinds `edit` and `new` mark forms, which follow their draft instead.
EasyAdmin's pages are read from their body classes; Drupal's are marked by
the module.
