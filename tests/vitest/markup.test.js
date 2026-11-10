// EasyAdmin's markup: its bridge's profile.
import '../../assets/bridges/easyadmin.js';
import {
  entriesOf,
  entrySelector,
  parseEntry,
  placeholderOf,
  prototypeOf,
  rowOf,
  rowSelector,
} from '../../assets/markup.js';

const html = (markup) => {
  document.body.innerHTML = markup;
  return document.body;
};

describe('rowOf', () => {
  it("finds the row a stock theme renders with the extension's attribute", () => {
    html('<div data-collaborate-row class="mb-3"><label>Title</label><input name="a[title]"></div>');
    expect(rowOf(document.querySelector('input'))).toBe(document.querySelector('[data-collaborate-row]'));
  });

  it("finds EasyAdmin's and earlier templates' .form-group row", () => {
    html('<div class="form-group"><input name="a[title]"></div>');
    expect(rowOf(document.querySelector('input'))).toBe(document.querySelector('.form-group'));
  });

  it('finds the innermost row of a nested field', () => {
    html(
      '<div data-collaborate-row id="outer"><div data-collaborate-row id="inner"><input name="a[b][c]"></div></div>'
    );
    expect(rowOf(document.querySelector('input')).id).toBe('inner');
  });

  it('is null outside a row and for no element', () => {
    html('<input name="a[title]">');
    expect(rowOf(document.querySelector('input'))).toBeNull();
    expect(rowOf(null)).toBeNull();
    expect(rowOf(undefined)).toBeNull();
  });

  it("exposes the selectors it reads, the extension's first and the bridge's after", () => {
    expect(rowSelector()).toBe('[data-collaborate-row], .form-group');
    expect(entrySelector()).toBe('[data-collaborate-entry], .field-collection-item');
  });
});

describe('entriesOf', () => {
  it("finds the collection's own widget, not a nested collection's", () => {
    html(`
      <div data-collaborate-collection="a[tags]" id="tags">
        <div data-collaborate-entries="a[tags]" id="widget">
          <div data-collaborate-entry>
            <div data-collaborate-collection="a[tags][0][notes]">
              <div data-collaborate-entries="a[tags][0][notes]" id="nested"></div>
            </div>
          </div>
        </div>
      </div>`);
    expect(entriesOf(document.getElementById('tags')).id).toBe('widget');
    expect(entriesOf(document.querySelector('[data-collaborate-collection="a[tags][0][notes]"]')).id).toBe('nested');
  });

  it("ignores a nested collection's widget when the collection shows none of its own", () => {
    html(`
      <div data-collaborate-collection="a[tags]" id="tags">
        <div data-collaborate-collection="a[tags][0][notes]"><div data-collaborate-entries="a[tags][0][notes]"></div></div>
      </div>`);
    expect(entriesOf(document.getElementById('tags'))).toBeNull();
  });

  it('is null without a widget', () => {
    html('<div data-collaborate-collection="a[tags]"></div>');
    expect(entriesOf(document.querySelector('div'))).toBeNull();
  });
});

describe('prototypeOf and placeholderOf', () => {
  it('reads the prototype and placeholder on the row first (EasyAdmin)', () => {
    html(`<div data-collaborate-collection="a[t]" data-prototype="row" data-form-type-name-placeholder="__t__">
      <div data-collaborate-entries="a[t]" data-prototype="widget" data-collaborate-placeholder="__w__"></div></div>`);
    const row = document.querySelector('[data-collaborate-collection]');
    expect(prototypeOf(row)).toBe('row');
    expect(placeholderOf(row)).toBe('__t__');
  });

  it("reads them on the widget (Symfony's themes)", () => {
    html(`<div data-collaborate-collection="a[t]">
      <div data-collaborate-entries="a[t]" data-prototype="widget" data-collaborate-placeholder="__w__"></div></div>`);
    const row = document.querySelector('[data-collaborate-collection]');
    expect(prototypeOf(row)).toBe('widget');
    expect(placeholderOf(row)).toBe('__w__');
  });

  it('defaults the placeholder to __name__ and has no prototype otherwise', () => {
    html('<div data-collaborate-collection="a[t]"></div>');
    const row = document.querySelector('div');
    expect(prototypeOf(row)).toBeUndefined();
    expect(placeholderOf(row)).toBe('__name__');
  });
});

describe('parseEntry', () => {
  it('builds a div entry', () => {
    const node = parseEntry('  <div data-collaborate-entry><input name="a[t][k]"></div> ');
    expect(node.tagName).toBe('DIV');
    expect(node.querySelector('input').name).toBe('a[t][k]');
  });

  it('builds a table row entry (form_table_layout)', () => {
    const node = parseEntry('<tr data-collaborate-entry><td><input name="a[t][k]"></td></tr>');
    expect(node.tagName).toBe('TR');
    expect(node.querySelector('input').name).toBe('a[t][k]');
  });

  it('is null for an empty prototype', () => {
    expect(parseEntry('')).toBeNull();
  });
});
