import { resetBridges } from '../../../assets/bridge.js';
import { DRUPAL } from '../../../assets/bridges/drupal.js';
import { EASYADMIN } from '../../../assets/bridges/easyadmin.js';

afterAll(() => resetBridges());

afterEach(() => {
  document.body.className = '';
  delete document.body.dataset.collaboratePage;
  document.body.innerHTML = '';
});

// The regions of src/Bridge/Bridge.php, which the server validates a pointer's region against.
describe('regions', () => {
  it("are EasyAdmin's layout", () => {
    expect(EASYADMIN.regions).toEqual({
      modal: '.modal.show',
      header: '.content-top',
      'mobile-header': '.responsive-header',
      sidebar: '.ea-sidebar, .sidebar',
      content: '.content-wrapper',
      body: 'body',
    });
  });

  it("are Claro's and Gin's", () => {
    expect(DRUPAL.regions).toEqual({
      modal: '.ui-dialog',
      header: '#toolbar-bar, .gin-secondary-toolbar, .region-header',
      sidebar: '.layout-region--secondary, .gin-sidebar',
      content: '.region-content, .layout-region--main, main',
      body: 'body',
    });
  });
});

describe('EasyAdmin', () => {
  it('reads the page from its body class, the marked page first', () => {
    document.body.className = 'ea ea-index ea-index-Article';
    expect(EASYADMIN.page(document.body)).toEqual({ entity: 'Article', kind: 'index' });
    document.body.className = 'ea ea-detail';
    expect(EASYADMIN.page(document.body)).toEqual({ entity: null, kind: 'detail' });
    document.body.className = 'ea ea-dashboard';
    expect(EASYADMIN.page(document.body)).toBeNull();
    document.body.dataset.collaboratePage = 'Tag:index';
    expect(EASYADMIN.page(document.body)).toEqual({ entity: 'Tag', kind: 'index' });
  });

  it('reloads an embedded list by its own event and tells the links it binds', () => {
    document.body.innerHTML =
      '<div class="field-embedded-list" data-src="/list"><a data-ea-fragment-bound="1"></a><a></a></div>';
    const list = document.querySelector('.field-embedded-list');
    const reload = vi.fn();
    list.addEventListener('ea.embedded-list.reload', reload);
    EASYADMIN.lists.reload(list);
    expect(reload).toHaveBeenCalledOnce();
    const [bound, plain] = list.querySelectorAll('a');
    expect(EASYADMIN.lists.bound(bound)).toBe(true);
    expect(EASYADMIN.lists.bound(plain)).toBe(false);
  });
});

describe('Drupal', () => {
  it('reads the page the module marks, and has no embedded lists', () => {
    document.body.dataset.collaboratePage = 'node:index';
    expect(DRUPAL.page(document.body)).toEqual({ entity: 'node', kind: 'index' });
    expect(DRUPAL.lists).toBeNull();
    expect(DRUPAL.collections.changed).toEqual(['collaborate:rows-changed']);
  });
});
