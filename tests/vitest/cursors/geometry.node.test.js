// @vitest-environment node

// A host's Node scripts (a Playwright helper) import the geometry for its constants: it loads without a document.
it('loads without a document, on the regions of no bridge', async () => {
  expect(typeof document).toBe('undefined');
  const { CURSOR_SHAPES, regionNames } = await import('../../../assets/cursors/geometry.js');
  const { NONE } = await import('../../../assets/bridge.js');
  expect(CURSOR_SHAPES).toBeDefined();
  expect(regionNames()).toEqual(Object.keys(NONE.regions));
});
