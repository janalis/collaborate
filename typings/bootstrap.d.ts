// Bootstrap ships its modal as an ES module without types: the part of it the state bar uses. Under Drupal the
// module's drupal/js/modal.js stands in for it, with the same surface.
declare module 'bootstrap/js/src/modal.js' {
  export default class Modal {
    constructor(element: Element, options?: { backdrop?: boolean | 'static'; keyboard?: boolean });
    show(): void;
    hide(): void;
    static getOrCreateInstance(
      element: Element,
      options?: { backdrop?: boolean | 'static'; keyboard?: boolean }
    ): Modal;
  }
}
