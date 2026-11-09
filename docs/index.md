---
layout: home

hero:
  name: collaborate
  text: Live collaboration for Symfony admin pages
  tagline: Shared form drafts, presence and cursors, and saved-view refresh, carried by one stream per page, over Mercure or the bundle's own WebSocket server. PHP and your database on the server, no Node service.
  image:
    src: /logo.svg
    alt: collaborate, two pointers over a shared document
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: What is collaborate?
      link: /guide/introduction
    - theme: alt
      text: GitHub
      link: https://github.com/janalis/collaborate

features:
  - icon: 📝
    title: Shared drafts
    details: Editors of the same record share one durable draft of its text, choices and collections. Save goes through your form's own validation and persistence.
    link: /guide/shared-drafts
    linkText: How drafts behave
  - icon: ✍️
    title: Rich text, transformed
    details: A CKEditor 5 plugin shares the editor's model operations; CKEditor's own transformations merge concurrent edits, undo and Markdown source mode included.
    link: /guide/rich-text
    linkText: Rich text and scripts
  - icon: 👥
    title: Presence and cursors
    details: See who else is on the page, their pointers in their platform's shapes, their carets and selections, and the field each one is in.
    link: /guide/cursors-and-presence
    linkText: Cursors and presence
  - icon: 🔄
    title: Saved-view refresh
    details: Lists and detail pages reload when a save elsewhere changes what they show, without losing scroll, selection or typed filters.
    link: /guide/saved-views
    linkText: Saved-view refresh
  - icon: 🔐
    title: Signed and private
    details: Signed page and field grants, CSRF-protected requests and private hub notices that carry invalidations, never content.
    link: /guide/operations
    linkText: Operations and security
  - icon: 🧩
    title: EasyAdmin, or not
    details: The EasyAdmin adapter shares edit and new pages out of the box; any other Symfony form page needs one small service.
    link: /guide/easyadmin
    linkText: EasyAdmin adapter
---

![Alice's edit page in EasyAdmin: Bob's avatar in the header, his pointer, his selection in the summary he is editing, and the bar telling of unsaved changes](/screenshots/hero.png)
