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
make lint-js             # Biome and TypeScript (npm run lint)
```

## The test applications

`tests/Application` holds two applications built on one shared kernel
(`Shared/AppKernel.php`), the same entities, forms and CKEditor wiring. An
`Article` has one field of each kind a draft carries (text, textarea, rich
text, Markdown with a source mode, a select, radios, a checkbox, a number, a
date, a `Category` association, an unmapped password) and ordered `Tag`
entries, each with a rich-text note:

| Application | Kernel | What it shows |
|---|---|---|
| `Plain` | `PlainKernel` | A host without EasyAdmin: its own controller, form context (`PlainFormContext`), templates and collection markup. |
| `EasyAdmin` | `EasyAdminKernel` | A dashboard and CRUD controllers, with the layout override. |
| `Host` | `HostKernel` | A host whose pages are no forms: a library of folders whose records show as rows or tiles, a share popup per record, a folder loaded in place of the listing; drafts off, every cursor option on, the room of a page the folder `HostPlaces` names. |

Both sign users in by user name without a password (`alice`, `bob`,
`carol`, `dave`). Their
console recreates an application's database and loads its fixtures:

```sh
make app-setup
```

It builds the stylesheets and an ES module copy of
`@microsoft/fetch-event-source` (`tests/Application/bin/setup-assets.mjs`),
installs EasyAdmin's public files, and recreates both applications'
databases with their fixtures
(`COLLABORATE_APP=plain|easyadmin|host APP_ENV=e2e php tests/Application/bin/console.php app:setup`).

## Playwright

`tests/playwright` drives the applications in Chromium, each scenario with
two or more signed-in editors in browser contexts of their own.
`playwright.config.js` starts FrankenPHP with `tests/Application/Caddyfile`,
which serves four sites, each with its own Mercure hub on its own origin:

| Project | Port | What it runs |
|---|---|---|
| `plain` | `PLAIN_PORT` (8710) | The plain application (`e2e` environment): every spec but `easyadmin-*`, `host-*` and `limits`. |
| `easyadmin` | `EASYADMIN_PORT` (8720) | The EasyAdmin application (`e2e`): every spec but `host-*` and `limits`. |
| `limits` | `LIMITS_PORT` (8730) | The plain application in its `e2e_limits` environment, whose drafts take updates of 4 KiB, journals of 64 KiB and 30 field states, and compact on the next Join past 1 KiB: `limits.spec.js`. |
| `host` | `HOST_PORT` (8740) | The host application (`e2e`), whose pointers go idle after 5 s: `host-*.spec.js`. A folder is revoked with `POST /e2e/folders/{folder}/revoke`. |

The `e2e` environments show two avatars before the `+N` counter. Every
scenario creates its own article through `POST /e2e/articles`; a script
writing outside the drafts is `POST /admin/e2e/articles/{id}`, and a save
without JavaScript is a browser context with JavaScript off. Scenarios wait
on DOM state, network requests or the page's draft session, never on fixed
delays; routes (`page.route`) only stand in for what a browser cannot be
made to do on demand: a refused or busy server, a lost journal request.
`back-forward-cache.spec.js` runs the full Chromium build without
Playwright's `--disable-back-forward-cache`, so that a page is really
restored from the cache.

```sh
npx playwright install chromium
make e2e                              # app-setup, then the four projects
make e2e ARGS="--project=plain"       # one application
PLAIN_PORT=8810 EASYADMIN_PORT=8820 LIMITS_PORT=8830 make e2e   # other ports
```

It needs `frankenphp` on the `PATH` (or `FRANKENPHP=/path/to/frankenphp`)
and `COLLABORATE_DATABASE_URL`. Scenarios run one at a time, since presence
is shared by everyone on a page.

### Traceability

Each user-facing behaviour of the guides, and the scenario that shows it in
a browser (`spec › test`). P: the plain application, E: the EasyAdmin one,
L: the `limits` project. The host options, shown by the `host` project
alone, have a table of their own below.

| Feature (guide) | Scenario | P | E | L |
|---|---|---|---|---|
| **Shared drafts** | | | | |
| Text edits carried both ways | `collaboration › carries text edits both ways` | ✓ | ✓ | |
| Concurrent typing in one text field converges | `fields › merge text two editors type at once` | ✓ | ✓ | |
| Textarea line breaks (LF), number, date, select, checkbox, radios, association (EasyAdmin autocomplete) carried and saved | `fields › carry text, a multi-line textarea, a number, a date, a select, a checkbox, radios and an association` | ✓ | ✓ | |
| A peer's choice fires `collaborate:applied` | same | ✓ | ✓ | |
| Last writer wins on choices and checkboxes | `fields › take the last value written to a choice, everywhere` | ✓ | ✓ | |
| Password and unmapped fields never shared | `fields › never carry a password, which stays on the page that typed it` | ✓ | ✓ | |
| Collection row added by a peer, built by the host's row initializer (its CKEditor) | `collections › shows a row a peer adds, with the widgets the host builds on its rows` | ✓ | ✓ | |
| Collection row removed by a peer | `collections › removes a row a peer removes` | ✓ | ✓ | |
| Rows reordered by a peer, focused row kept, positions renumbered, order saved | `collections › reorders rows as a peer does, renumbers their positions and saves that order` | ✓ | ✓ | |
| Order saved from the reordering page | `collections › saves the order from the page that reordered it` | ✓ | ✓ | |
| **State bar** | | | | |
| Connecting (grey, spinner), controls inert until ready, Discard off | `draft-states › locks the form while it connects, then shows the saved draft` | ✓ | ✓ | |
| Saved (green, check icon, announced), neither Save nor Discard offered | same; `saving › saves the draft and tells the other pages who saved it` | ✓ | ✓ | |
| Unsaved (blue, pen, "Click Save"), Synchronizing (blue, spinner, not announced) | `draft-states › shows unsaved changes, and synchronizes without announcing it` | ✓ | ✓ | |
| Interrupted (amber, plug) on a network outage, edits sent once back | `draft-states › keeps edits through a network outage and sends them once it is over` | ✓ | ✓ | |
| Interrupted on a failing journal (5xx), recovery | `draft-states › retries edits the journal failed to store` | ✓ | ✓ | |
| Hub outage: replay every 5 s, nothing lost, stream recovery | `draft-states › follows the draft by replaying it while the hub is unreachable` | ✓ | ✓ | |
| Busy Join (429, 503) retried | `draft-states › joins once a busy server lets it` | ✓ | ✓ | |
| Changed meanwhile (409), draft read again | `draft-states › reads the draft again after an action refused as made on an older state` | ✓ | ✓ | |
| Refused (red), no Discard | `draft-states › shows a refusal, and offers no Discard from it` | ✓ | ✓ | |
| Access expired (red, lock), local content kept | `draft-states › stops when the editor signed out elsewhere, keeping the local content` | ✓ | ✓ | |
| Incompatible: an edit the server cannot accept | `draft-states › stops on an edit the server cannot accept` | ✓ | ✓ | |
| Incompatible: another CKEditor build named by the fresh grant | `draft-states › stops when the server now edits a field with another editor build` | ✓ | ✓ | |
| Unavailable (grey, link-slash) when Join fails, ordinary Save | `draft-states › falls back to ordinary editing when the draft cannot be joined` | ✓ | ✓ | |
| Unavailable when an editor fails; its Save an outside change for peers | `draft-states › falls back to ordinary editing when an editor fails, its save an outside change for others` | ✓ | ✓ | |
| Reset (amber) on a peer's Discard, local content kept, "who" named | `discard › keeps the unsaved content of another page, which says who reset the draft` | ✓ | ✓ | |
| Created (green), Open saved record | `new-record › tells every page of the draft it created its record, and the pages that join later` | ✓ | ✓ | |
| In conflict (amber, compare icon) | `conflicts › lists each difference by its label, as read, with the words that changed` | ✓ | ✓ | |
| Full (red, database) | `limits › refuse an update too large, and the draft shows full until discarded` | | | ✓ |
| **Saving** | | | | |
| Save, "All changes saved by …" on peers, own save unnamed | `saving › saves the draft and tells the other pages who saved it`; `collaboration › tells the other editor of a save, which keeps the shared values` | ✓ | ✓ | |
| Save from the bar: Save first among its actions, offered only while unsaved, the page stays on the record | `saving › saves from the bar, staying on the page, and offers Save only while changes are unsaved` | ✓ | ✓ | |
| Save overtaken by a peer edit (posted from the bar): back to the form with a warning, saved again | `saving › sends back to the form a save a peer edit overtook, which then saves again` | ✓ | ✓ | |
| Validation error keeps the draft and the submitted values | `saving › keeps the draft and the submitted values when validation fails` | ✓ | ✓ | |
| Save blocked by conflicts brings the review into view and focus | `conflicts › brings the review into view and focus instead of saving` | ✓ | ✓ | |
| **Discarding** | | | | |
| Confirmation with Cancel focused; Cancel and Escape keep the draft | `discard › asks first, with Cancel focused, and keeps the draft when cancelled or dismissed` | ✓ | ✓ | |
| Discard for everyone, own page reloaded with a dismissible notice shown once | `discard › reloads the discarding page on the saved record, saying why once`; `collaboration › discards the shared draft for everyone after confirmation` | ✓ | ✓ | |
| Discard from Full | `limits › refuse an update too large, and the draft shows full until discarded` | | | ✓ |
| **New records** | | | | |
| Invitation link copied, "Copied" and its announcement | `new-record › copies its invitation link to the clipboard` | ✓ | ✓ | |
| Clipboard refused: link shown selected | `new-record › shows its invitation link selected where the clipboard refuses` | ✓ | ✓ | |
| Independent new-record pages have distinct drafts | `new-record › is not shared with another new-record page opened without the link` | ✓ | ✓ | |
| Drafted together, created once, "created by", own tab unnamed, later joiners told, Open saved record | `new-record › tells every page of the draft it created its record, and the pages that join later`; `collaboration › is drafted together through its invitation link, and created once` | ✓ | ✓ | |
| **Conflicts** | | | | |
| Record saved outside (without JavaScript) reaches open pages | `conflicts › lists each difference by its label, as read, with the words that changed` | ✓ | ✓ | |
| Labels; choice by option label; checkbox Yes/No; rich text without markup; word diff | same | ✓ | ✓ | |
| Collection entry removed/present and order changed, named after the collection | `conflicts › names collection entries removed and reordered elsewhere` | ✓ | ✓ | |
| Keep shared draft: draft edits kept, saved values taken elsewhere, Save keeps the outside write | `conflicts › keeps the draft edits and takes the saved values for the other fields` | ✓ | ✓ | |
| Use saved record, its own dialog, notice after the reload | `conflicts › replaces the draft by the saved record for everyone once confirmed` | ✓ | ✓ | |
| **Limits and compaction** | | | | |
| One update too large (413) | `limits › refuse an update too large, and the draft shows full until discarded` | | | ✓ |
| Journal of one draft full | `limits › stop a journal grown past its size` | | | ✓ |
| Field states: new collection entries refused | `limits › refuse new collection entries past the field states it may hold` | | | ✓ |
| Compaction on Join; a page showing the saved draft reloads on its own | `limits › compact a saved draft on the next Join, reloading the pages that showed it` | | | ✓ |
| Compaction before a page heard of the save; with nothing unsent it reloads on its own | `limits › reload a page that sent every edit when its draft is compacted before it hears of the save` | | | ✓ |
| Compaction; a page with unsent edits keeps them (Reset) | `limits › keep the unsent edits of a page when its draft is compacted` | | | ✓ |
| **Grants** | | | | |
| Short grant refused (412): full grant sent again | `grants › resend the full field grant when the server no longer knows its signature` | ✓ | ✓ | |
| Field grant refused (403): renewed from the form's URL, edit resent | `grants › renew a refused field grant from the form, and go on` | ✓ | ✓ | |
| Page grant refused: renewed from the page, stream kept | `grants › renew a refused page grant from the page, and keep the stream` | ✓ | ✓ | |
| **Back/forward cache** | | | | |
| Restored page renews its subscription and baseline, sends its edits, rejoins presence | `back-forward-cache › catches up with the draft and its peers` | ✓ | ✓ | |
| **Rich text** | | | | |
| Rich-text edits both ways | `collaboration › carries rich-text edits both ways` | ✓ | ✓ | |
| Concurrent typing converges; formatting carried | `rich-text › merges what two editors type at once, formatting included` | ✓ | ✓ | |
| Undo and redo stay local | `rich-text › undoes only the own edits, on every page` | ✓ | ✓ | |
| Markdown source mode shared (radios `<id>_view`), source typing reaches peers, mode not an unsaved change | `rich-text › shares its source mode, and carries source typing to the rendered editors` | ✓ | ✓ | |
| Source typed mid-syntax stays as typed during a peer's edit | `rich-text › keeps source typed mid-syntax as typed while a peer edits` | ✓ | ✓ | |
| **Presence** | | | | |
| Others listed, hidden when alone; dropped when they close the tab | `collaboration › shows who else is on the page, and drops them when they leave` | ✓ | ✓ | |
| Ordered by id, initial on colour, accessible name, `+N` past `max_avatars` | `presence › lists the other collaborators by id, past two as a counter naming the rest` | ✓ | ✓ | |
| Own other tabs hidden | `presence › hides the collaborator's own other tabs` | ✓ | ✓ | |
| Hidden tab leaves at once, back when visible | `presence › drops a collaborator whose tab is hidden, and shows them again when it is back` | ✓ | ✓ | |
| Silent tab expires (15 s) | `presence › drops a collaborator whose tab went silent` | ✓ | ✓ | |
| Rooms: query string separates, `_locale` does not | `presence › separates pages by their query string, not by locale` | ✓ | ✓ | |
| **Cursors** | | | | |
| Pointer with name, colour, shape (I-beam, hand) | `cursors › draw a peer's pointer with their name and colour, in the shape they see` | ✓ | ✓ | |
| Pointer anchored to the element under it across window sizes; hidden off screen | `cursors › anchor the pointer to the element under it, whatever the window size` | ✓ | ✓ | |
| Touch pointer as a circle that fades | `cursors › show a touch as a circle that fades once contact ends` | ✓ | ✓ | |
| Caret and selection in a text field, name flag fading, kept while the pointer is elsewhere, gone on blur | `cursors › show a caret and its selection in a text field, with a name flag that fades` | ✓ | ✓ | |
| Caret in CKEditor | `cursors › show a caret in a rich-text field`; `collaboration › draws a peer's pointer and caret` | ✓ | ✓ | |
| Field marks: select, checkbox, radios, autocomplete, rich editor; never a password | `cursors › outline the field each peer is in, a choice, a checkbox, radios and a rich editor's included` | ✓ | ✓ | |
| Several peers in one field share one outline | `cursors › share one outline between several peers in one field, naming them all` | ✓ | ✓ | |
| **Saved-view refresh (EasyAdmin)** | | | | |
| Index page refreshed by a save elsewhere | `easyadmin-saved-views › a saved list shows a record saved by another editor` | | ✓ | |
| Detail page refreshed | `easyadmin-saved-views › a detail page shows its record saved by another editor` | | ✓ | |
| Scroll position, ticked rows and focused row kept | `easyadmin-saved-views › a refreshed list keeps its scroll position, its ticked rows and the focused row` | | ✓ | |
| Refresh waits while search text is typed | `easyadmin-saved-views › a list waits while search text is typed and not submitted` | | ✓ | |
| Lost stream: refresh once it is back | `easyadmin-saved-views › a list refreshes when its lost stream comes back` | | ✓ | |
| A form refreshes its embedded lists, not itself | `easyadmin-saved-views › a form refreshes the lists embedded in it, not itself` | | ✓ | |

The host options (`host` project, `host-cursors › a host library › …`):

| Feature (guide) | Scenario |
|---|---|
| A pointer on a record found in the other layout (`key_attribute`), the record marked with the peer's initial (`markers`), the menu saying where they are | `the readers of a folder see each other, and a pointer on a record in the other view` |
| Two addresses of a place are one room, another place another (`places`) | `two addresses of a folder are one place; another folder is another` |
| A place entered without a page load (`collaborate:enter`) | `a reader who loads another folder in place of the listing leaves this one, and comes back` |
| A popup of a record: its line under the title, a caret in it, pointers above the modal dialog (`popups`, `top_layer`); no caret outside `caret_regions` | `the readers of a popup of a record see each other in it and type, above the open dialog` |
| An arrow at the edge of a pane (`edges`); Find from the presence menu, said (`presence: menu`) | `a pointer scrolled out of sight is an arrow, and its reader is found from the menu` |
| Idle pointers dimmed and said idle, woken as they move (`idle`) | `a still pointer goes idle, and wakes as it moves` |
| Show pointers, in every tab of the browser | `a reader hides the pointers of the others in every tab, and keeps seeing who they are` |
| A place revoked refused at the next request (`PlaceProviderInterface::canEnter()`) | `a folder revoked stops its pages from sharing` |

And `host-cursors › a newcomer to a host library › …`:

| Feature (guide) | Scenario |
|---|---|
| A reader who moved while alone told the first peer who answers, before their next heartbeat (`silent_alone`) | `who points before the reader already there answers is seen once she answers` |

Not shown in a browser, and covered by the PHPUnit and Vitest suites
instead:

| Behaviour | Why not end to end |
|---|---|
| A Save waiting for another Save's lock ("Another save … is in progress") | Needs a server-side Save held for seconds; the browser cannot hold the named lock. |
| "Synchronize the shared draft before saving", "already created its record" | Only a form posted without the page's session (a forged request) reaches them. |
| An edit refused as made before a peer's Discard | The race between a send and a Discard notice is not reproducible on demand. |
| Purge (`collaborate:purge`) and a page left on a purged draft | Needs drafts idle for days. |
| Translated (Gedmo) fields | The test applications have no translatable property. |
| EasyAdmin's slug widget, TomSelect and Tagify text widgets | Not in the test applications (TomSelect is, as the association's autocomplete). |
| Pointer shapes per platform, reduced motion, `hub.publish_pause` | Platform, media and hub failures the browser cannot switch per scenario. |
| Avatars from `avatarUrl()` | The test accounts have none. |
| `share_stream` over HTTP/1.1, `distinct_colors`, `silent_alone`'s hello resent | One context per reader holds its own locks; colour picking and lost hellos are Vitest's (`tests/vitest/cursors/host.*`). The host project runs with them on. |
| An accessibility scan (axe) of the presence menu and a popup's line | `@axe-core/playwright` is not a dependency of the bundle. |
