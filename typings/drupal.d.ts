// Drupal's globals, which its own scripts define before the bundle's and the module's: its AJAX and jQuery, its
// behaviors and editors, and the CKEditor 5 build it ships as a DLL. Any of them may be missing from a page, and
// is missing outside Drupal: read from `globalThis` cast to `DrupalGlobals`, never as bare globals.

/** One of Drupal's AJAX instances, bound to an element. */
export interface DrupalAjax {
  element: Element;
  event: string;
  ajaxing: boolean;
}

export interface DrupalGlobals {
  Drupal?: {
    /** Missing from a page without core/drupal.ajax. */
    ajax?: { instances: (DrupalAjax | null)[] };
    behaviors: Record<string, { attach(context: Document | Element): void }>;
    /** Drupal's editors by name; `format` is its settings for a field's text format. */
    // biome-ignore lint/suspicious/noExplicitAny: Drupal's editor settings, which each text format shapes
    editors?: { ckeditor5?: { attach(this: unknown, element: HTMLTextAreaElement, format: any): unknown } };
  };
  jQuery?: (element: Element) => { trigger(event: string): unknown };
  /** The DLL's modules are typed by the bundle's own `ckeditor5` imports, not here. */
  // biome-ignore lint/suspicious/noExplicitAny: a module of the DLL, whichever its path names
  CKEditor5?: { dll?: (path: string) => any; collaborate?: object };
}
