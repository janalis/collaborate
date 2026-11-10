// @ts-check
// Drupal's administration pages, in Claro or Gin: their regions, Form API's items and multi-value tables, the
// page the module names on the body, Views' bulk selection, jQuery UI dialogs and drop buttons. Rows added by
// Form API's AJAX ("Add another item") are announced by the module's behavior as `collaborate:rows-changed`.
import { registerBridge } from '../bridge.js';

export const DRUPAL = registerBridge({
  name: 'drupal',
  regions: {
    modal: '.ui-dialog',
    header: '#toolbar-bar, .gin-secondary-toolbar, .region-header',
    sidebar: '.layout-region--secondary, .gin-sidebar',
    content: '.region-content, .layout-region--main, main',
    body: 'body',
  },
  rows: ['.js-form-item', '.js-form-wrapper'],
  entries: ['tr.draggable'],
  selection: 'input[type="checkbox"][name$="_bulk_form[]"], .views-field-bulk-form input[type="checkbox"]',
  overlays: '.ui-dialog, .dropbutton-wrapper.open, [aria-expanded="true"].dropbutton__toggle',
  collections: { containers: ['tbody'], added: null, changed: ['collaborate:rows-changed'] },
});
