// The EasyAdmin entry point: shared cursors and presence, saved-view refresh and shared drafts, each
// starting on its own once the page is parsed.
//
// Every module captures `window.fetch` when it is evaluated, so that its background requests bypass
// whatever wrapper the host installs (a loading indicator, say): the host must import this entry before
// installing any fetch wrapper.
import './cursors/cursors.js';
import './updates.js';
import './draft/session.js';

export { addRowInitializer } from './draft/hooks.js';
export { sessionFor } from './draft/session.js';
