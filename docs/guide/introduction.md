# What is collaborate?

`janalis/collaborate` is a Symfony bundle for admin pages that several
people use at the same time. It adds three things:

- **Shared drafts.** People editing the same record share one durable
  draft. Text and rich text, choices, checkboxes and collections stay in
  sync as they type. Save goes through the form's normal validation and
  persistence; a record saved elsewhere meanwhile opens a review of the
  differences, and Discard resets the draft for everyone. See
  [Shared drafts](./shared-drafts).
- **Presence and cursors.** The header shows who else is on the page. Their
  pointers, carets, selections and the field each one is in are drawn in
  their color. See [Cursors and presence](./cursors-and-presence).
- **Saved-view refresh.** Lists and detail pages reload when a save
  elsewhere changes what they show. See [Saved-view refresh](./saved-views).

The bundle is a framework-level **core** (forms, Twig, controllers, the
journal) plus an **EasyAdmin adapter**, loaded when EasyAdminBundle is
registered. Without EasyAdmin, one small service tells the bundle which
record a page edits ([Without EasyAdmin](./without-easyadmin)).

## How it works

- **One Mercure stream per page.** A page holds a single subscription that
  carries its cursor room, the drafts of its forms and the saved-view
  notices. Each payload names its `channel`, since Mercure does not tell a
  subscriber which topic an event came from, and a browser keeps only six
  HTTP/1.1 connections per origin.
- **Signed grants.** The layout renders a *page grant* (who the user is,
  which page, until when) and each shared form a *field grant* (which
  record, which fields, until when), both signed with the kernel secret and
  bound to a hash of what admits the user. The session endpoint mints a
  short-lived subscriber JWT for exactly the topics those grants prove.
- **A journal in your database.** Draft content never travels through the
  hub. Every change is stored in three tables and read back over
  authenticated, CSRF-protected requests; Mercure only says "something
  changed". Writers of one draft serialize on a MariaDB/MySQL named lock, so
  a hub outage loses nothing that was committed.
- **Operational transformation on the clients.** The server orders and
  bounds batches of CKEditor model operations; CKEditor's own
  transformations merge concurrent edits in every browser. Plain text fields
  use the same model, one paragraph long.
- **No Node service.** Everything server-side is PHP: the controllers, the
  journal and the purge command. The browser side is a set of native ES
  modules served by AssetMapper.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.4 or later |
| Symfony | 7.4 or 8 (FrameworkBundle, SecurityBundle, TwigBundle, Form, CSRF protection, sessions) |
| Doctrine | ORM 3, DBAL 4, DoctrineBundle 2.13 or 3 |
| Database | MariaDB or MySQL 8: the journal uses `GET_LOCK`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE` and `SELECT … FOR UPDATE` |
| Mercure | `symfony/mercure-bundle` and a hub whose subscriber tokens the bundle signs with the hub's own token factory |
| Front end | Symfony AssetMapper; `ckeditor5`, `@microsoft/fetch-event-source` and `bootstrap/js/src/modal.js` in the import map; Bootstrap 5's Sass sources for the styles |
| Optional | `easycorp/easyadmin-bundle` 4.24+ or 5 (the adapter), `gedmo/doctrine-extensions` 3.13+ (per-locale draft fields for translatable properties) |

The continuous integration runs PHP 8.4 and 8.5 with Symfony 7.4 and 8.1,
against MariaDB 10.11 and 11.4.

## Glossary

| Term | Meaning |
|---|---|
| Layer | The hidden `<div data-collaborate-layer>` rendered by `collaborate_layer()`: it carries the page grant, the CSRF token and the client settings. |
| Page grant | A signed token naming the user and the page's cursor room. Every hub request carries it. |
| Field grant | A signed token naming a form's draft, its fields and their schema. Every draft request carries it, or its signature once the server knows it. |
| Draft | The shared state of one record (or of one new-record page): its fields, revisions and journal. |
| Journal | The ordered batches of changes of a draft, in `collaborate_draft_event`. |
| Generation | A draft's epoch. Compaction moves it on; pages still on the old one reload or stop. |
| Collaborator | A signed-in user that the identity provider gives an id and that holds the configured role. |
