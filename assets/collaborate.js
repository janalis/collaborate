// The entry point of any admin: shared cursors and presence, saved-view refresh and shared drafts, each
// starting on its own once the page is parsed, on the markup of the bridge the layer names (`collaborate.bridge`).
// An admin's own entry (`easyadmin.js`, `drupal.js`) registers its bridge's profile first.
//
// Every module captures `window.fetch` when it is evaluated, so that its background requests bypass
// whatever wrapper the host installs (a loading indicator, say): the host must import this entry before
// installing any fetch wrapper.
import './cursors/cursors.js';
import './updates.js';
import './draft/session.js';

export { registerBridge } from './bridge.js';
export { addRowInitializer } from './draft/hooks.js';
export { sessionFor } from './draft/session.js';
