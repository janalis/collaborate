import { createTurn, isMultiplexed, LOCK } from '../../../assets/cursors/turn.js';

// A lock manager that grants the lock to one holder at a time, in the order they asked.
function lockManager() {
  const queue = [];
  let held = false;
  const next = () => {
    if (held || !queue.length) return;
    const { callback, resolve, reject, signal } = queue.shift();
    if (signal?.aborted) {
      reject(new DOMException('aborted', 'AbortError'));
      next();
      return;
    }
    held = true;
    Promise.resolve(callback()).then(
      (value) => {
        held = false;
        resolve(value);
        next();
      },
      (error) => {
        held = false;
        reject(error);
        next();
      }
    );
  };
  return {
    requested: [],
    request(name, { signal }, callback) {
      this.requested.push(name);
      return new Promise((resolve, reject) => {
        const waiting = { callback, resolve, reject, signal };
        signal?.addEventListener('abort', () => {
          const index = queue.indexOf(waiting);
          if (index < 0) return;
          queue.splice(index, 1);
          reject(new DOMException('aborted', 'AbortError'));
        });
        queue.push(waiting);
        queueMicrotask(next);
      });
    },
  };
}

const settled = async () => {
  for (let index = 0; index < 5; index++) await Promise.resolve();
};

test('a page over HTTP/2 or 3 needs no turn', async () => {
  vi.spyOn(performance, 'getEntriesByType').mockReturnValue([{ nextHopProtocol: 'h2' }]);
  expect(isMultiplexed()).toBe(true);
  performance.getEntriesByType.mockReturnValue([{ nextHopProtocol: 'h3' }]);
  expect(isMultiplexed()).toBe(true);
  performance.getEntriesByType.mockReturnValue([{ nextHopProtocol: 'http/1.1' }]);
  expect(isMultiplexed()).toBe(false);
  performance.getEntriesByType.mockReturnValue([]);
  expect(isMultiplexed()).toBe(false);
  const locks = lockManager();
  await createTurn(locks, () => true).take();
  await createTurn(undefined, () => false).take();
  expect(locks.requested).toEqual([]);
  vi.restoreAllMocks();
});

test('one tab at a time holds the turn, the next takes it once it is given', async () => {
  const locks = lockManager();
  const first = createTurn(locks, () => false);
  const second = createTurn(locks, () => false);
  const order = [];
  first.take().then(() => order.push('first'));
  second.take().then(() => order.push('second'));
  await settled();
  expect(order).toEqual(['first']);
  expect(locks.requested).toEqual([LOCK, LOCK]);
  // Taken again while held, the same turn.
  await first.take();
  expect(locks.requested).toHaveLength(2);
  first.give();
  await settled();
  expect(order).toEqual(['first', 'second']);
  second.give();
  second.give();
});

test('a turn given up before it came is let go of at once; one the browser fails to give is asked again', async () => {
  const locks = lockManager();
  const holder = createTurn(locks, () => false);
  const waiting = createTurn(locks, () => false);
  await holder.take();
  const refused = waiting.take();
  waiting.give();
  await expect(refused).rejects.toThrow('aborted');
  holder.give();
  await settled();
  // The lock reached a turn given up already: it is let go of.
  const late = createTurn(
    {
      request: (_name, _options, callback) =>
        Promise.resolve().then(() => {
          late.give();
          return callback();
        }),
    },
    () => false
  );
  late.take();
  await settled();
  const failing = createTurn({ request: () => Promise.reject(new Error('no locks')) }, () => false);
  await expect(failing.take()).rejects.toThrow('no locks');
  await expect(failing.take()).rejects.toThrow('no locks');
  // A turn failed after another was asked for keeps the newer one.
  let fail;
  const racing = createTurn(
    {
      request: () =>
        new Promise((_, reject) => {
          fail ??= reject;
        }),
    },
    () => false
  );
  const old = racing.take();
  racing.give();
  racing.take();
  fail(new Error('late'));
  await expect(old).rejects.toThrow('late');
});
