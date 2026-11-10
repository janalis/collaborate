import { bridge, markedPage, NONE, registerBridge, resetBridges } from '../../assets/bridge.js';

afterEach(() => {
  resetBridges();
  document.body.innerHTML = '';
  delete document.body.dataset.collaboratePage;
});

const layer = (config) => {
  document.body.innerHTML = `<div data-collaborate-layer data-config='${config}'></div>`;
};

describe('markedPage', () => {
  it('reads the page the host names on its body', () => {
    document.body.dataset.collaboratePage = 'Article:index';
    expect(markedPage(document.body)).toEqual({ entity: 'Article', kind: 'index' });
    document.body.dataset.collaboratePage = 'node:detail';
    expect(markedPage(document.body)).toEqual({ entity: 'node', kind: 'detail' });
  });

  it('reads a page that names no entity, and nothing else', () => {
    document.body.dataset.collaboratePage = 'edit';
    expect(markedPage(document.body)).toEqual({ entity: null, kind: 'edit' });
    document.body.dataset.collaboratePage = 'Article:dashboard';
    expect(markedPage(document.body)).toBeNull();
    delete document.body.dataset.collaboratePage;
    expect(markedPage(document.body)).toBeNull();
  });
});

describe('bridge', () => {
  it('is no bridge until an entry point registers one', () => {
    expect(bridge()).toBe(NONE);
    expect(NONE.lists).toBeNull();
    expect(NONE.regions.body).toBe('body');
  });

  it("completes a registered profile with no bridge's options and takes it without a layer", () => {
    const profile = registerBridge({ name: 'cms', rows: ['.item'] });
    expect(profile).toMatchObject({ name: 'cms', rows: ['.item'], entries: [], lists: null });
    expect(profile.regions).toBe(NONE.regions);
    expect(bridge()).toBe(profile);
  });

  it('takes the bridge the layer names when it is registered, the registered one otherwise', () => {
    const cms = registerBridge({ name: 'cms' });
    const other = registerBridge({ name: 'other' });
    layer('{"bridge":"cms"}');
    expect(bridge()).toBe(cms);
    layer('{"bridge":"none"}');
    expect(bridge()).toBe(NONE);
    layer('{"bridge":"unknown"}');
    expect(bridge()).toBe(other);
    layer('{"bridge":7}');
    expect(bridge()).toBe(other);
    layer('{}');
    expect(bridge()).toBe(other);
  });

  it('reads a malformed configuration as naming none', () => {
    registerBridge({ name: 'cms' });
    layer('{nope');
    expect(bridge().name).toBe('cms');
    document.body.innerHTML = '<div data-collaborate-layer data-config="null"></div>';
    expect(bridge().name).toBe('cms');
  });

  it('forgets the registered profiles', () => {
    registerBridge({ name: 'cms' });
    resetBridges();
    layer('{"bridge":"cms"}');
    expect(bridge()).toBe(NONE);
  });
});
