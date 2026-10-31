import { renderConflicts } from '../../../assets/draft/conflicts.js';

test('renders external and current draft values as text and delegates explicit reconciliation', () => {
  document.body.innerHTML =
    '<form><label for="name">Name</label><input id="name" name="Record[name]" value="Draft"></form><aside></aside>';
  const session = {
    form: document.querySelector('form'),
    conflictPanel: document.querySelector('aside'),
    formName: (name) => name,
    configuration: {
      messages: {
        savedVersion: 'Saved',
        draftVersion: 'Draft',
        keepDraft: 'Keep',
        useSaved: 'Use saved',
        conflictsTitle: 'Review',
      },
    },
    setStatus: vi.fn(),
    reconcile: vi.fn(),
  };
  const conflicts = { 'Record[name]': '<script>external</script>' };
  renderConflicts(session, conflicts);
  expect(session.conflictPanel.hidden).toBe(false);
  expect(session.conflictPanel.querySelector('script')).toBeNull();
  expect(session.conflictPanel.textContent).toContain('<script>external</script>');
  expect(session.conflictPanel.querySelector('summary').textContent).toBe('Name');
  session.form.elements.namedItem('Record[name]').value = 'Updated draft';
  renderConflicts(session, conflicts);
  expect(session.conflictPanel.querySelector('[data-draft-field]').textContent).toBe('Updated draft');
  session.conflictPanel.querySelector('[data-collaborate-reconcile="keep"]').click();
  session.conflictPanel.querySelector('[data-collaborate-reconcile="saved"]').click();
  expect(session.reconcile.mock.calls).toEqual([[true], [false]]);
  renderConflicts(session, {});
  expect(session.conflictPanel.hidden).toBe(true);
});

import { describe as describeValue, draftValue, fieldLabel, renderDiff } from '../../../assets/draft/conflicts.js';

const messages = {
  savedVersion: 'Saved',
  draftVersion: 'Draft',
  keepDraft: 'Keep',
  useSaved: 'Use saved',
  conflictsTitle: 'Differences with the saved record',
  entryRemoved: 'Entry removed',
  entryPresent: 'Entry present',
  orderChanged: 'Order changed',
  yes: 'Yes',
  no: 'No',
};

function session(html, fields = {}, history = new Map()) {
  document.body.innerHTML = `<form id="record">${html}</form><section></section>`;
  return {
    form: document.querySelector('form'),
    conflictPanel: document.querySelector('section'),
    formName: (name) => name.replace(/^en:/, ''),
    configuration: { messages },
    state: { fields },
    history,
    setStatus: vi.fn(),
    reconcile: vi.fn(),
  };
}

test('names a choice by its options, a peer pick by the label its event carried, and an unknown id by its number', () => {
  const current = session(
    '<select name="Record[owner]" multiple><option value="1">Alice</option><option value="2" selected>Bob</option></select>',
    { 'Record[owner]': { type: 'value' } },
    new Map([['Record[owner]', [{ kind: 'value', labels: { 3: 'Carol' } }]]])
  );
  expect(describeValue(current, 'Record[owner]', ['1', '3', '9'])).toBe('Alice, Carol, #9');
  expect(describeValue(current, 'Record[owner]', '')).toBe('');
  expect(draftValue(current, 'Record[owner]')).toEqual(['2']);
  const single = session('<select name="Record[type]"><option value="a">Allusion</option></select>', {
    'Record[type]': { type: 'value' },
  });
  expect(draftValue(single, 'Record[type]')).toBe('a');
  single.form.querySelector('select').replaceChildren();
  expect(draftValue(single, 'Record[type]')).toBe('');
});

test('reads a checkbox as Yes or No and a radio by its label', () => {
  const current = session(
    '<input type="checkbox" name="Record[public]" checked>' +
      '<input type="radio" id="a" name="Record[kind]" value="a" checked><label for="a">Article</label>' +
      '<input type="radio" id="b" name="Record[kind]" value="b"><label for="b">Highlight</label>',
    { 'Record[public]': { type: 'value' }, 'Record[kind]': { type: 'value' } }
  );
  expect(describeValue(current, 'Record[public]', true)).toBe('Yes');
  expect(describeValue(current, 'Record[public]', '0')).toBe('No');
  expect(describeValue(current, 'Record[public]', 'false')).toBe('No');
  expect(draftValue(current, 'Record[public]')).toBe(true);
  expect(describeValue(current, 'Record[kind]', 'b')).toBe('Highlight');
  expect(describeValue(current, 'Record[kind]', 'z')).toBe('');
  expect(draftValue(current, 'Record[kind]')).toBe('a');
});

test('reads rich text without its markup, from the editor rather than its stale source', () => {
  const current = session('<textarea name="Record[body]"><p>Stale</p></textarea>', {
    'Record[body]': { type: 'rich' },
  });
  expect(describeValue(current, 'Record[body]', '<h2>Title</h2><p>One <b>bold</b><br>two</p><script>x</script>')).toBe(
    'Title\nOne bold\ntwo\nx'
  );
  expect(describeValue(current, 'Record[body]', '**Markdown** stays')).toBe('**Markdown** stays');
  expect(describeValue(current, 'Record[body]', null)).toBe('');
  expect(draftValue(current, 'Record[body]')).toBe('<p>Stale</p>');
  const editable = document.createElement('div');
  editable.className = 'ck-editor__editable';
  editable.ckeditorInstance = { sourceElement: current.form.querySelector('textarea'), getData: () => '<p>Live</p>' };
  document.body.append(editable);
  expect(draftValue(current, 'Record[body]')).toBe('<p>Live</p>');
});

test('describes collection structure in words and anything else as JSON', () => {
  const current = session('<input name="Record[note]">', {
    'Record[items][@a]': { type: 'member', value: { alive: true, order: 1 } },
    'Record[items][__collection__]': { type: 'collection', value: ['a'] },
  });
  expect(describeValue(current, 'Record[items][@a]', { alive: false })).toBe('Entry removed');
  expect(describeValue(current, 'Record[items][@a]', { alive: true, order: 2 })).toBe('Entry present');
  expect(draftValue(current, 'Record[items][@a]')).toEqual({ alive: true, order: 1 });
  expect(describeValue(current, 'Record[items][__collection__]', ['a', 'b'])).toBe('Order changed');
  expect(describeValue(current, 'Record[note]', { a: 1 })).toBe('{\n  "a": 1\n}');
  expect(draftValue(current, 'Record[missing]')).toBe('');
});

test('labels a field by its own label, its group, its collection, or its name at last', () => {
  const current = session(
    '<div data-collaborate-collection="Record[items]"><label>Items</label>' +
      '<div class="form-group"><label for="t">Title</label><input id="t" name="Record[items][0][title]"></div>' +
      '<fieldset class="form-group"><legend>Kind</legend><input type="radio" name="Record[items][0][kind]" value="a"></fieldset>' +
      '<input name="Record[items][0][bare]"></div><input name="Record[alone]">'
  );
  expect(fieldLabel(current, 'en:Record[items][0][title]')).toBe('Items › Title');
  expect(fieldLabel(current, 'Record[items][0][kind]')).toBe('Items › Kind');
  expect(fieldLabel(current, 'Record[items][0][bare]')).toBe('Items');
  expect(fieldLabel(current, 'Record[alone]')).toBe('Record[alone]');
});

test('names an entry and a collection order after their collection, and a control by its accessible name', () => {
  const current = session(
    '<fieldset data-collaborate-collection="Record[items]"><legend>Items</legend>' +
      '<div class="field-collection-item" data-collaborate-item="a"><input type="hidden" name="Record[items][a][position]" value="1">' +
      '<input aria-label="Name" name="Record[items][a][name]" value=" alpha "></div>' +
      '<div class="field-collection-item" data-collaborate-item="b"><input name="Record[items][b][name]" value=""></div>' +
      '</fieldset><div data-collaborate-collection="Record[bare]"></div>',
    {
      'Record[items][@a]': { type: 'member' },
      'Record[items][@b]': { type: 'member' },
      'Record[items][@gone]': { type: 'member' },
      'Record[items][__collection__]': { type: 'collection' },
      'Record[bare][@a]': { type: 'member' },
      'Record[items][a][name]': { type: 'text' },
      'Record[other][@a]': { type: 'value' },
    }
  );
  expect(fieldLabel(current, 'Record[items][@a]')).toBe('Items › alpha');
  // A row whose text is empty, or that is no longer on the page, is named after its collection alone.
  expect(fieldLabel(current, 'Record[items][@b]')).toBe('Items');
  expect(fieldLabel(current, 'Record[items][@gone]')).toBe('Items');
  expect(fieldLabel(current, 'Record[items][__collection__]')).toBe('Items');
  // A collection without a label, a state of another type: the usual lookup, down to the name.
  expect(fieldLabel(current, 'Record[bare][@a]')).toBe('Record[bare][@a]');
  expect(fieldLabel(current, 'Record[other][@a]')).toBe('Record[other][@a]');
  expect(fieldLabel(current, 'Record[items][a][name]')).toBe('Items › Name');
});

test('marks the words a draft removed and added, as text only', () => {
  document.body.innerHTML = '<pre></pre><pre></pre>';
  const [saved, draft] = document.querySelectorAll('pre');
  renderDiff(saved, draft, 'The <b>old</b> title', 'The new <b>old</b> title');
  expect(draft.querySelector('ins').textContent).toBe('new ');
  expect(saved.querySelector('del')).toBeNull();
  expect(draft.querySelector('b')).toBeNull();
  expect(draft.textContent).toBe('The new <b>old</b> title');
  renderDiff(saved, draft, 'Remove this one word', 'Remove word');
  expect(saved.querySelectorAll('del')).toHaveLength(1);
  expect(saved.querySelector('del').textContent).toMatch(/this.*one/);
  renderDiff(saved, draft, 'Same', 'Same');
  expect(saved.children).toHaveLength(0);
  // Past the cap, both sides show whole.
  const long = Array.from({ length: 1001 }, (_, index) => `w${index}`).join(' ');
  renderDiff(saved, draft, long, `${long} more`);
  expect(draft.querySelector('ins')).toBeNull();
  expect(draft.textContent).toBe(`${long} more`);
});

test('titles the review, labels the region by it and refreshes both sides as the draft changes', () => {
  const current = session('<label for="n">Name</label><input id="n" name="Record[name]" value="Draft text">');
  renderConflicts(current, { 'Record[name]': 'Saved text' });
  const heading = current.conflictPanel.querySelector('h2');
  expect(heading.textContent).toBe('Differences with the saved record');
  expect(heading.tabIndex).toBe(-1);
  expect(current.conflictPanel.getAttribute('aria-labelledby')).toBe(heading.id);
  expect(current.conflictPanel.querySelector('del').textContent).toBe('Saved');
  expect(current.conflictPanel.querySelector('ins').textContent).toBe('Draft');
  current.form.elements.namedItem('Record[name]').value = 'Saved text again';
  renderConflicts(current, { 'Record[name]': 'Saved text' });
  expect(current.conflictPanel.querySelector('[data-draft-field] ins').textContent).toBe(' again');
  expect(current.conflictPanel.querySelector('[data-saved-field] del')).toBeNull();
  expect(current.setStatus).toHaveBeenLastCalledWith('conflict');
});
