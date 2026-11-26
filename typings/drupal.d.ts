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
    /** Attaches a field's editor for its text format: `format.editor` names it. */
    editorAttach?: (element: HTMLTextAreaElement, format: unknown) => unknown;
    /** Drupal's editors by name; `format` is its settings for a field's text format. */
    editors?: {
      ckeditor5?: {
        attach(this: unknown, element: HTMLTextAreaElement, format: unknown): unknown;
        /** `trigger` is `serialize` when Drupal only reads the editor's data back into its field. */
        detach(this: unknown, element: HTMLTextAreaElement, format: unknown, trigger?: string): unknown;
      };
    };
  };
  /** The text formats with an editor, by format ID, and the editor each uses (`ckeditor5`). */
  drupalSettings?: { editor?: { formats?: Record<string, { editor?: string }> } };
  jQuery?: (element: Element) => { trigger(event: string): unknown };
  /** The DLL's modules are typed by the bundle's own `ckeditor5` imports, not here. */
  CKEditor5?: {
    dll?: (path: string) => Record<string, unknown>;
    collaborate?: object;
    /** The editor class Drupal creates its editors with. */
    editorClassic?: { ClassicEditor: { create(...args: unknown[]): Promise<unknown> } };
  };
}
