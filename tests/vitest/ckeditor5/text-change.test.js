import { rebaseText, textChange } from '../../../assets/ckeditor5/text-change.js';

test.each([
  ['abc', 'aXbc', { prefix: 1, end: 1, inserted: 'X' }],
  ['abc', 'ac', { prefix: 1, end: 2, inserted: '' }],
  ['a😀b', 'a😁b', { prefix: 1, end: 3, inserted: '😁' }],
  ['a😀b', 'a🧑b', { prefix: 1, end: 3, inserted: '🧑' }],
  ['', '😀', { prefix: 0, end: 0, inserted: '😀' }],
  ['😀', String.fromCodePoint(0x1fa00), { prefix: 0, end: 2, inserted: String.fromCodePoint(0x1fa00) }],
  ['é', 'é', { prefix: 0, end: 1, inserted: 'é' }],
])('computes a Unicode-safe change from %s to %s', (before, after, expected) => {
  expect(textChange(before, after)).toEqual(expected);
});

test.each([
  ['unchanged source takes the remote text', 'ab', 'ab', 'aXb', 'aXb'],
  ['remote change before the local edit', 'ab\n\n**bo', 'ab\n\n', 'aXb\n\n', 'aXb\n\n**bo'],
  ['remote change after the local edit', '**bo\n\nab', '\n\nab', '\n\naXb', '**bo\n\naXb'],
  ['overlapping changes keep the remote text', 'aYc', 'abc', 'aXc', 'aXc'],
  ['remote deletion after a longer local edit', 'a123\nbc', 'a\nbc', 'a\nc', 'a123\nc'],
])('rebases a remote change onto an unsaved source rewrite: %s', (_case, edited, before, after, expected) => {
  expect(rebaseText(edited, before, after)).toBe(expected);
});
