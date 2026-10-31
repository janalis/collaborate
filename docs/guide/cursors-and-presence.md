# Cursors and presence

Every page that renders the [layer](./getting-started#_3-render-the-layer-and-the-presence)
shows its other collaborators, as long as
[`cursors.enabled`](./configuration#cursors) is on (the default). No cursor
state is written to the database.

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
