// @ts-check
// EasyAdmin's pages: its layout's regions, its form theme's rows and collection items, the page its body class
// names, its embedded lists, its batch selection, Bootstrap's overlays, its collection and slug widgets, and its
// save buttons.
import { markedPage, registerBridge } from '../bridge.js';

export const EASYADMIN = registerBridge({
  name: 'easyadmin',
  regions: {
    modal: '.modal.show',
    header: '.content-top',
    'mobile-header': '.responsive-header',
    // `.ea-sidebar` from EasyAdmin 5.6, `.sidebar` before.
    sidebar: '.ea-sidebar, .sidebar',
    content: '.content-wrapper',
    body: 'body',
  },
  // EasyAdmin's own classes, and those of templates written for earlier versions.
  rows: ['.form-group'],
  entries: ['.field-collection-item'],
  // EasyAdmin names the page's entity in its body class (`ea-index-Product`, `ea-edit-Product`…).
  page: (body) => {
    const marked = markedPage(body);
    if (marked) return marked;
    const kind = /\bea-(index|detail|edit|new)\b/.exec(body.className)?.[1];
    const entity = /\bea-(?:index|detail|edit|new)-(\S+)/.exec(body.className)?.[1] ?? null;
    return kind ? { entity, kind } : null;
  },
  lists: {
    selector: '.field-embedded-list[data-src]',
    loading: ':not([data-loaded])',
    reload: (list) => list.dispatchEvent(new Event('ea.embedded-list.reload')),
    loaded: 'ea.embedded-list.refreshed',
    // A link or form EasyAdmin binds inside a list loads its page in place.
    bound: (control) => Boolean(control.dataset.eaFragmentBound),
  },
  selection: '.form-batch-checkbox',
  overlays: '.modal.show, .offcanvas.show, [aria-expanded="true"].dropdown-toggle',
  collections: {
    containers: [
      '.ea-form-collection-items .accordion > .form-widget-compound [data-empty-collection]',
      '.ea-form-collection-items > .accordion',
      '.ea-form-collection-items',
    ],
    added: 'ea.collection.item-added',
    changed: ['ea.collection.item-added', 'ea.collection.item-removed'],
  },
  // EasyAdmin's slug widget writes its field, without an event, when its target is typed in or its lock clicked.
  slug: '[data-ea-slug-field]',
  // `saveAndContinue` keeps the editor on the record; never "save and add another", which opens an empty form.
  submitters: ['saveAndContinue', 'saveAndReturn'],
});
