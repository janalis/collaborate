// What the host runs on a collection row this bundle draws: a row a peer added arrives as the bare
// prototype, without the widgets the host builds on page load (a rich-text editor, say).
const rowInitializers = new Set();

/** Registers `initializer(row)`, run on every collection row added from the draft; the returned function removes it. */
export function addRowInitializer(initializer) {
  rowInitializers.add(initializer);
  return () => rowInitializers.delete(initializer);
}

/** Runs every registered initializer on `row`; one that throws does not keep the others from running. */
export function initializeRow(row) {
  for (const initializer of rowInitializers) {
    try {
      initializer(row);
    } catch (error) {
      console.error(error);
    }
  }
}
