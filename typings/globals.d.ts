// What the browsers the bundle supports have, and TypeScript's DOM library does not describe yet.
interface Element {
  /** The CKEditor 5 editor an editable belongs to, which CKEditor sets on the element. */
  ckeditorInstance?: import('ckeditor5').Editor & { sourceElement?: HTMLTextAreaElement };
}

interface Navigator {
  /** User-Agent Client Hints: Chromium's platform, which `navigator.platform` reports less exactly. */
  readonly userAgentData?: { readonly platform?: string };
}

// Drupal's globals, which its own scripts define before the module's: the module's scripts read them where Drupal
// runs (its behaviors, its editors, the CKEditor 5 build it ships as a DLL).
declare var Drupal: any;
declare var CKEditor5: any;
