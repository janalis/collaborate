import { addRowInitializer, initializeRow } from '../../../assets/draft/hooks.js';

test('runs every registered initializer on a row until it is removed', () => {
  const row = document.createElement('div');
  const first = vi.fn();
  const second = vi.fn();
  const removeFirst = addRowInitializer(first);
  const removeSecond = addRowInitializer(second);
  initializeRow(row);
  expect(first).toHaveBeenCalledWith(row);
  expect(second).toHaveBeenCalledWith(row);
  removeFirst();
  initializeRow(row);
  expect(first).toHaveBeenCalledOnce();
  expect(second).toHaveBeenCalledTimes(2);
  removeSecond();
});

test('an initializer that throws does not keep the others from running', () => {
  const error = vi.spyOn(console, 'error').mockImplementation(() => {});
  const failure = new Error('broken widget');
  const removeFailing = addRowInitializer(() => {
    throw failure;
  });
  const after = vi.fn();
  const removeAfter = addRowInitializer(after);
  initializeRow(document.createElement('div'));
  expect(after).toHaveBeenCalledOnce();
  expect(error).toHaveBeenCalledWith(failure);
  removeFailing();
  removeAfter();
  error.mockRestore();
});
