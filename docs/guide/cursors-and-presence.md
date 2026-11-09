# Cursors and presence

Every page that renders the [layer](./getting-started#_3-render-the-layer-and-the-presence)
shows its other collaborators, as long as
[`cursors.enabled`](./configuration#cursors) is on (the default). No cursor
state is written to the database.

![Bob, seen from Alice's page: his avatar in the header, his pointer over the summary, and his selection in the content field he is in](/screenshots/presence-cursors.png)

## Rooms

Collaborators see each other when they are on the same **room**: the same
route, route parameters and query string (search, filters, sorting,
pagination…). The locale (`_locale`), `_token` and `_switch_user`
parameters and the URL fragment do not separate rooms. The room is an HMAC
of these values with the kernel secret, signed into the page grant: a
client cannot join a room by naming it.

## Pointers

- Each collaborator's pointer is drawn in their color with their name.
  Positions are anchored to the element under the pointer (a control, a
  field, a table row), so they follow it on peers with other window sizes
  or scroll positions; a target that is missing, clipped, covered or off
  screen hides the pointer.
- A tab sends at most one event every [`cursors.throttle`](./configuration#cursors)
  milliseconds (100). Peers interpolate movement over 100 ms, or jump
  straight to it under `prefers-reduced-motion`.
- A pointer takes the shape its sender sees: the computed CSS `cursor` of
  the element under it (a hand over links, resize arrows over handles), an
  I-beam over editable fields and selectable text. Shapes follow the
  sender's platform (macOS, Windows, or Linux in Ubuntu's style; other
  desktops use Windows'), and touch pointers show as a circle that fades
  after contact ends.
- A collaborator's other tabs are hidden from them; for everyone else, the
  tab that moved last owns that collaborator's pointer.

## Carets and field marks

- In focused text inputs, textareas and CKEditor fields, peers see a caret
  bar and the selection, tinted in the collaborator's color. A name flag
  shows while the caret moves or its text changes and fades two seconds
  later. Carets stay while the pointer is elsewhere and leave on blur.
  Password and other non-text inputs share no caret.
- Caret positions are text offsets in the field, so peers with the same
  content resolve the same position. In rich text, block boundaries count
  as one character.
- On a [shared form](./shared-drafts), the field each collaborator is in is
  outlined in their color with their name above it; this covers selects,
  checkboxes, radios, autocompletes and rich editors too. Several people in
  one field share one outline and a list of names. Password, file and
  hidden controls are never named.

## Presence

The element rendered by `collaborate_presence()` lists the other
collaborators on the page, ordered by id: each as their avatar
(`CollaboratorProviderInterface::avatarUrl()`, same origin only) ringed in
their color, or as their initial on it, with their name as tooltip and
accessible label. Past [`cursors.max_avatars`](./configuration#cursors)
(5), a `+N` counter names the rest. The element is hidden while nobody else
is there.

A collaborator stays present while any of their tabs on the page is open
and visible, whether or not their pointer is on it. Hiding or leaving a tab
sends a `leave` event at once; heartbeats every
[`cursors.heartbeat`](./configuration#cursors) seconds (5) keep an idle tab
present, and a tab silent for `cursors.expiry` seconds (15) is dropped, which
covers crashes and lost connections.

## Transport

Cursor events go to `POST <prefix>/cursors/events` with the page grant and
the layer's CSRF token. The server validates them (kind, shape, anchor,
caret, field, at most [`cursors.max_event_bytes`](./configuration#cursors)),
replaces anything identity-related with what it knows (user id, name,
color, avatar, a server timestamp) and publishes them privately to the
room's topic after the response. Freshness compares server timestamps: an
event more than `cursors.expiry` older than the newest one received is
dropped, so a client whose clock differs from the server's still shows
cursors.

If the hub fails, the worker stops publishing for
[`hub.publish_pause`](./configuration#hub) seconds (5) and answers cursor
events with 503 meanwhile, so senders back off instead of tying up
workers. A hidden tab leaves the room's topic; reconnecting exchanges
current positions only, never past movement.

The overlay never intercepts clicks, focus, scrolling or assistive
technologies (`pointer-events: none`, `aria-hidden`), and cursor requests
bypass any `fetch` wrapper installed after the bundle's entry point.

## Pages of your own

The defaults describe EasyAdmin's pages. A page laid out another way (a file
listing, a board, a document) describes itself with the
[`cursors` options](./configuration#cursors); each is off, or EasyAdmin's,
by default.

### Places

A room is the page's route, parameters and query unless a
`PlaceProviderInterface` names it (`cursors.places`):

```php
use Janalis\Collaborate\Cursor\PlaceProviderInterface;

final readonly class FolderPlaces implements PlaceProviderInterface
{
    public function place(Request $request): ?array
    {
        // Two addresses of one folder are one room; another page has none.
        return 'folder' === $request->attributes->get('_route')
            ? ['folder', $this->folderOf($request)]
            : null;
    }

    public function canEnter(UserInterface $user, array $place): bool
    {
        return $this->access->canRead($user, $place[1]);
    }
}
```

The grant carries the place (readable by its holder, signed), and every
session and event request asks `canEnter()` again: a place the user lost is
refused at once (403), which stops the page from sharing. A page in no place
renders no layer.

A page that replaces its content without loading (a listing fetched by a
script) enters another place with the grant the new content carries:

```twig
<div id="listing" data-grant="{{ collaborate_grant() }}">…</div>
```

```js
document.dispatchEvent(new CustomEvent('collaborate:enter', { detail: { grant } }));
// or: cursorsFor().enter(grant), with cursorsFor from '@janalis/collaborate/cursors/cursors.js'
```

A grant to the same room keeps the stream; another one leaves the first room
and joins the next.

### Regions, records and popups

- `regions` names the parts of the page a pointer is placed from, the first
  one an element is in naming it.
- With `key_attribute` (`data-key`), a pointer on a record is placed from
  the record, whatever element shows it: a row on one page, a tile on
  another. A record outside `key_scope` (an inspector showing the reader's
  own selection, say) is never named.
- An element carrying `data-collaborate-popup="<kind>"` beside the key
  attribute, in an open `<dialog>` or `.modal`, tells peers which record's
  popup the reader has open, whether or not their pointer moves. Under its
  title (`[data-collaborate-popup-presence]` if the page puts one there, the
  top of the popup otherwise) a status says who else has it open. A path in a
  popup is the popup's: it names nothing of another popup of the same shape.
- `caret_regions` keeps the carets of other regions (a search box) to their
  reader.

### What peers see

- `markers` outlines the records peers point at or have a popup of, with
  their initials; `edges` draws an arrow at the edge of a pane for a pointer
  scrolled out of it.
- `idle` dims a pointer that has not moved for that long; its name shows
  while it moves.
- `top_layer` keeps pointers above a modal `<dialog>`, which sits in the top
  layer.
- `presence: menu` makes the avatars a button, named after what it shows
  (initials, the count of the others) then the peers' names, so that speech
  control finds it by what it reads; its menu lists each peer with
  where they are (the record they point at by its `data-collaborate-label`,
  their popup, idle), finds one (scrolled to, pointed out, announced to
  screen readers), and hides the pointers of the others in every tab of the
  browser (`localStorage`), the reader's own still shared.
- `distinct_colors` keeps neighbouring colours apart on each page.

### Fewer requests

- `silent_alone` sends nothing while the reader is alone; a lost hello is
  sent again, and peers who stop hearing each other say hello again. A
  reader who moved while alone tells where they are once a first peer
  answers.
- Past three peers, a tab sends less often, down to four times the throttle.
- `share_stream`: over HTTP/1.1 a browser opens six connections to a site,
  all tabs together, and each stream holds one. One tab shares its cursor at
  a time; the others wait for its turn, which it keeps from place to place.

### Styles without Bootstrap 5

`_cursors.scss` needs Bootstrap 5's functions and variables. Elsewhere, use
the module it loads, with the colours of `colors`:

```scss
@use 'collaborate/assets/scss/layer' with (
  $colors: ('blue': #0d6efd, 'red': #dc3545),
);
```

Each colour is drawn with black or white, whichever reads best (or
`$contrasts`). The rest follows custom properties a page may set:
`--collaborate-font`, `--collaborate-ring`, `--collaborate-muted-bg`,
`--collaborate-muted-fg`, `--collaborate-menu-bg`, `--collaborate-menu-fg`,
`--collaborate-menu-border`, `--collaborate-menu-hover` and
`--collaborate-focus`, each defaulting to system colours.

### Publishing in a worker

The bundle publishes after the response (`kernel.terminate`). A server that
publishes in process, and whose runtime terminates the kernel outside the
request it served, can flush `HubPublisher::publish()` on a late
`kernel.response` listener instead; a decorator of
`Janalis\Collaborate\Hub\Transport\HubTransportInterface` can then
publish each `Notice` (its `topics` and its JSON `data`) with the server's
own function.
