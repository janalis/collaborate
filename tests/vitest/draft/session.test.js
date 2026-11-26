// EasyAdmin's markup: its bridge's profile.
import '../../../assets/bridges/easyadmin.js';
vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: vi.fn(() => Promise.resolve()) }));

vi.mock('../../../assets/draft/bar.js', async (original) => ({
  ...(await original()),
  confirmAction: vi.fn(async () => true),
}));

import { Model } from 'ckeditor5';
import { OperationBuffer } from '../../../assets/ckeditor5/operation-buffer.js';
import { confirmAction } from '../../../assets/draft/bar.js';
import { DraftSession, sessionFor } from '../../../assets/draft/session.js';
import { HttpError, NetworkError } from '../../../assets/http.js';

const live = [];

/** The page's hub: a draft session joins it with its channel and grant; transport is `hub.test.js`'s. */
function fakeHub() {
  const hub = {
    members: [],
    leaves: 0,
    join: vi.fn((member) => {
      hub.members.push(member);
      hub.member = member;
      return () => {
        hub.leaves++;
        hub.members = hub.members.filter((joined) => joined !== member);
      };
    }),
  };
  return hub;
}
const message = (member, data) => member.receive({ data: JSON.stringify(data) }, data);

function fixture(
  html = '<input name="Record[name]">',
  fields = { 'Record[name]': { type: 'text', initial: '' } },
  overrides = {}
) {
  document.body.innerHTML = `<form>${html}<button type="submit">Save</button></form>`;
  const form = document.querySelector('form');
  const state = {
    generation: 1,
    revision: 0,
    savedRevision: 0,
    recordId: null,
    fields: {},
    events: [],
    rebasedFrom: [],
    ...overrides.state,
  };
  for (const [name, field] of Object.entries(fields))
    state.fields[name] = { ...field, value: field.initial, saved: field.initial, version: null };
  const messages = {
    connecting: 'Connecting',
    saved: 'Saved',
    draft: 'Draft',
    pending: 'Pending',
    offline: 'Offline',
    unavailable: 'Unavailable',
    discarded: 'Discarded',
    denied: 'Denied',
    discard: 'Discard',
    confirmDiscard: 'Confirm',
    incompatible: 'Incompatible',
    created: 'Created',
    openRecord: 'Open',
    changed: 'Changed',
    failed: 'Failed',
    conflict: 'Conflict',
    savedBy: 'Saved by %name%',
    createdBy: 'Created by %name%',
    discardedBy: 'Discarded by %name%',
    confirmDiscardTitle: 'confirmDiscardTitle',
    confirmSavedTitle: 'confirmSavedTitle',
    noticeDiscardedOwn: 'You discarded',
    noticeDiscarded: 'Discarded, reloaded',
    noticeDiscardedBy: '%name% discarded, reloaded',
    noticeUsedSaved: 'Uses saved',
  };
  const configuration = {
    grant: `${btoa(JSON.stringify({ fields }))}.signature`,
    csrf: 'csrf',
    url: '/__action__',
    messages,
    editUrl: '/record/__record__/edit',
    ...overrides.configuration,
  };
  const fetcher = vi.fn(async (url, options) => {
    const body = JSON.parse(options.body);
    if (url === '/join') await overrides.join?.();
    if (url === '/join' && overrides.failJoin) return new Response('{}', { status: overrides.joinStatus || 503 });
    if (url === '/append') {
      const field = state.fields[body.field];
      if (field.version !== null && body.version !== field.version) return new Response('{}', { status: 409 });
      field.initialVersion ??= body.version;
      field.version = body.version + (body.operations?.length || 1);
      const event = { ...body, revision: ++state.revision };
      state.events.push(event);
      return Response.json(event);
    }
    return Response.json({ ...state, events: state.events.filter((event) => event.revision > (body.since || 0)) });
  });
  const hub = fakeHub();
  const session = new DraftSession(form, configuration, fetcher, hub);
  live.push(session);
  return { session, form, state, fetcher, hub, configuration };
}

afterEach(() => {
  for (const session of live.splice(0)) session.stop();
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  confirmAction.mockReset().mockResolvedValue(true);
  sessionStorage.clear();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

test('keeps startup controls inert until Join and rich editor registration finish, preserving original restrictions', async () => {
  let release;
  const join = new Promise((resolve) => {
    release = resolve;
  });
  const { session, form } = fixture(
    '<input name="Record[name]" readonly><select name="Record[type]" disabled><option>a</option></select><textarea name="Record[body]"></textarea><div id="restricted" inert>Restricted</div>',
    {
      'Record[name]': { type: 'text', initial: 'Original' },
      'Record[type]': { type: 'value', initial: 'a' },
      'Record[body]': { type: 'rich', initial: 'Body' },
    },
    { join: () => join }
  );
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  expect(form.querySelector('select').hasAttribute('inert')).toBe(true);
  expect(session.bar.element.hasAttribute('inert')).toBe(false);
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(false);
  release();
  await session.ready;
  expect(form.querySelector('textarea').hasAttribute('inert')).toBe(true);
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  expect(form.querySelector('input').readOnly).toBe(true);
  expect(form.querySelector('select').disabled).toBe(true);
  expect(form.querySelector('textarea').hasAttribute('inert')).toBe(false);
  expect(form.querySelector('#restricted').hasAttribute('inert')).toBe(true);
});

test.each([
  ['a model version differing from the draft', (session) => session.register('Record[body]', { version: 9 })],
  [
    'a corrupt operation history',
    (session) => {
      session.remember({ field: 'Record[body]', kind: 'operations', version: 0 });
      return session.register('Record[body]', {
        receive: () => {
          throw new Error('corrupt');
        },
      });
    },
  ],
])('releases startup controls for recovery when rich editor registration rejects %s', async (_, register) => {
  const { session, form } = fixture('<input name="Record[name]"><textarea name="Record[body]"></textarea>', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[body]': { type: 'rich', initial: 'Body', initialVersion: 1 },
  });
  await session.ready;
  session.state.fields['Record[body]'].initialVersion = 1;
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  expect(() => register(session)).toThrow();
  expect(form.dataset.collaborateState).toBe('incompatible');
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  expect(form.querySelector('textarea').hasAttribute('inert')).toBe(false);
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(false);
});

test('releases startup controls when the draft halts while an editor is still awaited', async () => {
  const { session, form } = fixture('<input name="Record[name]"><textarea name="Record[body]"></textarea>', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  session.halt('denied');
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  expect(form.querySelector('textarea').hasAttribute('inert')).toBe(false);
  expect(form.dataset.collaborateState).toBe('denied');
});

test.each([429, 503])('retries a temporary Join failure (%s) while keeping startup locked', async (joinStatus) => {
  vi.useFakeTimers();
  let attempts = 0;
  const { session, form, fetcher } = fixture(undefined, undefined, {
    joinStatus,
    get failJoin() {
      return ++attempts === 1;
    },
  });
  await vi.advanceTimersByTimeAsync(0);
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(1);
  await vi.advanceTimersByTimeAsync(1000);
  await session.ready;
  expect(attempts).toBe(2);
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  expect(form.dataset.collaborateState).toBe('saved');
});

test('keeps asking Join while a Save holds the draft for its whole lock', async () => {
  vi.useFakeTimers();
  let attempts = 0;
  const { session, form } = fixture(undefined, undefined, {
    get failJoin() {
      return ++attempts <= 10;
    },
  });
  await vi.advanceTimersByTimeAsync(10_000);
  await session.ready;
  expect(attempts).toBe(11);
  expect(form.dataset.collaborateState).toBe('saved');
});

test('falls back to the ordinary form after exhausting Join retries', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture(undefined, undefined, { failJoin: true });
  const rejected = expect(session.ready).rejects.toMatchObject({ status: 503 });
  await vi.advanceTimersByTimeAsync(11_000);
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(false);
  await vi.advanceTimersByTimeAsync(1000);
  await rejected;
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(13);
  expect(form.dataset.collaborateState).toBe('unavailable');
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(true);
  expect(form.elements.namedItem('_collaborate_grant')).toBeNull();
  await expect(session.editorConfiguration(form.querySelector('input'))).resolves.toEqual({});
});

test('mounts editors without Collaborate when Join is refused, e.g. after an editor schema change', async () => {
  const { session, form, fetcher } = fixture(
    '<textarea name="Record[body]"></textarea>',
    { 'Record[body]': { type: 'rich', initial: 'Body' } },
    { failJoin: true, joinStatus: 409 }
  );
  const configuration = session.editorConfiguration(form.querySelector('textarea'));
  await expect(session.ready).rejects.toMatchObject({ status: 409 });
  await expect(configuration).resolves.toEqual({});
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(1);
  expect(form.querySelector('textarea').hasAttribute('inert')).toBe(false);
  expect(form.dataset.collaborateState).toBe('unavailable');
});

test.each([403, 409])('does not retry a permanent Join failure (%s)', async (joinStatus) => {
  const { session, fetcher } = fixture(undefined, undefined, { failJoin: true, joinStatus });
  await expect(session.ready).rejects.toThrow();
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(1);
});

test('stopping during the retry delay prevents another Join request', async () => {
  vi.useFakeTimers();
  const { session, fetcher } = fixture(undefined, undefined, { failJoin: true });
  const rejected = expect(session.ready).rejects.toThrow();
  await vi.advanceTimersByTimeAsync(0);
  session.stop();
  await vi.advanceTimersByTimeAsync(1000);
  await rejected;
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(1);
});

test('does not leave widget clones inert after startup finishes', async () => {
  let release;
  const join = new Promise((resolve) => {
    release = resolve;
  });
  const { session, form } = fixture(
    '<div><input name="Record[name]"><button class="widget" type="button">Widget</button></div>',
    undefined,
    { join: () => join }
  );
  const original = form.querySelector('.widget');
  const clone = original.cloneNode(true);
  original.replaceWith(clone);
  release();
  await session.ready;
  expect(clone.closest('[inert]')).toBeNull();
});

test('sends the digest of the field states it holds and keeps them when a replay returns none', async () => {
  const { session, state, fetcher } = fixture(undefined, undefined, { state: { digest: 'joined' } });
  await session.ready;
  expect(session.digest).toBe('joined');
  const held = session.state.fields['Record[name]'];
  fetcher.mockResolvedValueOnce(Response.json({ ...state, fields: [], events: [], digest: 'joined' }));
  await session.pull();
  const replays = fetcher.mock.calls.filter(([url]) => url === '/replay');
  expect(JSON.parse(replays.at(-1)[1].body)).toMatchObject({ since: 0, digest: 'joined' });
  expect(session.state.fields['Record[name]']).toEqual(held);
  fetcher.mockResolvedValueOnce(
    Response.json({ ...state, fields: { 'Record[name]': { ...held, saved: 'Saved elsewhere' } }, digest: 'changed' })
  );
  await session.pull();
  expect(session.digest).toBe('changed');
  expect(session.state.fields['Record[name]'].saved).toBe('Saved elsewhere');
  await session.pull();
  expect(JSON.parse(fetcher.mock.calls.filter(([url]) => url === '/replay').at(-1)[1].body).digest).toBe('changed');
});

test('sends the stamp of the draft row it read, keeps everything on an unchanged answer, and drops it with a new grant', async () => {
  const { session, state, form, fetcher, configuration } = fixture(undefined, undefined, {
    state: { stamp: 'joined', conflicts: { 'Record[name]': 'external' } },
  });
  await session.ready;
  expect(session.stamp).toBe('joined');
  const lastReplay = () => JSON.parse(fetcher.mock.calls.filter(([url]) => url === '/replay').at(-1)[1].body);
  const status = form.dataset.collaborateState;
  expect(form.querySelectorAll('[data-collaborate-reconcile]')).toHaveLength(2);
  const before = Date.now();
  fetcher.mockResolvedValueOnce(Response.json({ unchanged: true, stamp: 'joined' }));
  await session.pull();
  expect(lastReplay()).toMatchObject({ since: 0, stamp: 'joined' });
  expect(session.pulled).toBeGreaterThanOrEqual(before);
  expect(session.revision).toBe(0);
  expect(form.dataset.collaborateState).toBe(status);
  expect(form.querySelectorAll('[data-collaborate-reconcile]')).toHaveLength(2);
  fetcher.mockResolvedValueOnce(Response.json({ ...state, events: [], stamp: 'written' }));
  await session.pull();
  expect(session.stamp).toBe('written');
  await session.refreshConfiguration({ ...configuration });
  expect(lastReplay().stamp).toBeNull();
});

test('leaves the offline status on an unchanged answer once the stream or a poll comes back', async () => {
  vi.useFakeTimers();
  const { session, hub, form, fetcher } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  hub.member.opened();
  await vi.advanceTimersByTimeAsync(200);
  expect(form.dataset.collaborateState).toBe('saved');
  hub.member.closed();
  expect(form.dataset.collaborateState).toBe('offline');
  fetcher.mockResolvedValueOnce(Response.json({ unchanged: true }));
  hub.member.opened();
  await vi.advanceTimersByTimeAsync(200);
  expect(form.dataset.collaborateState).toBe('saved');
  // A failed poll, then one nobody wrote to since.
  fetcher.mockRejectedValueOnce(new TypeError('Failed to fetch'));
  session.schedule(0);
  await vi.advanceTimersByTimeAsync(0);
  expect(form.dataset.collaborateState).toBe('offline');
  fetcher.mockResolvedValueOnce(Response.json({ unchanged: true }));
  await vi.advanceTimersByTimeAsync(200);
  expect(form.dataset.collaborateState).toBe('saved');
});

test('shows its conflicts again when an unchanged answer ends an offline spell', async () => {
  const { session, form, fetcher } = fixture(undefined, undefined, {
    state: { stamp: 'joined', conflicts: { 'Record[name]': 'external' } },
  });
  await session.ready;
  expect(form.dataset.collaborateState).toBe('conflict');
  session.setStatus('offline');
  fetcher.mockResolvedValueOnce(Response.json({ unchanged: true, stamp: 'joined' }));
  await session.pull();
  expect(form.dataset.collaborateState).toBe('conflict');
});

test('reads the conflicts again with a grant taken while a replay of the previous one was running', async () => {
  const { session, state, form, fetcher, configuration } = fixture(undefined, undefined, {
    state: { stamp: 'joined' },
  });
  await session.ready;
  let release;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        release = resolve;
      })
  );
  const replay = session.pull();
  const refresh = session.refreshConfiguration({ ...configuration });
  await Promise.resolve();
  // The replay sent with the previous grant answers its record: no conflict, and the row it read.
  release(Response.json({ ...state, events: [], stamp: 'joined', conflicts: {} }));
  fetcher.mockResolvedValueOnce(
    Response.json({ ...state, events: [], stamp: 'joined', conflicts: { 'Record[name]': 'external' } })
  );
  await Promise.all([replay, refresh]);
  const replays = fetcher.mock.calls.filter(([url]) => url === '/replay');
  expect(replays).toHaveLength(2);
  expect(JSON.parse(replays[1][1].body).stamp).toBeNull();
  expect(form.querySelectorAll('[data-collaborate-reconcile]')).toHaveLength(2);
  expect(session.stamp).toBe('joined');
});

test('shares an in-flight replay with baseline refresh and flush without replaying older events', async () => {
  const field = 'Record[type]';
  const { session, form, fetcher, configuration } = fixture(
    '<select name="Record[type]"><option>a</option><option>b</option><option>c</option></select>',
    { [field]: { type: 'value', initial: 'a' } },
    { configuration: { isNew: true } }
  );
  await session.ready;
  const transport = fetcher.getMockImplementation();
  let release;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        release = resolve;
      })
  );
  const replay = session.pull();
  const refresh = session.refreshConfiguration(configuration);
  const flushing = session.flush();
  await Promise.resolve();
  expect(fetcher.mock.calls.filter(([url]) => url === '/replay')).toHaveLength(1);
  const first = { id: 'first', field, kind: 'value', version: 0, revision: 1, value: 'b' };
  const second = { id: 'second', field, kind: 'value', version: 1, revision: 2, value: 'c' };
  const snapshot = { ...session.state, revision: 2, events: [first, second] };
  release(Response.json(snapshot));
  await Promise.all([replay, refresh, flushing]);
  expect(form.querySelector('select').value).toBe('c');
  expect(session.revision).toBe(2);
  fetcher.mockResolvedValueOnce(Response.json({ ...snapshot, revision: 1, events: [first] }));
  await session.pull();
  expect(session.revision).toBe(2);
  expect(form.querySelector('select').value).toBe('c');
  fetcher.mockResolvedValueOnce(Response.json(snapshot));
  await session.pull();
  // A field registered later needs only the newest value.
  expect(session.history.get(field)).toEqual([second]);
  const select = form.querySelector('select');
  select.value = 'b';
  select.dispatchEvent(new Event('change'));
  expect(session.adapters.get(field).next().version).toBe(2);
  fetcher.mockImplementation(transport);
});

test('activates only on configured forms and sends granular text operations without the loading indicator', async () => {
  document.body.innerHTML = '<form><input></form>';
  expect(sessionFor(document.querySelector('input'))).toBeNull();
  const { session, form, state } = fixture();
  await session.ready;
  const input = form.querySelector('input');
  input.value = 'hello';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  await session.flush();
  expect(state.events[0].kind).toBe('operations');
  expect(state.events[0].operations[0].__className).toBe('InsertOperation');
  expect(session.adapters.get(input.name).pending).toHaveLength(0);
  expect(form.dataset.collaborateState).toBe('draft');
  expect(document.body.classList.contains('loading')).toBe(false);
});

test('recovers a durable text journal after recreating the page and preserves a focused selection', async () => {
  const { session, form, state } = fixture('<input name="Record[name]" value="abcd">', {
    'Record[name]': { type: 'text', initial: 'abcd' },
  });
  await session.ready;
  const input = form.querySelector('input');
  input.focus();
  input.setSelectionRange(2, 2);
  const model = session.adapters.get(input.name).model;
  const insertion = {
    id: 'remote-batch',
    field: input.name,
    version: model.document.version,
    revision: 1,
    kind: 'operations',
    operations: [
      {
        __className: 'InsertOperation',
        baseVersion: model.document.version,
        position: { root: 'main', path: [0, 0], stickiness: 'toNone' },
        nodes: [{ data: 'X' }],
        shouldReceiveAttributes: false,
      },
    ],
  };
  state.events.push(insertion);
  state.revision = 1;
  await session.pull();
  expect(input.value).toBe('Xabcd');
  expect(input.selectionStart).toBe(3);
});

test('serializes scalar changes, preserves newer local choices and silently updates TomSelect', async () => {
  const fields = { 'Record[type]': { type: 'value', initial: 'a' } };
  const { session, form, state } = fixture(
    '<select name="Record[type]"><option>a</option><option>b</option></select>',
    fields
  );
  await session.ready;
  const select = form.querySelector('select');
  select.tomselect = { setValue: vi.fn() };
  select.value = 'b';
  select.dispatchEvent(new Event('change'));
  await session.flush();
  expect(state.events[0].value).toBe('b');
  expect(select.tomselect.setValue).toHaveBeenLastCalledWith('b', true);
});

test('stops on generation changes and retains local content', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const input = form.querySelector('input');
  input.value = 'unsent';
  input.dispatchEvent(new Event('input'));
  state.generation = 2;
  await expect(session.pull()).rejects.toThrow('discarded');
  expect(input.value).toBe('unsent');
  expect(form.dataset.collaborateState).toBe('discarded');
});

test('rejoins a page that shows exactly the saved draft when the generation changes', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  state.generation = 2;
  await expect(session.pull()).rejects.toThrow('rebased');
  expect(rejoin).toHaveBeenCalledOnce();
  expect(session.stopped).toBe(true);
  expect(form.dataset.collaborateState).toBe('connecting');
  // Rejoining reloads the page (jsdom only reports that it cannot navigate).
  rejoin.mockRestore();
  expect(() => session.rejoin()).not.toThrow();
});

test('keeps the form inert while an editor is awaited and the page rejoins: what is typed would be lost', async () => {
  const { session, form, state } = fixture('<input name="Record[name]"><textarea name="Record[body]"></textarea>', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  state.generation = 2;
  await expect(session.pull()).rejects.toThrow('rebased');
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
});

test('awaits the editor of an entry added again under a key whose editor was detached', async () => {
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const field = { name: 'Record[body]' };
  const removed = (await session.editorConfiguration(field)).collaborate;
  const detached = session.editorWaiters.get('Record[body]');
  session.detachField('Record[body]');
  expect(detached.ready).toBe(true);
  const added = (await session.editorConfiguration(field)).collaborate;
  // The removed entry's editor, late, settles nothing of the new entry's, and follows nothing.
  expect(removed.current()).toBe(false);
  removed.ready();
  removed.failed();
  removed.release();
  expect(session.editorWaiters.get('Record[body]').ready).toBe(false);
  expect(session.stopped).toBeFalsy();
  expect(added.current()).toBe(true);
  added.ready();
  expect(session.editorWaiters.get('Record[body]').ready).toBe(true);
});

test('each editor of a field settles its own wait: a later one replaces it, whoever awaited the first awaiting it', async () => {
  const { session, form } = fixture('<input name="Record[name]"><textarea name="Record[body]"></textarea>', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const field = { name: 'Record[body]' };
  // The join awaits the field's editor: the first editor takes that wait.
  const awaited = session.editorWaiters.get('Record[body]');
  const first = (await session.editorConfiguration(field)).collaborate;
  expect(session.editorWaiters.get('Record[body]')).toBe(awaited);
  expect(first.getSession()).toBe(session);
  expect(first.field).toBe('Record[body]');
  // A second editor of the field (its text format switched): the first's say nothing more.
  const second = (await session.editorConfiguration(field)).collaborate;
  first.ready();
  first.failed();
  expect(awaited.ready).toBe(false);
  expect(form.querySelector('input').hasAttribute('inert')).toBe(true);
  second.ready();
  await awaited.promise;
  expect(form.querySelector('input').hasAttribute('inert')).toBe(false);
  // A ready editor taken off, its field still in the form: the field's next editor has a while to come.
  vi.useFakeTimers();
  const timers = vi.getTimerCount();
  second.release();
  expect(vi.getTimerCount()).toBe(timers + 1);
  // A third editor is awaited afresh, and takes the time given: one deadline, its own.
  const third = (await session.editorConfiguration(field)).collaborate;
  expect(vi.getTimerCount()).toBe(timers + 1);
  expect(session.editorWaiters.get('Record[body]').ready).toBe(false);
  // Gone before it was ready, its field still in the form: the time given is counted once.
  third.release();
  third.release();
  expect(vi.getTimerCount()).toBe(timers + 1);
  // The field's next editor, ready in time, holds nothing.
  const next = (await session.editorConfiguration(field)).collaborate;
  next.ready();
  expect(vi.getTimerCount()).toBe(timers);
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBeFalsy();
  // Nor does a field detached meanwhile (its entry removed).
  next.release();
  session.detachField('Record[body]');
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBeFalsy();
  const last = (await session.editorConfiguration(field)).collaborate;
  // None in time: the draft, which cannot follow the field, stops.
  last.release();
  vi.advanceTimersByTime(9_999);
  expect(session.stopped).toBeFalsy();
  vi.advanceTimersByTime(1);
  expect(session.stopped).toBe(true);
  vi.useRealTimers();
});

test('an editor not ready in time stops the draft, unless its field left the form', async () => {
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  vi.useFakeTimers();
  const field = { name: 'Record[body]' };
  // Its field gone meanwhile (no host said so): nothing waits for it.
  (await session.editorConfiguration(field)).collaborate;
  const textarea = form.querySelector('textarea');
  textarea.remove();
  vi.advanceTimersByTime(10_000);
  expect(session.editorWaiters.has('Record[body]')).toBe(false);
  expect(session.stopped).toBeFalsy();
  form.append(textarea);
  // Still there: the draft stops.
  (await session.editorConfiguration(field)).collaborate;
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBe(true);
  // A draft stopped awaits no editor.
  const timers = vi.getTimerCount();
  (await session.editorConfiguration(field)).collaborate;
  expect(vi.getTimerCount()).toBe(timers);
  vi.useRealTimers();
});

test('a ready editor gone leaves its field to its next editor in time, or stops the draft', async () => {
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const field = { name: 'Record[body]' };
  const first = (await session.editorConfiguration(field)).collaborate;
  first.ready();
  // Its field gone from the form: nothing waits for it.
  const textarea = form.querySelector('textarea');
  textarea.remove();
  first.release();
  expect(session.editorWaiters.has('Record[body]')).toBe(false);
  form.append(textarea);
  // Its field still there, and no next editor: the draft stops.
  const second = (await session.editorConfiguration(field)).collaborate;
  second.ready();
  vi.useFakeTimers();
  second.release();
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBe(true);
  vi.useRealTimers();
});

test('a rich field whose editor nobody asks for stops the draft in time, unless it left the form', async () => {
  vi.useFakeTimers();
  const { session, form } = fixture(
    '<textarea name="Record[body]"></textarea><textarea name="Record[note]"></textarea>',
    {
      'Record[body]': { type: 'rich', initial: 'Body' },
      'Record[note]': { type: 'rich', initial: 'Note' },
      'Record[plain]': { type: 'text', initial: '' },
    }
  );
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  expect(session.editorWaiters.size).toBe(2);
  // The other's field leaves the form: once its time runs out, nothing waits for it.
  const note = form.querySelector('[name="Record[note]"]');
  note.remove();
  // An editor asked for late has the whole while from its asking, once ready none.
  vi.advanceTimersByTime(9_000);
  const body = (await session.editorConfiguration({ name: 'Record[body]' })).collaborate;
  vi.advanceTimersByTime(9_000);
  expect(session.stopped).toBeFalsy();
  expect(session.editorWaiters.has('Record[note]')).toBe(false);
  body.ready();
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBeFalsy();
  // Back, and still asked for by nobody: the draft stops.
  form.append(note);
  session.editorWaiter('Record[note]');
  vi.advanceTimersByTime(10_000);
  expect(session.stopped).toBe(true);
  vi.useRealTimers();
});

test('a field followed as text that gets an editor stops the draft, and a host can ask what the draft follows', async () => {
  const { session, form } = fixture('<input name="Record[name]">', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[items][__name__][title]': { type: 'text', initial: '' },
  });
  await session.ready;
  expect(await session.follows({ name: 'Record[name]' })).toBe(true);
  // A row the draft will follow once it is one.
  expect(await session.follows({ name: 'Record[items][n1][title]' })).toBe(true);
  expect(await session.follows({ name: 'Unknown' })).toBe(false);
  expect(await session.editorConfiguration({ name: 'Unknown' })).toEqual({});
  expect(session.stopped).toBeFalsy();
  const said = vi.spyOn(console, 'error').mockImplementation(() => {});
  expect(await session.editorConfiguration({ name: 'Record[name]' })).toEqual({});
  expect(session.stopped).toBe(true);
  expect(form.dataset.collaborateState).toBe('unavailable');
  // Said, with what to configure: a field type the bundle shares as text.
  expect(said).toHaveBeenCalledWith(expect.stringContaining('drafts.rich_text.block_prefixes'));
  const refused = fixture(undefined, undefined, { failJoin: true, joinStatus: 403 });
  await expect(refused.session.ready).rejects.toThrow();
  expect(await refused.session.follows({ name: 'Record[name]' })).toBe(false);
});

test.each([
  true,
  false,
])('a Save (from its button: %s) under way as the draft stops for an editor that never came posts the form as usual', async (button) => {
  vi.useFakeTimers();
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue(undefined);
  const native = vi.fn((event) => event.preventDefault());
  form.addEventListener('submit', native);
  const submitter = button ? form.querySelector('button[type="submit"]') : null;
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter }));
  expect(native).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(10_000);
  expect(session.stopped).toBe(true);
  expect(native).toHaveBeenCalledOnce();
  expect(native.mock.calls[0][0].submitter).toBe(submitter);
  vi.useRealTimers();
});

test('a Save posts the form as usual once the draft stopped for an editor, without the save fields it added', async () => {
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue(undefined);
  // An earlier Save of this page added its fields.
  session.hidden('_collaborate_revision', '3');
  const native = vi.fn((event) => event.preventDefault());
  form.addEventListener('submit', native);
  // The flush ends as the draft stops for an editor: nothing thrown, the Save still posts.
  vi.spyOn(session, 'flush').mockImplementation(async () => {
    session.editorFailed();
  });
  const submitter = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter }));
  await vi.waitFor(() => expect(native).toHaveBeenCalledOnce());
  expect(native.mock.calls[0][0].submitter).toBe(submitter);
  expect(form.elements.namedItem('_collaborate_revision')).toBeNull();
});

test('a Save whose button left the form posts nothing, and the bar says to save again', async () => {
  const { session, form } = fixture();
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue(undefined);
  const said = vi.spyOn(console, 'error').mockImplementation(() => {});
  const native = vi.fn((event) => event.preventDefault());
  form.addEventListener('submit', native);
  const submitter = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
  // The host renders its actions again while the Save waits.
  const flush = vi.spyOn(session, 'flush').mockImplementation(async () => {
    submitter.replaceWith(submitter.cloneNode(true));
  });
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter }));
  await vi.waitFor(() => expect(said).toHaveBeenCalledWith(expect.stringContaining('save again'), expect.anything()));
  expect(native).not.toHaveBeenCalled();
  expect(form.dataset.collaborateState).toBe('notSaved');
  expect(session.bar.element.querySelector('[data-collaborate-save]')?.hidden).toBeFalsy();
  // The same as the draft stops for an editor meanwhile.
  said.mockClear();
  const again = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
  flush.mockImplementation(async () => {
    again.replaceWith(again.cloneNode(true));
    session.editorFailed();
  });
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter: again }));
  await vi.waitFor(() => expect(said).toHaveBeenCalledWith(expect.stringContaining('save again'), expect.anything()));
  expect(native).not.toHaveBeenCalled();
  // The draft stopped: said, offering no Discard it could not do.
  expect(form.dataset.collaborateState).toBe('notPosted');
  // What any Save that could not post says (this fixture's messages name it by its key).
  expect(session.bar.text.textContent).toBe('notSaved');
  expect(session.bar.element.querySelector('[data-collaborate-discard]')?.hidden).not.toBe(false);
});

test("the bar's Save that cannot post says to save again", async () => {
  const { session, form } = fixture();
  await session.ready;
  vi.spyOn(console, 'error').mockImplementation(() => {});
  vi.spyOn(form, 'requestSubmit').mockImplementation(() => {
    throw new Error('Detached.');
  });
  session.saveFromBar();
  expect(form.dataset.collaborateState).toBe('notSaved');
});

test('a page frozen in the back/forward cache gives its editors their whole time again once back', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea><textarea name="Record[note]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
    'Record[note]': { type: 'rich', initial: 'Note' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  // An editor ready already is given no time again.
  (await session.editorConfiguration({ name: 'Record[note]' })).collaborate.ready();
  vi.advanceTimersByTime(9_000);
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  vi.advanceTimersByTime(60_000);
  expect(session.stopped).toBeFalsy();
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  await vi.advanceTimersByTimeAsync(9_000);
  expect(session.stopped).toBeFalsy();
  // Its editor comes in time; the one ready before was given no time to run out.
  (await session.editorConfiguration({ name: 'Record[body]' })).collaborate.ready();
  await vi.advanceTimersByTimeAsync(30_000);
  expect(session.stopped).toBeFalsy();
  vi.useRealTimers();
});

test('an editor still awaited once the page is back has its whole time, then stops the draft', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  await vi.advanceTimersByTimeAsync(9_999);
  expect(session.stopped).toBeFalsy();
  await vi.advanceTimersByTimeAsync(1);
  expect(session.stopped).toBe(true);
  vi.useRealTimers();
});

test('a page frozen twice in a row while a save waits on an editor still gives it its time, then stops', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  const flushed = session.flush().catch((error) => error);
  for (let frozen = 0; frozen < 2; frozen += 1) {
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  }
  await vi.advanceTimersByTimeAsync(10_000);
  expect(session.stopped).toBe(true);
  expect(await flushed).toEqual(new Error('Collaborate draft stopped.'));
  vi.useRealTimers();
});

test('only an editor asked for counts: its field leaving and coming back, or the page, do not', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  const editor = (await session.editorConfiguration({ name: 'Record[body]' })).collaborate;
  for (let times = 0; times < 6; times += 1) {
    editor.release();
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    await vi.advanceTimersByTimeAsync(0);
  }
  expect(session.stopped).toBeFalsy();
  vi.useRealTimers();
});

test('a save field a host took out of the form is added again', async () => {
  const { session, form } = fixture();
  await session.ready;
  session.hidden('_collaborate_revision', '3');
  form.querySelector('[name="_collaborate_revision"]').remove();
  session.hidden('_collaborate_revision', '4');
  expect(/** @type {HTMLInputElement} */ (form.elements.namedItem('_collaborate_revision')).value).toBe('4');
  // One moved into another form would post this draft's grant there: taken out of it.
  const other = document.createElement('form');
  document.body.append(other);
  other.append(/** @type {HTMLInputElement} */ (form.elements.namedItem('_collaborate_revision')));
  session.hidden('_collaborate_revision', '5');
  expect(other.querySelector('[name="_collaborate_revision"]')).toBeNull();
  expect(/** @type {HTMLInputElement} */ (form.elements.namedItem('_collaborate_revision')).value).toBe('5');
  // Moved there again, and that form posted before any Save of this draft: taken out as it posts.
  other.append(/** @type {HTMLInputElement} */ (form.elements.namedItem('_collaborate_revision')));
  const posted = vi.fn((/** @type {SubmitEvent} */ event) => {
    event.preventDefault();
    return new FormData(other).has('_collaborate_revision');
  });
  other.addEventListener('submit', posted);
  other.dispatchEvent(new SubmitEvent('submit', { cancelable: true, bubbles: true }));
  expect(posted).toHaveReturnedWith(false);
  // This draft's own form posting keeps them.
  session.hidden('_collaborate_revision', '6');
  const own = vi.fn(() => new FormData(form).has('_collaborate_revision'));
  form.addEventListener('submit', own);
  session.stop();
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, bubbles: true }));
  expect(own).toHaveReturnedWith(true);
  other.remove();
});

test("the draft's save fields are its own: a host's control of the name is left as it is", async () => {
  const { session, form } = fixture('<input type="hidden" name="_collaborate_revision" value="host">');
  await session.ready;
  session.hidden('_collaborate_revision', '3');
  session.hidden('_collaborate_revision', '4');
  const controls = form.querySelectorAll('[name="_collaborate_revision"]');
  expect(Array.from(controls, (control) => control.value)).toEqual(['host', '4']);
  // A draft stopped otherwise than for an editor keeps them, for a host that posts later.
  session.stop();
  expect(form.querySelectorAll('[name="_collaborate_revision"]')).toHaveLength(2);
});

test('a Save failing on the page itself says to save again; one the network failed says offline', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const refresh = vi.spyOn(session, 'refreshRecord').mockResolvedValue(undefined);
  const flush = vi.spyOn(session, 'flush').mockResolvedValue(undefined);
  const said = vi.spyOn(console, 'error').mockImplementation(() => {});
  const hidden = vi.spyOn(session, 'hidden').mockImplementation(() => {
    throw new Error('Unexpected.');
  });
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('notSaved'));
  expect(said).toHaveBeenCalledWith(expect.stringContaining('save again'), new Error('Unexpected.'));
  hidden.mockRestore();
  // The network fails the flush's request: offline.
  flush.mockRestore();
  fetcher.mockRejectedValue(new TypeError('Failed to fetch'));
  session.state.fields['Record[name]'].value = 'Changed';
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  // A body the network cut, or a page that is not the server's answer: the server's part, offline too.
  session.setStatus('draft');
  fetcher.mockResolvedValue(new Response('<html>', { status: 200 }));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  // The record's refresh, its JSON cut by the network: offline, the draft kept; a sign-in page there stops it.
  refresh.mockRestore();
  session.setStatus('draft');
  fetcher.mockImplementation(async (url) =>
    url === form.action
      ? new Response('{"config', { status: 200, headers: { 'Content-Type': 'application/json' } })
      : Response.json({})
  );
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  expect(session.stopped).toBeFalsy();
  expect(said).toHaveBeenCalledWith('Collaborate:', expect.any(NetworkError));
  // An aborted Save says nothing of its own.
  refresh.mockResolvedValue(undefined);
  session.setStatus('draft');
  fetcher.mockRejectedValue(new DOMException('Aborted.', 'AbortError'));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  expect(form.dataset.collaborateState).toBe('draft');
});

test('a draft reloading its page says so: a page that stays tells its user to reload it', async () => {
  const { session, form } = fixture();
  await session.ready;
  // A Save added the draft's save fields, then was held back (the browser's validation, say).
  session.hidden('_collaborate_grant', 'grant');
  session.rejoin();
  expect(form.dataset.collaborateState).toBe('reloading');
  // Stopped: nothing of the draft it left says otherwise, its requests aborted. A Save its user chooses posts the
  // form the ordinary way, without the draft's save fields.
  expect(session.stopped).toBe(true);
  expect(session.live).toBe(false);
  expect(session.abort.signal.aborted).toBe(true);
  expect(form.querySelector('input[name="_collaborate_grant"]')).toBeNull();
  const event = new SubmitEvent('submit', { cancelable: true });
  form.dispatchEvent(event);
  expect(event.defaultPrevented).toBe(false);
});

test("a conflict's review that throws as it is brought into view is still a conflict, not a Save to press again", async () => {
  const { session, form } = fixture();
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue(undefined);
  vi.spyOn(session, 'flush').mockResolvedValue(undefined);
  session.conflicts = { 'Record[name]': 'external' };
  vi.spyOn(session.conflictPanel, 'querySelector').mockImplementation(() => {
    throw new Error('Replaced.');
  });
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('conflict'));
  await new Promise((resolve) => setTimeout(resolve, 0));
  expect(session.saving).toBe(false);
});

test('a grant renewal that fails stops the draft as denied; a failure of the network is said once until the draft is back', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const said = vi.spyOn(console, 'error').mockImplementation(() => {});
  session.failed(new TypeError('Failed to fetch'));
  session.failed(new TypeError('Failed to fetch'));
  expect(form.dataset.collaborateState).toBe('offline');
  const logged = () => said.mock.calls.filter(([prefix]) => prefix === 'Collaborate:').length;
  expect(logged()).toBe(1);
  session.setStatus('saved');
  session.failed(new TypeError('Failed to fetch'));
  expect(logged()).toBe(2);
  // A sign-in on another origin fails as the network would: denied, not retried for good.
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (url === form.action) throw new TypeError('Failed to fetch');
    if (url === '/replay') return new Response('', { status: 403 });
    return transport(url, options);
  });
  await expect(session.pull()).rejects.toThrow('permission');
  expect(form.dataset.collaborateState).toBe('denied');
});

test('a draft stopped for an editor while no Save is under way leaves none of its save fields to the next Save', async () => {
  const { session, form } = fixture();
  await session.ready;
  session.hidden('_collaborate_revision', '3');
  session.editorFailed();
  expect(form.elements.namedItem('_collaborate_revision')).toBeNull();
});

test('a draft stopped otherwise (the page left) posts nothing for a Save under way', async () => {
  const { session, form } = fixture();
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockImplementation(async () => {
    session.stop();
    throw new DOMException('Left.', 'AbortError');
  });
  const native = vi.fn((event) => event.preventDefault());
  form.addEventListener('submit', native);
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  expect(native).not.toHaveBeenCalled();
});

test('a save awaits editors at least as long as they are given to come', async () => {
  vi.useFakeTimers();
  const { session } = fixture(
    '<textarea name="Record[body]"></textarea>',
    { 'Record[body]': { type: 'rich', initial: 'Body' } },
    { configuration: { config: { nextEditorGrace: 30_000 } } }
  );
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  const flushed = session.flush().catch((error) => error);
  await vi.advanceTimersByTimeAsync(15_000);
  (await session.editorConfiguration({ name: 'Record[body]' })).collaborate.ready();
  await vi.advanceTimersByTimeAsync(0);
  expect(await flushed).toBeUndefined();
  vi.useRealTimers();
});

test('a detached field forgets the time its editor was given', async () => {
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  vi.useFakeTimers();
  (await session.editorConfiguration({ name: 'Record[body]' })).collaborate;
  const timers = vi.getTimerCount();
  session.detachField('Record[body]');
  expect(vi.getTimerCount()).toBe(timers - 1);
  // A field no editor was awaited for (a text field) has nothing to forget.
  expect(() => session.detachField('Record[name]')).not.toThrow();
  vi.useRealTimers();
});

test('a stopped draft forgets the editors it gave time to come', async () => {
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  vi.useFakeTimers();
  const editorFailed = vi.spyOn(session, 'editorFailed');
  const handle = (await session.editorConfiguration({ name: 'Record[body]' })).collaborate;
  handle.release();
  session.stop();
  vi.advanceTimersByTime(10_000);
  expect(editorFailed).not.toHaveBeenCalled();
  // Nor gives any once stopped.
  const timers = vi.getTimerCount();
  handle.release();
  expect(vi.getTimerCount()).toBe(timers);
  vi.useRealTimers();
});

test('nothing waits for the editor of a field gone from the form, or of a form gone from the page', async () => {
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const field = { name: 'Record[body]' };
  const textarea = form.querySelector('textarea');
  textarea.remove();
  const removed = (await session.editorConfiguration(field)).collaborate;
  removed.release();
  expect(session.editorWaiters.has('Record[body]')).toBe(false);
  removed.release();
  form.append(textarea);
  const gone = (await session.editorConfiguration(field)).collaborate;
  form.remove();
  gone.release();
  expect(session.editorWaiters.has('Record[body]')).toBe(false);
  document.body.append(form);
  // An editor that cannot be created, or wired once ready, stops the draft.
  const fourth = (await session.editorConfiguration(field)).collaborate;
  fourth.ready();
  fourth.failed();
  expect(session.stopped).toBe(true);
});

test('rejoins without the unsaved-changes prompt EasyAdmin keeps for a form edited since it loaded', async () => {
  const { session } = fixture();
  await session.ready;
  window.onbeforeunload = () => 'Changes may not be saved.';
  session.rejoin();
  expect(window.onbeforeunload).toBeNull();
});

test('a confirmed Discard or use of the saved record reloads by rejoining', async () => {
  const { session, fetcher } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  await session.reconcile(false);
  expect(fetcher.mock.calls.some(([url]) => url === '/reconcile')).toBe(true);
  expect(rejoin).toHaveBeenLastCalledWith({ key: 'noticeUsedSaved' });
  await session.discardDraft();
  expect(fetcher.mock.calls.some(([url]) => url === '/discard')).toBe(true);
  expect(rejoin).toHaveBeenLastCalledWith({ key: 'noticeDiscardedOwn' });
  // Each asks with its own wording.
  expect(confirmAction.mock.calls.map(([{ title }]) => title)).toEqual(['confirmSavedTitle', 'confirmDiscardTitle']);
});

test('rejoins a clean page whose draft was purged instead of polling it again', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) =>
    url === '/replay' ? new Response('{}', { status: 409 }) : transport(url, options)
  );
  session.schedule(0);
  await vi.advanceTimersByTimeAsync(60_000);
  expect(rejoin).toHaveBeenCalledOnce();
  expect(fetcher.mock.calls.filter(([url]) => url === '/replay')).toHaveLength(1);
  expect(form.dataset.collaborateState).toBe('connecting');
});

test('keeps the unsent edits of a page whose draft was purged', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) =>
    url === '/replay' ? new Response('{}', { status: 409 }) : transport(url, options)
  );
  const input = form.querySelector('input');
  input.value = 'unsent';
  input.dispatchEvent(new Event('input'));
  await vi.advanceTimersByTimeAsync(60_000);
  expect(rejoin).not.toHaveBeenCalled();
  expect(fetcher.mock.calls.filter(([url]) => url === '/replay')).toHaveLength(1);
  expect(input.value).toBe('unsent');
  expect(form.dataset.collaborateState).toBe('discarded');
});

test('a Discard naming one of its fields rejoins a clean page and keeps the unsent edits of another', async () => {
  const discard = { id: 'reset', field: '', kind: 'discard', version: 0, fields: ['Record[name]'] };
  const clean = fixture();
  await clean.session.ready;
  const rejoin = vi.spyOn(clean.session, 'rejoin').mockImplementation(() => {});
  clean.state.events.push({ ...discard, revision: ++clean.state.revision });
  clean.state.savedRevision = clean.state.revision;
  await expect(clean.session.pull()).rejects.toThrow('rebased');
  expect(rejoin).toHaveBeenCalledOnce();

  const editing = fixture();
  await editing.session.ready;
  const input = editing.form.querySelector('input');
  input.value = 'unsent';
  input.dispatchEvent(new Event('input'));
  editing.state.events.push({ ...discard, revision: ++editing.state.revision });
  await expect(editing.session.pull()).rejects.toThrow('discarded');
  expect(input.value).toBe('unsent');
  expect(editing.form.dataset.collaborateState).toBe('discarded');
  expect(editing.session.generation).toBe(1);
});

test('keeps the operations a later field replays, the newest value of a choice, and no Discard', () => {
  const { session } = fixture();
  const event = (id, field, kind) => ({ id, field, kind, version: 0, revision: 1 });
  for (const remembered of [
    event('a', 'Record[body]', 'operations'),
    event('b', 'Record[body]', 'operations'),
    event('c', 'Record[type]', 'value'),
    event('d', 'Record[type]', 'value'),
    event('e', '', 'discard'),
  ])
    session.remember(remembered);
  expect([...session.history].map(([field, events]) => [field, events.map(({ id }) => id)])).toEqual([
    ['Record[body]', ['a', 'b']],
    ['Record[type]', ['d']],
  ]);
});

test('names the revision an edit was made on, so an edit made before a Discard is refused', async () => {
  const { session, form, fetcher, state } = fixture();
  await session.ready;
  state.events.push({ id: 'peer', field: 'Other[name]', kind: 'value', version: 0, value: 'x', revision: 1 });
  state.revision = 1;
  await session.pull();
  const input = form.querySelector('input');
  input.value = 'edited';
  input.dispatchEvent(new Event('input'));
  await session.flush();
  const append = JSON.parse(fetcher.mock.calls.find(([url]) => url === '/append')[1].body);
  expect(append).toMatchObject({ field: 'Record[name]', generation: 1, seen: 1 });
});

test('keeps a page whose draft held unsaved changes when the generation changes', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  session.state.savedRevision = -1;
  state.generation = 2;
  await expect(session.pull()).rejects.toThrow('discarded');
  expect(rejoin).not.toHaveBeenCalled();
  expect(form.dataset.collaborateState).toBe('discarded');
});

test('rejoins a page that sent every edit when a compaction replaced its generation before it heard the save', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  // The page still holds the revision before a peer's save: by itself it does not look saved.
  session.state.savedRevision = -1;
  state.generation = 2;
  state.rebasedFrom = [7, 1];
  await expect(session.pull()).rejects.toThrow('rebased');
  expect(rejoin).toHaveBeenCalledOnce();
  expect(form.dataset.collaborateState).toBe('connecting');
});

test('keeps a page with unsent edits when a compaction replaced its generation', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  const input = form.querySelector('input');
  input.value = 'unsent';
  input.dispatchEvent(new Event('input'));
  state.generation = 2;
  state.rebasedFrom = [1];
  await expect(session.pull()).rejects.toThrow('discarded');
  expect(rejoin).not.toHaveBeenCalled();
  expect(input.value).toBe('unsent');
});

test('keeps a page that had not heard the save when its draft was recreated rather than compacted', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  session.state.savedRevision = -1;
  // A compaction of another generation: this page's was purged, and the draft created anew.
  state.generation = 9;
  state.rebasedFrom = [8];
  await expect(session.pull()).rejects.toThrow('discarded');
  expect(rejoin).not.toHaveBeenCalled();
  expect(form.dataset.collaborateState).toBe('discarded');
});

test('retains a new draft after creation and offers the saved record instead of creating twice', async () => {
  const { session, form, state, configuration } = fixture();
  configuration.isNew = true;
  await session.ready;
  state.recordId = '12';
  await expect(session.pull()).rejects.toThrow('created');
  expect(form.dataset.collaborateState).toBe('created');
  expect(form.querySelector('a').getAttribute('href')).toBe('/record/12/edit');
});

test('waits for rich editors, checks initial versions and releases registration on destroy', async () => {
  const { session } = fixture();
  await session.ready;
  session.state.fields.body = { type: 'rich', initial: 'initial', initialVersion: 2 };
  expect(() => session.register('body', { version: 1 })).toThrow('initialization');
  const adapter = { version: 2, receive: vi.fn(), next: () => null };
  const remove = session.register('body', adapter);
  expect(session.adapters.get('body')).toBe(adapter);
  remove();
  expect(session.adapters.has('body')).toBe(false);
  // An earlier editor of the field, destroyed once a later one registered, leaves the later one's adapter.
  const earlier = session.register('body', adapter);
  const later = { version: 2, receive: vi.fn(), next: () => null };
  session.register('body', later);
  earlier();
  expect(session.adapters.get('body')).toBe(later);
  const waiter = session.editorWaiter('body');
  waiter.resolve();
  await expect(waiter.promise).resolves.toBeUndefined();
});

test('allows only server-issued dynamic collection field patterns', async () => {
  const { session } = fixture('', { 'Record[items][__name__][title]': { type: 'text', initial: '' } });
  await session.ready;
  expect(session.ensureField('Record[items][n123][title]').type).toBe('text');
  expect(session.ensureField('Record[items][n123][password]')).toBeNull();
});

test('rejects authorization expiry and stops subsequent requests', async () => {
  const { session, fetcher } = fixture();
  await session.ready;
  // The grant expired, and the access with it: the refresh that would renew it is refused too.
  fetcher
    .mockResolvedValueOnce(new Response('{}', { status: 403 }))
    .mockResolvedValueOnce(new Response('', { status: 403 }));
  await expect(session.pull()).rejects.toThrow('permission');
  expect(session.stopped).toBe(true);
  await expect(session.flush()).rejects.toThrow('stopped');
});

test('an expired grant is renewed once from the form action, and the requests it failed go again', async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const expired = configuration.grant;
  const grant = `${expired.split('.')[0]}.renewed`;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (!options.method) return Response.json({ configuration: { ...configuration, grant }, tokens: {} });
    if (JSON.parse(options.body).grant !== grant) return new Response('{}', { status: 403 });
    return transport(url, options);
  });
  await Promise.all([session.pull(), session.request('presence', { clientId: 'peer' })]);
  expect(fetcher.mock.calls.filter(([, options]) => !options.method)).toEqual([
    [form.action, expect.objectContaining({ headers: { Accept: 'application/json', 'X-Collaborate-Refresh': '1' } })],
  ]);
  expect(session.configuration.grant).toBe(grant);
  expect(session.stopped).toBeFalsy();
});

test('a request refused for the grant a Save has since renewed goes again without renewing it twice', async () => {
  const { session, fetcher, configuration } = fixture();
  await session.ready;
  const grant = `${configuration.grant.split('.')[0]}.renewed`;
  const transport = fetcher.getMockImplementation();
  const refused = Promise.withResolvers();
  fetcher.mockImplementation(async (url, options) => {
    if (!options.method) return Response.json({ configuration: { ...configuration, grant }, tokens: {} });
    // An acknowledged grant goes as its signature alone.
    if (!grant.endsWith(JSON.parse(options.body).grant)) return refused.promise;
    return transport(url, options);
  });
  const presence = session.request('presence', { clientId: 'peer' });
  await session.refreshRecord();
  refused.resolve(new Response('{}', { status: 403 }));
  await presence;
  expect(fetcher.mock.calls.filter(([, options]) => !options.method)).toHaveLength(1);
  expect(session.stopped).toBeFalsy();
});

test('a renewed grant refused in turn stops the session', async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const grant = `${configuration.grant.split('.')[0]}.renewed`;
  fetcher.mockImplementation(async (_, options) =>
    options.method
      ? new Response('{}', { status: 403 })
      : Response.json({ configuration: { ...configuration, grant }, tokens: {} })
  );
  await expect(session.request('presence', { clientId: 'peer' })).rejects.toThrow('permission');
  expect(fetcher.mock.calls.filter(([, options]) => !options.method)).toHaveLength(1);
  expect(session.stopped).toBe(true);
  expect(form.dataset.collaborateState).toBe('denied');
});

test('a grant renewed for another schema keeps its incompatible status', async () => {
  const { session, form, fetcher, configuration } = fixture('<input name="Record[name]">', {
    'Record[name]': { type: 'text', initial: '', schema: 'v1' },
  });
  await session.ready;
  const fields = { 'Record[name]': { type: 'text', initial: '', schema: 'v2' } };
  const grant = `${btoa(JSON.stringify({ fields }))}.renewed`;
  fetcher.mockImplementation(async (_, options) =>
    options.method
      ? new Response('{}', { status: 403 })
      : Response.json({ configuration: { ...configuration, grant }, tokens: {} })
  );
  await expect(session.pull()).rejects.toThrow('permission');
  expect(form.dataset.collaborateState).toBe('incompatible');
});

test('refreshes the saved baseline with a JSON GET of the edit page and delays native submit handlers until synchronized', async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (!options.method) return Response.json({ configuration, tokens: {} });
    return transport(url, options);
  });
  const nativeSubmit = vi.fn();
  form.addEventListener('submit', nativeSubmit);
  vi.spyOn(form, 'requestSubmit').mockImplementation((submitter) => {
    form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter }));
  });
  const button = form.querySelector('button');
  const first = new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button });
  form.dispatchEvent(first);
  expect(first.defaultPrevented).toBe(true);
  expect(nativeSubmit).not.toHaveBeenCalled();
  await vi.waitFor(() => expect(form.requestSubmit).toHaveBeenCalledOnce());
  expect(nativeSubmit).toHaveBeenCalledOnce();
  const options = fetcher.mock.calls.find(([, options]) => !options.method)[1];
  expect(options.headers).toEqual({ Accept: 'application/json', 'X-Collaborate-Refresh': '1' });
  expect(form.elements.namedItem('_collaborate_revision').value).toBe('0');
});

test('posts the form once for a second Save sent while the first is still synchronizing', async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  let release;
  fetcher.mockImplementation(async (url, options) => {
    if (options.method) return transport(url, options);
    await new Promise((resolve) => {
      release = resolve;
    });
    return Response.json({ configuration, tokens: {} });
  });
  // A handler that cancels stands for a submission blocked by validation: the page stays.
  form.addEventListener('submit', (event) => event.preventDefault());
  vi.spyOn(form, 'requestSubmit').mockImplementation(() => {
    form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  });
  const first = new SubmitEvent('submit', { bubbles: true, cancelable: true });
  const second = new SubmitEvent('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(first);
  await vi.waitFor(() => expect(release).toBeTypeOf('function'));
  form.dispatchEvent(second);
  expect(second.defaultPrevented).toBe(true);
  release();
  await vi.waitFor(() => expect(form.requestSubmit).toHaveBeenCalledOnce());
  await new Promise((resolve) => setTimeout(resolve, 0));
  expect(fetcher.mock.calls.filter(([, options]) => !options.method)).toHaveLength(1);
  release = null;
  // Once that submission ended on the page, Save goes through again.
  form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  await vi.waitFor(() => expect(release).toBeTypeOf('function'));
  release();
  await vi.waitFor(() => expect(form.requestSubmit).toHaveBeenCalledTimes(2));
});

test.each([
  ['a submission that leaves the page', false, 0],
  ['a submission a handler cancelled', true, 1],
])('after %s, keeps or sends the edits typed since', async (_, cancelled, appends) => {
  vi.useFakeTimers();
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (!options.method) return Response.json({ configuration, tokens: {} });
    return transport(url, options);
  });
  if (cancelled) form.addEventListener('submit', (event) => event.preventDefault());
  vi.spyOn(form, 'requestSubmit').mockImplementation(() => {
    form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  });
  // A flush scheduled before Save still runs after it.
  session.schedule(3000);
  form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  await vi.waitFor(() => expect(form.requestSubmit).toHaveBeenCalledOnce());
  const input = form.querySelector('input');
  input.value = 'typed after Save';
  input.dispatchEvent(new Event('input'));
  await vi.advanceTimersByTimeAsync(6000);
  expect(fetcher.mock.calls.filter(([url]) => url === '/append')).toHaveLength(appends);
  // Back on the page from the browser cache, the edits go out again.
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  await session.resuming;
  await vi.advanceTimersByTimeAsync(1000);
  expect(fetcher.mock.calls.filter(([url]) => url === '/append')).toHaveLength(1);
});

test('follows its draft on the hub, replays on its notices and reports a lost stream', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher, configuration } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const stream = hub.member;
  expect(stream).toMatchObject({ channel: 'draft', draft: undefined });
  stream.opened();
  // The header's avatars show who else is on the page: the form announces nobody.
  await vi.advanceTimersByTimeAsync(5000);
  expect(fetcher.mock.calls.some(([url]) => url === '/presence')).toBe(false);
  stream.closed();
  expect(session.live).toBe(false);
  expect(session.form.dataset.collaborateState).toBe('offline');
  const pulls = fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  message(stream, { channel: 'draft', kind: 'saved' });
  await vi.advanceTimersByTimeAsync(200);
  expect(fetcher.mock.calls.filter(([url]) => url === '/replay').length).toBe(pulls + 1);
  // The hub proves the draft with its grant: its signature once the server has verified it.
  expect(stream.grant(false)).toBe(configuration.grant.split('.')[1]);
  expect(stream.grant(true)).toBe(configuration.grant);
  window.dispatchEvent(new Event('pagehide'));
  expect(session.stopped).toBe(true);
  expect(hub.members).toEqual([]);
});

test('sends the full grant until the server accepts it, then its signature, and resends it when the server lost it', async () => {
  const { session, fetcher, configuration } = fixture();
  await session.ready;
  const grants = () => fetcher.mock.calls.map(([url, options]) => [url, JSON.parse(options.body).grant]);
  expect(grants()[0]).toEqual(['/join', configuration.grant]);
  fetcher.mockClear();
  await session.request('replay', { since: 0 });
  expect(grants()).toEqual([['/replay', 'signature']]);

  fetcher.mockClear();
  fetcher.mockResolvedValueOnce(new Response('{}', { status: 412 }));
  await session.request('replay', { since: 0 });
  expect(grants()).toEqual([
    ['/replay', 'signature'],
    ['/replay', configuration.grant],
  ]);

  fetcher.mockClear();
  const renewed = { ...configuration, grant: `${configuration.grant.split('.')[0]}.renewed` };
  session.configuration = renewed;
  await session.request('replay', { since: 0 });
  await session.request('replay', { since: 0 });
  expect(grants()).toEqual([
    ['/replay', renewed.grant],
    ['/replay', 'renewed'],
  ]);

  fetcher.mockClear();
  fetcher
    .mockResolvedValueOnce(new Response('{}', { status: 412 }))
    .mockResolvedValueOnce(new Response('{}', { status: 412 }));
  await expect(session.request('replay', { since: 0 })).rejects.toMatchObject({ status: 412 });
  expect(grants()).toEqual([
    ['/replay', 'renewed'],
    ['/replay', renewed.grant],
  ]);
});

test('resolves collection controls through the current grant schema and remembers each control name', async () => {
  const fields = {
    'en:Record[items][__name__][label]': { type: 'text', initial: '', formName: 'Record[items][__name__][label]' },
  };
  const { session, configuration } = fixture('<input name="Record[items][0][label]">', fields);
  await session.ready;
  expect(session.fieldKey('Record[items][0][label]')).toBe('en:Record[items][0][label]');
  expect(session.fieldKey('Record[items][0][label]')).toBe('en:Record[items][0][label]');
  expect(session.fieldKey('Record[other]')).toBe('Record[other]');
  expect(session.definition('en:Record[items][1][label]')).toMatchObject({ formName: 'Record[items][1][label]' });
  const renamed = {
    'fr:Record[items][__name__][label]': { type: 'text', initial: '', formName: 'Record[items][__name__][label]' },
  };
  await session.refreshConfiguration({
    ...configuration,
    grant: `${btoa(JSON.stringify({ fields: renamed }))}.signature`,
  });
  expect(session.fieldKey('Record[items][0][label]')).toBe('fr:Record[items][0][label]');
  expect(session.definition('en:Record[items][1][label]')).toBeNull();
});

test('retains unsent edits and the Save guard across cached navigation, then renews and replays', async () => {
  const { session, form, hub } = fixture(undefined, undefined, { configuration: { isNew: true } });
  await session.ready;
  await vi.waitFor(() => expect(hub.join).toHaveBeenCalledOnce());
  const control = form.elements.namedItem('Record[name]');
  control.value = 'Unsent';
  control.dispatchEvent(new Event('input'));
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  expect(session.stopped).not.toBe(true);
  expect(session.abort.signal.aborted).toBe(true);
  expect(hub.members).toEqual([]);
  await expect(session.flush()).rejects.toMatchObject({ name: 'AbortError' });
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(false);
  const refresh = vi.spyOn(session, 'refreshRecord').mockResolvedValue();
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  await session.resuming;
  expect(refresh).toHaveBeenCalledOnce();
  expect(session.abort.signal.aborted).toBe(false);
  expect(session.adapters.get('Record[name]').next()).toBeNull();
  expect(control.value).toBe('Unsent');
  expect(hub.join).toHaveBeenCalledTimes(2);
});

test('finishes a frozen Join before resuming without rebuilding its adapters', async () => {
  let release;
  const join = new Promise((resolve) => {
    release = resolve;
  });
  const { session, form } = fixture(undefined, undefined, { join: () => join, configuration: { isNew: true } });
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false }));
  expect(session.resuming).toBeUndefined();
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  const recovery = session.resuming;
  window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  release();
  await recovery;
  expect(session.initialized).toBe(true);
  expect(session.suspended).toBe(false);
  expect(session.adapters.size).toBe(1);
  expect(form.querySelector('[inert]')).toBeNull();
});

test('waits for an aborted replay to settle and ignores its late response before resuming', async () => {
  const { session, fetcher, form } = fixture(undefined, undefined, { configuration: { isNew: true } });
  await session.ready;
  let release;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        release = resolve;
      })
  );
  const replay = session.pull();
  const rejected = expect(replay).rejects.toMatchObject({ name: 'AbortError' });
  session.suspend();
  const recovering = session.resume();
  expect(form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }))).toBe(false);
  release(Response.json({ ...session.state, generation: 999, events: [] }));
  await rejected;
  await recovering;
  expect(session.generation).toBe(1);
  expect(session.stopped).not.toBe(true);
  expect(session.suspended).toBe(false);
});

test('retains local content on failed recovery and does not revive a permanently stopped session', async () => {
  const { session, form } = fixture();
  await session.ready;
  const control = form.elements.namedItem('Record[name]');
  control.value = 'Retained';
  control.dispatchEvent(new Event('input'));
  vi.spyOn(session, 'refreshRecord').mockRejectedValue(new Error('Network unavailable'));
  session.suspend();
  await session.resume();
  expect(form.dataset.collaborateState).toBe('offline');
  expect(control.value).toBe('Retained');
  expect(session.adapters.get('Record[name]').next()).not.toBeNull();
  session.suspend();
  const recovery = session.resume();
  session.stop();
  await recovery;
  expect(session.abort.signal.aborted).toBe(true);
  expect(session.resume()).toBeUndefined();
});

test('keeps Save intercepted if navigation suspends the form as its flush finishes', async () => {
  const { session, form } = fixture();
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue();
  vi.spyOn(session, 'flush').mockImplementation(async () => {
    session.suspend();
  });
  const nativeSubmit = vi.spyOn(form, 'requestSubmit').mockImplementation(() => {});
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(session.flush).toHaveBeenCalledOnce());
  expect(nativeSubmit).not.toHaveBeenCalled();
});

test('shares source mode while preserving pending local choices and locking source controls during uploads', async () => {
  const name = 'Record[body]';
  const { session, form } = fixture(
    '<textarea id="body" name="Record[body]"></textarea><input type="radio" name="body_view" value="markdown"><input type="radio" name="body_view" value="wysiwyg" checked>',
    {
      [name]: { type: 'rich', initial: 'Body' },
      [`${name}[__mode__]`]: { type: 'value', initial: 'wysiwyg' },
    }
  );
  await session.ready;
  const field = form.querySelector('textarea');
  const configuration = await session.editorConfiguration(field);
  expect(configuration.collaborate.getSession()).toBe(session);
  expect(await session.editorConfiguration({ name: 'Unknown' })).toEqual({});
  configuration.collaborate.ready();
  const model = new Model();
  const root = model.document.createRoot();
  model.schema.register('paragraph', { inheritAllFrom: '$block' });
  model.schema.register('imageBlock', { inheritAllFrom: '$blockObject', allowAttributes: ['uploadId'] });
  model.change((writer) => writer.appendElement('paragraph', root));
  const apply = vi.fn();
  const remove = session.registerMode(name, { sourceElement: field, model }, apply);
  const markdown = form.querySelector('[value="markdown"]');
  markdown.checked = true;
  markdown.dispatchEvent(new Event('change'));
  const adapter = session.adapters.get(`${name}[__mode__]`);
  const pending = adapter.next();
  adapter.receive({ id: 'remote', version: 0, value: 'wysiwyg' });
  expect(markdown.checked).toBe(true);
  adapter.receive({ ...pending, version: 1, value: 'markdown' });
  adapter.receive({ id: 'remote', version: 0, value: 'wysiwyg' });
  expect(markdown.checked).toBe(true);
  markdown.dispatchEvent(new Event('change'));
  expect(adapter.next().version).toBe(2);
  field.focus();
  field.value = '**Source**';
  field.dispatchEvent(new Event('input'));
  field.blur();
  expect(apply).toHaveBeenCalledWith('**Source**');
  model.change((writer) => writer.appendElement('imageBlock', { uploadId: 'pending' }, root));
  expect(field.readOnly).toBe(true);
  expect(markdown.disabled).toBe(true);
  model.change((writer) => writer.remove(root.getChild(1)));
  expect(field.readOnly).toBe(false);
  expect(markdown.disabled).toBe(false);
  remove();
  expect(session.adapters.has(`${name}[__mode__]`)).toBe(false);
  expect(session.sources.has(name)).toBe(false);
  // An earlier editor's removal, once a later one registered, leaves the later one's mode and source.
  const earlier = session.registerMode(name, { sourceElement: field, model }, apply);
  const later = session.registerMode(name, { sourceElement: field, model }, apply);
  const mode = session.adapters.get(`${name}[__mode__]`);
  const source = session.sources.get(name);
  earlier();
  expect(session.adapters.get(`${name}[__mode__]`)).toBe(mode);
  expect(session.sources.get(name)).toBe(source);
  later();
  session.registerMode(name, { sourceElement: null }, apply)();
  model.destroy();
});

test("an editor gone before its last edits were sent (a format switched) sends them still: the field's next editor has them", async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  /** An editor's model as the draft builds it: one empty paragraph. */
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    const buffer = new OperationBuffer(model, () => session.schedule());
    return { model, buffer, paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  const first = editor();
  const unregister = session.register(name, first.buffer);
  // Two edits, two batches, each on the version the one before it leaves.
  first.model.change((writer) => writer.insertText('Typed', first.paragraph(), 0));
  first.model.change((writer) => writer.insertText(' more', first.paragraph(), 5));
  // Its format switched within the 150 ms its edits wait: the editor is destroyed, another built for the field,
  // typed in at once too.
  unregister();
  first.buffer.destroy();
  first.model.destroy();
  // Awaited as any editor of the field: a Save waits for it, what it takes over in no editor meanwhile.
  expect(session.editorWaiters.get(name)?.ready).toBe(false);
  const next = editor();
  session.register(name, next.buffer);
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  next.model.change((writer) => writer.insertText('>', next.paragraph(), 0));
  const text = () =>
    /** @type {any[]} */ (Array.from(next.paragraph().getChildren())).map((node) => node.data).join('');
  await vi.waitFor(() => expect(state.events.filter((event) => event.field === name)).toHaveLength(3));
  await vi.waitFor(() => expect(next.buffer.hasPending()).toBe(false));
  expect(text()).toMatch(/^(>Typed more|Typed more>)$/);
  expect(session.retiring).toEqual([]);
  // One gone with nothing unsent leaves nothing to send.
  const idle = editor();
  session.register(name, idle.buffer)();
  expect(session.retiring).toEqual([]);
  next.model.destroy();
  idle.model.destroy();
});

test("what an editor gone held is the next editor's own: rebased on a peer's edit first, not taken twice, dropped only off its version", async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    const buffer = new OperationBuffer(model, () => session.schedule());
    return { model, buffer, paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  const text = (/** @type {any} */ paragraph) => Array.from(paragraph.getChildren(), (node) => node.data).join('');
  const events = () => state.events.filter((event) => event.field === name);
  // An editor gone holding 'Mine'; a peer's edit reaches the draft before the field's next editor comes.
  const first = editor();
  const leave = session.register(name, first.buffer);
  first.model.change((writer) => writer.insertText('Mine', first.paragraph(), 0));
  leave();
  first.model.destroy();
  expect(session.sent()).toBe(false);
  const peer = editor();
  peer.model.change((writer) => writer.insertText('Peer', peer.paragraph(), 0));
  const batch = /** @type {any} */ (peer.buffer.next());
  state.fields[name].initialVersion = batch.version;
  state.fields[name].version = batch.version + batch.operations.length;
  state.events.push({ kind: 'operations', ...batch, field: name, revision: ++state.revision });
  await session.pull();
  // The next editor takes 'Mine' over as its own, rebased on the peer's edit, and sends it.
  const next = editor();
  session.register(name, next.buffer);
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  expect(session.retiring).toEqual([]);
  await vi.waitFor(() => expect(events()).toHaveLength(2));
  await vi.waitFor(() => expect(next.buffer.hasPending()).toBe(false));
  expect(text(next.paragraph())).toMatch(/^(PeerMine|MinePeer)$/);
  expect(session.sent()).toBe(true);
  // An editor built before the one it replaces went takes over what that one held, there and then.
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const going = editor();
  const unregisterGoing = session.register(name, going.buffer);
  going.model.change((writer) => writer.insertText('!', going.paragraph(), 0));
  const later = editor();
  session.register(name, later.buffer);
  const shown = text(next.paragraph());
  unregisterGoing();
  expect(later.buffer.hasPending()).toBe(true);
  expect(text(later.paragraph())).toBe(`!${shown}`);
  // What the draft has already (an acknowledgement on its way) is not taken over twice.
  const warned = vi.spyOn(console, 'warn').mockImplementation(() => {});
  const mine = events().find((event) => event.id !== batch.id);
  session.retiring = [
    { field: name, held: { version: mine.version, groups: [{ id: mine.id, operations: mine.operations }] } },
  ];
  const again = editor();
  session.register(name, again.buffer);
  expect(again.buffer.hasPending()).toBe(false);
  expect(text(again.paragraph())).toBe(text(next.paragraph()));
  // Taken over at its own version, after the draft's edits before it: nothing dropped.
  expect(warned).not.toHaveBeenCalled();
  // One made on another version cannot be taken over: dropped, and said.
  session.retiring = [{ field: name, held: { version: 999, groups: [{ id: 'off', operations: [] }] } }];
  const off = editor();
  session.register(name, off.buffer);
  expect(warned).toHaveBeenCalledWith(expect.stringContaining('dropped'));
  expect(off.buffer.hasPending()).toBe(false);
  // Held for a field: a conflict kept does not give it the saved value; the field removed drops it.
  const adopt = vi.fn();
  session.adapters.set(name, { next: () => null, receive() {}, adopt });
  session.state.fields[name].version = null;
  session.conflicts = { [name]: 'Saved' };
  vi.spyOn(session, 'request').mockResolvedValue(null);
  vi.spyOn(session, 'pull').mockResolvedValue(undefined);
  vi.spyOn(session, 'flush').mockResolvedValue(undefined);
  session.retiring = [{ field: name, held: { version: 0, groups: [{ id: 'held', operations: [] }] } }];
  await session.reconcile(true);
  expect(adopt).not.toHaveBeenCalled();
  session.detachField(name);
  expect(session.retiring).toEqual([]);
  session.adapters.set(name, { next: () => null, receive() {}, adopt });
  await session.reconcile(true);
  expect(adopt).toHaveBeenCalledWith('Saved');
  for (const each of [next, peer, later, going, again, off]) each.model.destroy();
});

test('what an editor gone held is rebased for a next editor that typed or followed the draft since, held for one to come; nothing for a field removed', async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const warned = vi.spyOn(console, 'warn').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    const buffer = new OperationBuffer(model, () => session.schedule());
    return { model, buffer, paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  const text = (/** @type {any} */ paragraph) => Array.from(paragraph.getChildren(), (node) => node.data).join('');
  // The next editor already typed in: what the one replaced held is rebased over its edits, both kept.
  const going = editor();
  const leave = session.register(name, going.buffer);
  const next = editor();
  session.register(name, next.buffer);
  next.model.change((writer) => writer.insertText('Next', next.paragraph(), 0));
  going.model.change((writer) => writer.insertText('Gone', going.paragraph(), 0));
  leave();
  expect(warned).not.toHaveBeenCalled();
  expect(next.buffer.held().groups).toHaveLength(2);
  expect(text(next.paragraph())).toMatch(/^(NextGone|GoneNext)$/);
  // One replaced, a peer's edit reaching the draft before it goes: the next editor moved on from its version.
  // (An edit of the draft before either came is under both, none of what the held edits are rebased over.)
  const earlier = editor();
  earlier.model.change((writer) => writer.insertText('Q', earlier.paragraph(), 0));
  const earlierBatch = /** @type {any} */ (earlier.buffer.next());
  state.fields[name].version = earlierBatch.version + earlierBatch.operations.length;
  state.events.push({ kind: 'operations', ...earlierBatch, field: name, revision: ++state.revision });
  await session.pull();
  earlier.model.destroy();
  const third = editor();
  const replacedGoing = editor();
  const leaveReplaced = session.register(name, replacedGoing.buffer);
  replacedGoing.model.change((writer) => writer.insertText('Old', replacedGoing.paragraph(), 0));
  session.register(name, third.buffer);
  const peer = editor();
  for (const event of state.events.filter((event) => event.field === name)) peer.buffer.receive(event);
  peer.model.change((writer) => writer.insertText('P', peer.paragraph(), 0));
  const peerBatch = /** @type {any} */ (peer.buffer.next());
  state.fields[name].version = peerBatch.version + peerBatch.operations.length;
  state.events.push({ kind: 'operations', ...peerBatch, field: name, revision: ++state.revision });
  await session.pull();
  const hold = vi.spyOn(third.buffer, 'hold');
  leaveReplaced();
  // Rebased over the peer's edit the next editor followed since, and only over it.
  expect(hold).toHaveBeenCalledWith(expect.anything(), peerBatch.operations);
  expect(warned).not.toHaveBeenCalled();
  expect(third.buffer.held().groups).toHaveLength(1);
  expect(text(third.paragraph())).toMatch(/^(POldQ|OldPQ)$/);
  for (const each of [replacedGoing, peer]) each.model.destroy();
  // One replaced whose replacement went first: held for the field's next editor, awaited.
  const first = editor();
  const leaveFirst = session.register(name, first.buffer);
  first.model.change((writer) => writer.insertText('First', first.paragraph(), 0));
  const second = editor();
  session.register(name, second.buffer)();
  leaveFirst();
  expect(warned).not.toHaveBeenCalled();
  expect(session.retiring).toHaveLength(1);
  expect(session.editorWaiters.get(name)?.ready).toBe(false);
  session.retiring = [];
  session.editorWaiters.get(name)?.resolve();
  for (const each of [first, second]) each.model.destroy();
  // Its field's wait, ready, running out (its editor released meanwhile): cleared, not stopping the draft later.
  const awaited = editor();
  const unregisterAwaited = session.register(name, awaited.buffer);
  awaited.model.change((writer) => writer.insertText('A', awaited.paragraph(), 0));
  const ready = /** @type {any} */ (session.editorWaiters.get(name));
  ready.grace = setTimeout(() => {}, 60_000);
  const cleared = vi.spyOn(globalThis, 'clearTimeout');
  unregisterAwaited();
  expect(cleared).toHaveBeenCalledWith(ready.grace);
  cleared.mockRestore();
  session.retiring = [];
  awaited.model.destroy();
  // One going before its field was awaited at all: its next editor is awaited from then.
  session.editorWaiters.delete(name);
  const early = editor();
  const unregisterEarly = session.register(name, early.buffer);
  early.model.change((writer) => writer.insertText('Early', early.paragraph(), 0));
  unregisterEarly();
  expect(session.retiring).toHaveLength(1);
  expect(session.editorWaiters.get(name)?.ready).toBe(false);
  session.retiring = [];
  early.model.destroy();
  // A field removed, its editor destroyed after: nothing held, nothing awaited.
  const row = editor();
  const unregisterRow = session.register(name, row.buffer);
  const rowNext = editor();
  session.register(name, rowNext.buffer);
  row.model.change((writer) => writer.insertText('Row', row.paragraph(), 0));
  session.detachField(name);
  // The row removed, what its replaced editor holds is nothing the page owes the draft.
  expect(session.sent()).toBe(true);
  // An entry added again under its key meanwhile: its editor takes nothing of the removed row's.
  const added = editor();
  session.register(name, added.buffer);
  unregisterRow();
  expect(added.buffer.hasPending()).toBe(false);
  session.detachField(name);
  added.model.destroy();
  rowNext.model.destroy();
  expect(session.retiring).toEqual([]);
  expect(session.editorWaiters.has(name)).toBe(false);
  // A hand-over its next editor cannot apply: the draft stops, incompatible, the editor's going undisturbed.
  const failing = editor();
  const leaveFailing = session.register(name, failing.buffer);
  failing.model.change((writer) => writer.insertText('F', failing.paragraph(), 0));
  const unable = editor();
  session.register(name, unable.buffer);
  vi.spyOn(unable.buffer, 'hold').mockImplementation(() => {
    throw new Error('Cannot apply.');
  });
  expect(() => leaveFailing()).not.toThrow();
  expect(form.dataset.collaborateState).toBe('incompatible');
  for (const each of [going, next, third, row, failing, unable]) each.model.destroy();
});

test('a replaced editor hands over what follows its batch the draft acknowledged; a hand-over failing at registration stops the draft, the editor registered', async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const warned = vi.spyOn(console, 'warn').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    const buffer = new OperationBuffer(model, () => session.schedule());
    return { model, buffer, paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  const text = (/** @type {any} */ paragraph) => Array.from(paragraph.getChildren(), (node) => node.data).join('');
  // Editor A sends 'Sent' (the draft acknowledges it after B came), then types '!' it has not sent.
  const a = editor();
  const leaveA = session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('Sent', a.paragraph(), 0));
  const sent = /** @type {any} */ (a.buffer.next());
  const b = editor();
  session.register(name, b.buffer);
  state.fields[name].initialVersion = sent.version;
  state.fields[name].version = sent.version + sent.operations.length;
  state.events.push({ kind: 'operations', ...sent, field: name, revision: ++state.revision });
  await session.pull();
  a.model.change((writer) => writer.insertText('!', a.paragraph(), 4));
  // Replaced, not gone yet: what it holds is unsent.
  expect(session.sent()).toBe(false);
  leaveA();
  expect(warned).not.toHaveBeenCalled();
  expect(b.buffer.held().groups).toHaveLength(1);
  expect(text(b.paragraph())).toBe('Sent!');
  // One replaced whose only batch the draft has, its replacement gone first: nothing to hand over, nothing said.
  const d = editor();
  const leaveD = session.register(name, d.buffer);
  d.model.change((writer) => writer.insertText('D', d.paragraph(), 0));
  const dSent = /** @type {any} */ (d.buffer.next());
  const e = editor();
  const leaveE = session.register(name, e.buffer);
  state.fields[name].version = dSent.version + dSent.operations.length;
  state.events.push({ kind: 'operations', ...dSent, field: name, revision: ++state.revision });
  await session.pull();
  leaveE();
  leaveD();
  expect(warned).not.toHaveBeenCalled();
  // Gone, it is owed nothing more.
  expect(session.replaced.some((other) => other.adapter === d.buffer)).toBe(false);
  expect(session.retiring).toEqual([]);
  for (const each of [d, e]) each.model.destroy();
  // One taken over as the next editor registers, which cannot apply it: the draft stops; the editor is registered.
  session.retiring = [{ field: name, held: { version: sent.version, groups: [{ id: 'held', operations: [] }] } }];
  const c = editor();
  vi.spyOn(c.buffer, 'hold').mockImplementation(() => {
    throw new Error('Cannot apply.');
  });
  const unregisterC = session.register(name, c.buffer);
  expect(form.dataset.collaborateState).toBe('incompatible');
  expect(session.adapters.get(name)).toBe(c.buffer);
  unregisterC();
  for (const each of [a, b, c]) each.model.destroy();
});

test('a flush under way as an editor goes holding edits waits for the next one and sends them; a renewal failing offline is offline', async () => {
  const name = 'Record[body]';
  const { session, form, state, fetcher } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    return { model, buffer: new OperationBuffer(model), paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  const a = editor();
  const leaveA = session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('Held', a.paragraph(), 0));
  // The editor goes as the flush pulls, past its wait for editors.
  const pull = session.pull.bind(session);
  vi.spyOn(session, 'pull').mockImplementationOnce(async () => {
    leaveA();
    await pull();
  });
  const flushing = session.flush();
  await vi.waitFor(() => expect(session.editorWaiters.get(name)?.ready).toBe(false));
  const b = editor();
  session.register(name, b.buffer);
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  await flushing;
  expect(state.events.filter((event) => event.field === name)).toHaveLength(1);
  expect(b.buffer.hasPending()).toBe(false);
  // A grant's renewal failing while the browser is offline: the network's failure, not a refusal.
  const offline = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (url === form.action) throw new TypeError('Failed to fetch');
    if (url === '/replay') return new Response('', { status: 403 });
    return transport(url, options);
  });
  await expect(session.pull()).rejects.toThrow('Failed to fetch');
  expect(session.stopped).toBeFalsy();
  offline.mockRestore();
  for (const each of [a, b]) each.model.destroy();
});

test('a replaced editor owes nothing the draft acknowledged; only editors are kept as replaced, destroyed with their field; a flush ends as the draft stops', async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    return { model, buffer: new OperationBuffer(model), paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  // A sent its edit; B replaced it; the draft acknowledged the edit, heard by B: A owes nothing.
  const a = editor();
  session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('Sent', a.paragraph(), 0));
  const sent = /** @type {any} */ (a.buffer.next());
  const b = editor();
  session.register(name, b.buffer);
  state.fields[name].initialVersion = sent.version;
  state.fields[name].version = sent.version + sent.operations.length;
  state.events.push({ kind: 'operations', ...sent, field: name, revision: ++state.revision });
  await session.pull();
  expect(a.buffer.hasPending()).toBe(true);
  expect(session.sent()).toBe(true);
  // A value control replaced is not kept: it has no going to wait for.
  const control = { next: () => null, receive() {} };
  session.register(name, control);
  const c = editor();
  session.register(name, c.buffer);
  expect(session.replaced.map((other) => other.adapter)).not.toContain(control);
  // The field removed: its replaced editors are destroyed with it; another field's are not.
  const destroy = vi.spyOn(a.buffer, 'destroy');
  const elsewhere = { field: 'Record[other]', adapter: { next: () => null, receive() {}, destroy: vi.fn() } };
  session.replaced.push(elsewhere);
  session.detachField(name);
  expect(destroy).toHaveBeenCalled();
  expect(elsewhere.adapter.destroy).not.toHaveBeenCalled();
  expect(session.replaced).toEqual([elsewhere]);
  session.replaced = [];
  // A flush waiting for an editor as the draft stops ends, rather than waiting on.
  const d = editor();
  const leaveD = session.register(name, d.buffer);
  d.model.change((writer) => writer.insertText('D', d.paragraph(), 0));
  leaveD();
  expect(session.editorWaiters.get(name)?.ready).toBe(false);
  const pull = session.pull.bind(session);
  vi.spyOn(session, 'pull').mockImplementationOnce(async () => {
    session.editorFailed();
    await pull().catch(() => {});
  });
  session.editorWaiters.get(name)?.resolve();
  const waiter = session.editorWaiter(name);
  waiter.ready = false;
  await expect(
    Promise.race([
      session.flush().then(
        () => 'ended',
        () => 'ended'
      ),
      new Promise((resolve) => setTimeout(() => resolve('spinning'), 500)),
    ])
  ).resolves.toBe('ended');
  for (const each of [a, b, c, d]) each.model.destroy();
});

test('a reload keeps inert controls so; held edits go with their field; a replaced editor holding edits keeps its field from the saved value; a renewal the server failed is retried', async () => {
  const name = 'Record[body]';
  const { session, form, fetcher } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  // Held edits whose field left the form (its wait let go): nothing to go to, nothing owed.
  session.retiring = [{ field: name, held: { version: 0, groups: [{ id: 'gone', operations: [] }] } }];
  session.letGo(name);
  expect(session.retiring).toEqual([]);
  expect(session.sent()).toBe(true);
  // A replaced editor with nothing pending is owed nothing, its edits not even looked at.
  const unsent = vi.spyOn(session, 'unsent');
  const idle = { next: () => null, receive() {}, hasPending: () => false, held: () => ({ version: 0, groups: [] }) };
  session.replaced = [{ field: name, adapter: idle }];
  expect(session.sent()).toBe(true);
  expect(unsent).not.toHaveBeenCalled();
  // One holding edits: a conflict kept does not give its field the saved value.
  const holding = {
    next: () => null,
    receive() {},
    hasPending: () => true,
    held: () => ({ version: 0, groups: [{ id: 'unsent', operations: [] }] }),
  };
  session.replaced = [{ field: name, adapter: holding }];
  expect(session.sent()).toBe(false);
  const adopt = vi.fn();
  session.adapters.set(name, { next: () => null, receive() {}, adopt });
  session.state.fields[name].version = null;
  session.conflicts = { [name]: 'Saved' };
  const request = vi.spyOn(session, 'request').mockResolvedValue(null);
  vi.spyOn(session, 'pull').mockResolvedValue(undefined);
  const flush = vi.spyOn(session, 'flush').mockResolvedValue(undefined);
  await session.reconcile(true);
  expect(adopt).not.toHaveBeenCalled();
  // One holding edits of another field keeps this one's saved value from nothing.
  session.replaced = [{ field: 'Record[other]', adapter: holding }];
  await session.reconcile(true);
  expect(adopt).toHaveBeenCalledWith('Saved');
  session.replaced = [];
  request.mockRestore();
  vi.mocked(session.pull).mockRestore();
  flush.mockRestore();
  // A renewal the server failed (busy, down), or whose answer the network cut: retried, not denied.
  const transport = fetcher.getMockImplementation();
  for (const answer of [
    () => new Response('', { status: 503 }),
    () => new Response('{"configura', { status: 200, headers: { 'Content-Type': 'application/json' } }),
  ]) {
    fetcher.mockImplementation(async (url, options) => {
      if (url === form.action) return answer();
      if (url === '/replay') return new Response('', { status: 403 });
      return transport(url, options);
    });
    await expect(session.pull()).rejects.not.toThrow('permission');
    expect(session.stopped).toBeFalsy();
  }
  fetcher.mockImplementation(transport);
  // A reload keeps the controls it found inert so: nothing typed there would be kept.
  const control = /** @type {HTMLElement} */ (form.querySelector('textarea'));
  control.setAttribute('inert', '');
  session.startupControls = [control];
  session.rejoin();
  expect(control.hasAttribute('inert')).toBe(true);
});

test('a Save waits for a replaced editor holding edits to go, and sends them; one that never goes is waited for as long as an editor', async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    return { model, buffer: new OperationBuffer(model), paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  // A host builds the next editor before destroying the one it replaces, which holds 'Old'; a Save meanwhile.
  const a = editor();
  const leaveA = session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('Old', a.paragraph(), 0));
  const b = editor();
  session.register(name, b.buffer);
  expect(session.owing()).toBe(true);
  const saving = session.flush();
  await new Promise((resolve) => setTimeout(resolve, 20));
  expect(state.events.filter((event) => event.field === name)).toHaveLength(0);
  leaveA();
  await saving;
  expect(state.events.filter((event) => event.field === name)).toHaveLength(1);
  expect(session.owing()).toBe(false);
  // One never destroyed: the Save goes on once an editor's time ran out, without its edits.
  session.configuration.config = { ...session.configuration.config, nextEditorGrace: 50 };
  b.model.change((writer) => writer.insertText('!', b.paragraph(), 0));
  const c = editor();
  session.register(name, c.buffer);
  const started = Date.now();
  await session.flush();
  expect(Date.now() - started).toBeGreaterThanOrEqual(40);
  expect(session.owing()).toBe(true);
  for (const each of [a, b, c]) each.model.destroy();
});

test("held edits taken over as the next editor replays are rebased only over what it applied; two held for a field both taken; a Save's wait ends as nothing is owed", async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    const paragraph = () => /** @type {any} */ (root.getChild(0));
    const text = () => Array.from(paragraph().getChildren(), (/** @type {any} */ node) => node.data).join('');
    return { model, buffer: new OperationBuffer(model), paragraph, text };
  };
  const events = () => state.events.filter((event) => event.field === name);
  // 'ab' in the draft; the editor goes holding 'X' typed after it.
  const a = editor();
  const leaveA = session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('ab', a.paragraph(), 0));
  await session.flush();
  a.model.change((writer) => writer.insertText('X', a.paragraph(), 2));
  leaveA();
  // A peer's 'PP' reaches the draft at that version, before the field's next editor comes.
  const peer = editor();
  for (const event of events()) peer.buffer.receive(event);
  peer.model.change((writer) => writer.insertText('PP', peer.paragraph(), 0));
  const batch = /** @type {any} */ (peer.buffer.next());
  state.fields[name].version = batch.version + batch.operations.length;
  state.events.push({ kind: 'operations', ...batch, field: name, revision: ++state.revision });
  await session.pull();
  const next = editor();
  session.register(name, next.buffer);
  expect(form.dataset.collaborateState).not.toBe('incompatible');
  expect(next.text()).toBe('PPabX');
  // Two held for the field (one replaced going after the one that replaced it): both taken over.
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  const b = editor();
  const leaveB = session.register(name, b.buffer);
  b.model.change((writer) => writer.insertText('1', b.paragraph(), 0));
  const c = editor();
  const leaveC = session.register(name, c.buffer);
  c.model.change((writer) => writer.insertText('2', c.paragraph(), 0));
  leaveC();
  leaveB();
  expect(session.retiring).toHaveLength(2);
  const d = editor();
  session.register(name, d.buffer);
  expect(session.retiring).toEqual([]);
  expect(d.buffer.held().groups).toHaveLength(2);
  // A Save waiting on a replaced editor holding edits ends as soon as nothing is owed: its field removed.
  session.configuration.config = { ...session.configuration.config, nextEditorGrace: 5_000 };
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  session.adapters.delete(name);
  const e = editor();
  session.register(name, e.buffer);
  e.model.change((writer) => writer.insertText('E', e.paragraph(), 0));
  const f = editor();
  session.register(name, f.buffer);
  expect(session.owing()).toBe(true);
  const started = Date.now();
  const saving = session.flush();
  await new Promise((resolve) => setTimeout(resolve, 20));
  session.detachField(name);
  await saving;
  expect(Date.now() - started).toBeLessThan(2_000);
  for (const each of [a, peer, next, b, c, d, e, f]) each.model.destroy();
});

test("a Save waiting for a replaced editor's edits ends as the draft acknowledges them", async () => {
  const name = 'Record[body]';
  const { session, form, state } = fixture('<textarea id="body" name="Record[body]"></textarea>', {
    [name]: { type: 'rich', initial: '' },
  });
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  vi.spyOn(session, 'schedule').mockImplementation(() => {});
  session.configuration.config = { ...session.configuration.config, nextEditorGrace: 5_000 };
  const editor = () => {
    const model = new Model();
    model.schema.register('paragraph', { inheritAllFrom: '$block' });
    const root = model.document.createRoot();
    model.change((writer) => writer.appendElement('paragraph', root));
    return { model, buffer: new OperationBuffer(model), paragraph: () => /** @type {any} */ (root.getChild(0)) };
  };
  // A sent its edit (the append under way) when B replaced it: owed until the draft acknowledges it.
  const a = editor();
  session.register(name, a.buffer);
  a.model.change((writer) => writer.insertText('Sent', a.paragraph(), 0));
  const sent = /** @type {any} */ (a.buffer.next());
  const b = editor();
  session.register(name, b.buffer);
  expect(session.owing()).toBe(true);
  const started = Date.now();
  const saving = session.flush();
  await new Promise((resolve) => setTimeout(resolve, 100));
  state.fields[name].initialVersion = sent.version;
  state.fields[name].version = sent.version + sent.operations.length;
  state.events.push({ kind: 'operations', ...sent, field: name, revision: ++state.revision });
  await saving;
  expect(Date.now() - started).toBeLessThan(2_000);
  expect(session.owing()).toBe(false);
  for (const each of [a, b]) each.model.destroy();
});

test('applies typed Markdown source once typing settles, on blur, before a flush, and never after removal', async () => {
  const name = 'Record[body]';
  const { session, form } = fixture(
    '<textarea id="body" name="Record[body]"></textarea><input type="radio" name="body_view" value="markdown" checked><input type="radio" name="body_view" value="wysiwyg">',
    {
      [name]: { type: 'rich', initial: 'Body' },
      [`${name}[__mode__]`]: { type: 'value', initial: 'markdown' },
    }
  );
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  const field = form.querySelector('textarea');
  const model = new Model();
  model.document.createRoot();
  const apply = vi.fn();
  vi.useFakeTimers();
  const remove = session.registerMode(name, { sourceElement: field, model }, apply);
  const type = (value) => {
    field.value = value;
    field.dispatchEvent(new Event('input'));
  };
  field.focus();
  type('a');
  type('ab');
  type('abc');
  expect(apply).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(100);
  expect(apply.mock.calls).toEqual([['abc']]);
  type('abcd');
  await session.flush();
  expect(apply.mock.calls.at(-1)).toEqual(['abcd']);
  field.blur();
  expect(apply).toHaveBeenCalledTimes(2);
  field.focus();
  // A remote batch for the field settles its typing first; nothing pending, nothing applied.
  type('abcdX');
  session.settleSource(name);
  expect(apply.mock.calls.at(-1)).toEqual(['abcdX']);
  session.settleSource(name);
  session.settleSource('Record[other]');
  expect(apply).toHaveBeenCalledTimes(3);
  type('abcde');
  remove();
  await vi.advanceTimersByTimeAsync(100);
  expect(apply).toHaveBeenCalledTimes(3);
  expect(session.sources.size).toBe(0);
  model.destroy();
});

test('pauses replay during IME composition and resumes synchronization after composition ends', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const before = fetcher.mock.calls.length;
  form.dispatchEvent(new CompositionEvent('compositionstart'));
  await session.pull();
  expect(fetcher.mock.calls).toHaveLength(before);
  const submit = new SubmitEvent('submit', { cancelable: true });
  form.dispatchEvent(submit);
  expect(submit.defaultPrevented).toBe(true);
  form.dispatchEvent(new CompositionEvent('compositionend'));
  await session.flush();
  expect(fetcher.mock.calls.length).toBeGreaterThan(before);
});

test('fails closed on quota errors and retains the local input for recovery', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const input = form.querySelector('input');
  input.value = 'unsent';
  input.dispatchEvent(new Event('input'));
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation((url, options) =>
    url === '/append' ? Promise.resolve(new Response('{}', { status: 413 })) : transport(url, options)
  );
  await expect(session.flush()).rejects.toMatchObject({ status: 413 });
  expect(session.form.dataset.collaborateState).toBe('full');
  expect(input.value).toBe('unsent');
});

// The halts a page reported, in order: what the server logs of them.
const reports = (fetcher) =>
  fetcher.mock.calls.filter(([url]) => url === '/report').map(([, options]) => JSON.parse(options.body));

test('reports an incompatible halt once per cause and field, with a short grant once acknowledged, and ignores a failed report', async () => {
  const error = vi.spyOn(console, 'error').mockImplementation(() => {});
  const { session, fetcher, configuration } = fixture();
  await session.ready;
  fetcher.mockImplementation(async (url) => {
    if (url === '/report') throw new TypeError('offline');
    return Response.json({});
  });
  session.report('version', 'Record[name]', 'editor 2, draft 1');
  session.report('version', 'Record[name]', 'again');
  session.acknowledged = null;
  session.report('replay', 'Record[name]', 7, 400);
  session.report('prototype');
  await vi.waitFor(() => expect(reports(fetcher)).toHaveLength(3));
  const [version, replay, prototype] = reports(fetcher);
  expect(version).toEqual({
    grant: 'signature',
    cause: 'version',
    field: 'Record[name]',
    status: null,
    detail: 'editor 2, draft 1',
  });
  expect(replay).toEqual({
    grant: configuration.grant,
    cause: 'replay',
    field: 'Record[name]',
    status: 400,
    detail: '7',
  });
  expect(prototype).toMatchObject({ cause: 'prototype', field: null, detail: null, status: null });
  const [url, options] = fetcher.mock.calls.find(([called]) => called === '/report');
  expect(url).toBe('/report');
  expect(options).toMatchObject({
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'csrf' },
  });
  expect(error).toHaveBeenCalledTimes(3);
  expect(error).toHaveBeenCalledWith('Collaborate: this page stopped following the shared draft (version).', {
    field: 'Record[name]',
    status: null,
    detail: 'editor 2, draft 1',
  });
  error.mockRestore();
});

test('rejects changed identities, schemas and corrupt operation history while retaining input', async () => {
  const first = fixture();
  await first.session.ready;
  const denied = { ...first.configuration, grant: `${btoa(JSON.stringify({ userId: 99, fields: {} }))}.signature` };
  await first.session.refreshConfiguration(denied);
  expect(first.form.dataset.collaborateState).toBe('denied');
  const second = fixture('<input name="Record[name]">', {
    'Record[name]': { type: 'text', initial: '', schema: 'v1' },
  });
  await second.session.ready;
  second.state.fields['Record[name]'].schema = 'v2';
  await expect(second.session.pull()).rejects.toThrow('schema');
  expect(second.form.dataset.collaborateState).toBe('incompatible');
  await vi.waitFor(() =>
    expect(reports(second.fetcher)).toEqual([
      expect.objectContaining({ cause: 'schema', field: 'Record[name]', detail: 'v1 → v2' }),
    ])
  );
  const third = fixture();
  await third.session.ready;
  third.session.remember({ field: 'Record[name]', kind: 'operations', version: 100 });
  expect(() =>
    third.session.register('Record[name]', {
      receive: () => {
        throw new Error('corrupt');
      },
    })
  ).toThrow('corrupt');
  expect(third.form.dataset.collaborateState).toBe('incompatible');
  await vi.waitFor(() =>
    expect(reports(third.fetcher)).toEqual([
      expect.objectContaining({ cause: 'replay', field: 'Record[name]', detail: 'corrupt' }),
    ])
  );
  const fourth = fixture();
  await fourth.session.ready;
  fourth.state.events.push({ field: 'Record[name]', version: 100, revision: 1, operations: [] });
  await expect(fourth.session.pull()).rejects.toThrow('gap');
  expect(fourth.form.dataset.collaborateState).toBe('incompatible');
  await vi.waitFor(() =>
    expect(reports(fourth.fetcher)).toEqual([expect.objectContaining({ cause: 'replay', field: 'Record[name]' })])
  );
});

test('a replay a non-error value breaks is reported with that value', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  session.remember({ field: 'Record[name]', kind: 'operations', version: 100 });
  expect(() =>
    session.register('Record[name]', {
      receive: () => {
        throw 'corrupt';
      },
    })
  ).toThrow();
  expect(form.dataset.collaborateState).toBe('incompatible');
  await vi.waitFor(() =>
    expect(reports(fetcher)).toEqual([expect.objectContaining({ cause: 'replay', detail: 'corrupt' })])
  );
});

test('an edit the network lost waits offline and is reported nowhere', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const answer = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (url === '/append') throw new TypeError('offline');
    return answer(url, options);
  });
  const control = form.elements.namedItem('Record[name]');
  control.value = 'Lost';
  control.dispatchEvent(new Event('input'));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  expect(fetcher.mock.calls.map(([url]) => url)).toContain('/append');
  expect(reports(fetcher)).toEqual([]);
  session.stop?.();
});

test('reports an editor initialized from another version than the draft stored', async () => {
  const { session, fetcher } = fixture();
  await session.ready;
  session.state.fields['Record[name]'].initialVersion = 1;
  expect(() => session.register('Record[name]', { version: 3, receive() {} })).toThrow('initialization');
  await vi.waitFor(() =>
    expect(reports(fetcher)).toEqual([
      expect.objectContaining({ cause: 'version', field: 'Record[name]', detail: 'editor 3, draft 1' }),
    ])
  );
});

test('updates only CSRF metadata on baseline refresh and stops when the saved record becomes inaccessible', async () => {
  const { session, form, fetcher, configuration } = fixture(
    '<input name="Record[name]" value="local"><input type="hidden" name="Record[_token]" value="old">'
  );
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation((url, options) => {
    if (options.method) return transport(url, options);
    return Promise.resolve(
      Response.json({ configuration, tokens: { 'Record[_token]': 'fresh', 'Missing[_token]': 'ignored' } })
    );
  });
  await session.refreshRecord();
  expect(form.elements.namedItem('Record[_token]').value).toBe('fresh');
  expect(form.elements.namedItem('Record[name]').value).toBe('');
  for (const status of [429, 500, 502, 503]) {
    fetcher.mockResolvedValue(new Response('Unavailable', { status }));
    await expect(session.refreshRecord()).rejects.toMatchObject({ status });
    expect(session.stopped).toBeFalsy();
  }
  // A form no longer shared, and a page that is not JSON (a proxy that dropped the header).
  for (const body of [Response.json({ configuration: null, tokens: {} }), new Response('<form></form>')]) {
    const other = fixture();
    await other.session.ready;
    const otherTransport = other.fetcher.getMockImplementation();
    other.fetcher.mockImplementation((url, options) =>
      options.method ? otherTransport(url, options) : Promise.resolve(body)
    );
    await expect(other.session.refreshRecord()).rejects.toThrow('unavailable');
    expect(other.form.dataset.collaborateState).toBe('denied');
  }
});

test('a new-record page renews its grant from the action its form posts to', async () => {
  const { session, form, fetcher, configuration } = fixture(undefined, undefined, {
    configuration: { isNew: true },
  });
  form.setAttribute('action', '/record/new?draft=0123456789abcdef0123456789abcdef');
  await session.ready;
  const grant = `${btoa(JSON.stringify({ fields: { 'Record[name]': { type: 'text', initial: '' } } }))}.renewed`;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation((url, options) =>
    options.method
      ? transport(url, options)
      : Promise.resolve(Response.json({ configuration: { ...configuration, grant }, tokens: {} }))
  );
  await session.refreshRecord();
  const [url, options] = fetcher.mock.calls.find(([, options]) => !options.method);
  expect(url).toBe(form.action);
  expect(options.headers).toEqual({ Accept: 'application/json', 'X-Collaborate-Refresh': '1' });
  expect(session.configuration.grant).toBe(grant);
  expect(session.stopped).toBeFalsy();
});

test('stops when the saved record is gone or the session redirects to sign-in', async () => {
  for (const response of [
    new Response('Missing', { status: 404 }),
    Object.defineProperty(new Response('<form></form>'), 'redirected', { value: true }),
  ]) {
    const { session, form, fetcher } = fixture();
    await session.ready;
    fetcher.mockResolvedValue(response);
    await expect(session.refreshRecord()).rejects.toThrow('unavailable');
    expect(form.dataset.collaborateState).toBe('denied');
  }
  const { session, form, fetcher } = fixture();
  await session.ready;
  fetcher.mockResolvedValue(new Response('Forbidden', { status: 403 }));
  await expect(session.refreshRecord()).rejects.toThrow('unavailable');
  expect(form.dataset.collaborateState).toBe('denied');
});

test('reports retryable network errors without discarding the local draft', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture();
  await session.ready;
  fetcher.mockResolvedValue(new Response('{}', { status: 502 }));
  session.schedule();
  await vi.advanceTimersByTimeAsync(150);
  expect(form.dataset.collaborateState).toBe('offline');
  expect(session.stopped).not.toBe(true);
  expect(session.timer).not.toBeNull();
});

test('stops sending a batch the server refused', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture();
  await session.ready;
  fetcher.mockResolvedValue(new Response('Invalid plain-text position.', { status: 400 }));
  session.schedule();
  await vi.advanceTimersByTimeAsync(150);
  expect(form.dataset.collaborateState).toBe('incompatible');
  expect(session.stopped).toBe(true);
  expect(reports(fetcher)).toEqual([expect.objectContaining({ cause: 'refused', field: null, status: 400 })]);
  const calls = fetcher.mock.calls.length;
  await vi.advanceTimersByTimeAsync(5000);
  expect(fetcher.mock.calls.length).toBe(calls);
});

test('reports a refused batch with its status and the field it was for', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) =>
    url === '/append' ? new Response('{}', { status: 400 }) : transport(url, options)
  );
  const input = form.querySelector('input');
  input.value = 'refused';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('incompatible'));
  await vi.waitFor(() =>
    expect(reports(fetcher)).toEqual([
      expect.objectContaining({
        cause: 'refused',
        field: 'Record[name]',
        status: 400,
        detail: 'Collaborate append: 400',
      }),
    ])
  );
});

test('waits a second behind a busy draft without reporting a lost connection', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher } = fixture();
  await session.ready;
  const state = form.dataset.collaborateState;
  fetcher.mockResolvedValue(new Response('{}', { status: 503 }));
  session.schedule();
  await vi.advanceTimersByTimeAsync(150);
  expect(form.dataset.collaborateState).toBe(state);
  const calls = fetcher.mock.calls.length;
  await vi.advanceTimersByTimeAsync(900);
  expect(fetcher.mock.calls.length).toBe(calls);
  await vi.advanceTimersByTimeAsync(100);
  expect(fetcher.mock.calls.length).toBeGreaterThan(calls);
  expect(session.stopped).not.toBe(true);
});

test('reconciles only after an explicit choice and exposes connection failures', async () => {
  const { session, fetcher } = fixture();
  await session.ready;
  confirmAction.mockResolvedValue(false);
  await session.reconcile(false);
  expect(fetcher.mock.calls.some(([url]) => url === '/reconcile')).toBe(false);
  await session.discardDraft();
  expect(fetcher.mock.calls.some(([url]) => url === '/discard')).toBe(false);
  await session.reconcile(true);
  expect(fetcher.mock.calls.some(([url]) => url === '/reconcile')).toBe(true);
  fetcher.mockRejectedValue(new Error('offline'));
  await session.reconcile(true);
  expect(session.form.dataset.collaborateState).toBe('offline');
  confirmAction.mockResolvedValue(true);
  await session.discardDraft();
  expect(session.form.dataset.collaborateState).toBe('offline');
});

test('keeps the shared draft only where it has edits and adopts the saved record elsewhere', async () => {
  const { session, form, state, fetcher } = fixture(
    '<input name="Record[name]"><input name="Record[note]"><select name="Record[type]"><option>a</option><option>b</option></select>',
    {
      'Record[name]': { type: 'text', initial: 'Original' },
      'Record[note]': { type: 'text', initial: 'Note' },
      'Record[type]': { type: 'value', initial: 'a' },
    }
  );
  await session.ready;
  const note = form.elements.namedItem('Record[note]');
  note.value = 'Draft note';
  note.dispatchEvent(new Event('input'));
  await session.flush();
  state.fields['Record[note]'].savedVersion = -2;
  const conflicts = { 'Record[name]': 'External', 'Record[note]': 'External note', 'Record[type]': 'b' };
  for (const [name, field] of Object.entries(state.fields)) session.state.fields[name] = { ...field };
  session.renderConflicts(conflicts);
  await session.reconcile(true);
  expect(form.elements.namedItem('Record[name]').value).toBe('External');
  expect(form.elements.namedItem('Record[type]').value).toBe('b');
  expect(note.value).toBe('Draft note');
  const appended = fetcher.mock.calls
    .filter(([url]) => url === '/append')
    .map(([, options]) => JSON.parse(options.body).field);
  expect(appended).toEqual(expect.arrayContaining(['Record[name]', 'Record[type]']));
  const order = fetcher.mock.calls.map(([url]) => url);
  expect(order.lastIndexOf('/append')).toBeLessThan(order.indexOf('/reconcile'));
});

test('keeps an unsent local edit instead of adopting the saved record', async () => {
  const { session, form } = fixture();
  await session.ready;
  const name = form.elements.namedItem('Record[name]');
  name.value = 'Unsent';
  name.dispatchEvent(new Event('input'));
  session.renderConflicts({ 'Record[name]': 'External' });
  await session.reconcile(true);
  expect(name.value).toBe('Unsent');
});

test('blocks early submits, exposes invitations and opens a record already created by another session', async () => {
  const { session, form } = fixture(undefined, undefined, {
    state: { recordId: '42' },
    configuration: { isNew: true },
  });
  const event = new SubmitEvent('submit', { cancelable: true });
  form.dispatchEvent(event);
  expect(event.defaultPrevented).toBe(true);
  await session.ready;
  expect(form.dataset.collaborateState).toBe('created');
  expect(form.querySelector('[data-collaborate-open-record]').getAttribute('href')).toBe('/record/42/edit');
  expect(form.querySelector('[data-collaborate-discard]').disabled).toBe(true);
  const invited = fixture(undefined, undefined, { configuration: { invitation: '/new?draft=shared' } });
  await invited.session.ready;
  expect(invited.form.querySelector('[data-collaborate-invite]')).not.toBeNull();
  const unavailable = fixture(undefined, undefined, { failJoin: true, joinStatus: 409 });
  await expect(unavailable.session.ready).rejects.toMatchObject({ status: 409 });
  expect(unavailable.form.dataset.collaborateState).toBe('unavailable');
});

test('allows an explicit discard while synchronization is stopped and handles a saved-record reset choice', async () => {
  const { session, fetcher, form } = fixture();
  await session.ready;
  session.setStatus('draft');
  session.stopped = true;
  form.querySelector('[data-collaborate-discard]').click();
  await vi.waitFor(() => expect(fetcher.mock.calls.some(([url]) => url === '/discard')).toBe(true));
  // The page reloads then: another one uses the saved record.
  const other = fixture();
  await other.session.ready;
  other.session.stopped = true;
  await other.session.reconcile(false);
  expect(other.fetcher.mock.calls.some(([url]) => url === '/reconcile')).toBe(true);
});

test('waits for rich editor initialization, no more once the draft stops for an editor that never comes', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const pending = session.flush();
  const rejected = expect(pending).rejects.toThrow('Collaborate draft stopped.');
  await vi.advanceTimersByTimeAsync(10_000);
  await rejected;
  expect(session.stopped).toBe(true);
});

test('a save waits for an editor asked for again as long as its time runs, never less', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  // The editor is asked for again and again (Drupal swapping it): the save waits past any time of its own.
  let settled = false;
  const pending = session.flush().then(() => {
    settled = true;
  });
  let editor;
  for (let elapsed = 0; elapsed < 36_000; elapsed += 9_000) {
    editor = (await session.editorConfiguration({ name: 'Record[body]' })).collaborate;
    await vi.advanceTimersByTimeAsync(9_000);
  }
  expect(settled).toBe(false);
  expect(session.stopped).toBeFalsy();
  editor.ready();
  await vi.advanceTimersByTimeAsync(0);
  await pending;
  expect(settled).toBe(true);
});

test('an editor asked for again and again, none ever ready, stops the draft: a save under way posts as usual', async () => {
  vi.useFakeTimers();
  const { session, form } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
    'Record[items][__name__][title]': { type: 'text', initial: '' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await session.ready;
  const rejected = expect(session.flush()).rejects.toThrow('Collaborate draft stopped.');
  // Nine editors asked for in turn, none ready: the tenth stops the draft, and starts from the textarea.
  for (let asked = 0; asked < 9; asked += 1) {
    await session.editorConfiguration({ name: 'Record[body]' });
    expect(session.stopped).toBeFalsy();
  }
  expect(await session.editorConfiguration({ name: 'Record[body]' })).toEqual({});
  expect(session.stopped).toBe(true);
  expect(form.dataset.collaborateState).toBe('unavailable');
  await rejected;
  // A stopped draft takes on no editor more, nor any field.
  const fields = { ...session.state.fields };
  expect(await session.editorConfiguration({ name: 'Record[items][n9][title]' })).toEqual({});
  expect(session.state.fields).toEqual(fields);
  const waiters = new Map(session.editorWaiters);
  expect(await session.editorConfiguration({ name: 'Record[body]' })).toEqual({});
  expect(session.editorWaiters).toEqual(waiters);
  expect(session.editorWaiters.get('Record[body]')).toBe(waiters.get('Record[body]'));
  // One ready meanwhile starts the count again.
  const other = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await vi.advanceTimersByTimeAsync(0);
  await other.session.ready;
  for (let asked = 0; asked < 9; asked += 1)
    (await other.session.editorConfiguration({ name: 'Record[body]' })).collaborate.ready();
  for (let asked = 0; asked < 9; asked += 1) await other.session.editorConfiguration({ name: 'Record[body]' });
  expect(other.session.stopped).toBeFalsy();
  vi.useRealTimers();
});
test('falls back to ordinary editing when a rich editor fails to mount', async () => {
  const { session, form } = fixture('<input name="Record[name]"><textarea name="Record[body]"></textarea>', {
    'Record[name]': { type: 'text', initial: '' },
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  expect(form.querySelector('[inert]')).not.toBeNull();
  session.editorFailed();
  expect(form.querySelector('[inert]')).toBeNull();
  expect(session.stopped).toBe(true);
  expect(form.dataset.collaborateState).toBe('unavailable');
  const submit = new SubmitEvent('submit', { cancelable: true });
  form.dispatchEvent(submit);
  expect(submit.defaultPrevented).toBe(false);
  session.editorFailed();
  expect(form.dataset.collaborateState).toBe('unavailable');
});

test('blocks Save while external changes need review, supports Enter submission and retains failed submissions', async () => {
  const { session, form, state, fetcher } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation((url, options) => {
    if (!options.method) return Promise.resolve(Response.json({ configuration: session.configuration, tokens: {} }));
    return transport(url, options);
  });
  vi.spyOn(form, 'requestSubmit').mockImplementation(() => {});
  state.conflicts = { 'Record[name]': 'external' };
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('conflict'));
  expect(form.requestSubmit).not.toHaveBeenCalled();
  state.conflicts = {};
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.requestSubmit).toHaveBeenCalledWith());
  // The network failing, as fetch does: offline.
  fetcher.mockRejectedValue(new TypeError('Failed to fetch'));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  // A bug of the page's own as the Save reaches the server (not as the network fails): said to save again.
  vi.spyOn(console, 'error').mockImplementation(() => {});
  const refresh = vi.spyOn(session, 'refreshRecord').mockRejectedValueOnce(new RangeError('A bug.'));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('notSaved'));
  // A TypeError too, the most common bug: the network's failures are told apart where they happen.
  session.setStatus('draft');
  refresh.mockRejectedValueOnce(new TypeError("Cannot read properties of undefined (reading 'value')"));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('notSaved'));
  // The server failing (an answer's status): offline, not the page's.
  session.setStatus('draft');
  refresh.mockRejectedValueOnce(new HttpError('Collaborate record refresh: 503', 503));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
  expect(session.pageBug(new RangeError('A bug.'))).toBe(true);
  // A draft frozen, or stopped, as it fails: nothing of the page's.
  session.suspended = true;
  expect(session.pageBug(new RangeError('A bug.'))).toBe(false);
  session.suspended = false;
  session.stopped = true;
  expect(session.pageBug(new RangeError('A bug.'))).toBe(false);
});

test('pauses an in-flight replay when IME composition begins and refuses schema changes in refreshed grants', async () => {
  const { session, form, fetcher, state, configuration } = fixture(undefined, {
    'Record[name]': { type: 'text', initial: '', schema: 'v1' },
  });
  await session.ready;
  let resolve;
  fetcher.mockImplementation(
    () =>
      new Promise((done) => {
        resolve = done;
      })
  );
  const pending = session.flush();
  await vi.waitFor(() => expect(resolve).toBeTypeOf('function'));
  form.dispatchEvent(new CompositionEvent('compositionstart'));
  resolve(Response.json(state));
  await pending;
  expect(session.composing).toBe(true);
  const grant = `${btoa(JSON.stringify({ fields: { 'Record[name]': { type: 'text', initial: '', schema: 'v2' } } }))}.signature`;
  await session.refreshConfiguration({ ...configuration, grant });
  expect(form.dataset.collaborateState).toBe('incompatible');
  expect(reports(fetcher)).toEqual([
    expect.objectContaining({ cause: 'schema', field: 'Record[name]', detail: 'v1 → v2' }),
  ]);
  await session.refreshRecord();
});

test('automatically initializes configured forms once after DOMContentLoaded', async () => {
  const { session: original, form, fetcher, configuration } = fixture();
  await original.ready;
  original.stop();
  form.dataset.collaborate = JSON.stringify(configuration);
  vi.stubGlobal('fetch', fetcher);
  vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
  vi.resetModules();
  const module = await import('../../../assets/draft/session.js');
  document.dispatchEvent(new Event('DOMContentLoaded'));
  const input = form.querySelector('input');
  const session = module.sessionFor(input);
  live.push(session);
  await session.ready;
  expect(module.sessionFor(input)).toBe(session);
  // The stopped session's bar, and one for the session initialized after DOMContentLoaded.
  expect(form.querySelectorAll('[data-collaborate-status]')).toHaveLength(2);
});

test('stops once a host takes its form away', async () => {
  vi.useFakeTimers();
  const { session, form, fetcher, hub } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  expect(hub.members).toHaveLength(1);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  // A form moved elsewhere in the page keeps its session.
  document.body.prepend(form);
  await vi.advanceTimersByTimeAsync(5000);
  expect(session.stopped).toBeFalsy();
  form.remove();
  await vi.advanceTimersByTimeAsync(5000);
  expect(session.stopped).toBe(true);
  expect(hub.members).toEqual([]);
  const before = replays();
  await vi.advanceTimersByTimeAsync(60_000);
  expect(replays()).toBe(before);
});

test('a form a host puts back after its session stopped with it starts another one', async () => {
  vi.useFakeTimers();
  const { session: original, form, fetcher, configuration } = fixture();
  await original.ready;
  original.stop();
  form.dataset.collaborate = JSON.stringify(configuration);
  vi.stubGlobal('fetch', fetcher);
  vi.resetModules();
  const module = await import('../../../assets/draft/session.js');
  const input = form.querySelector('input');
  const first = module.sessionFor(input);
  live.push(first);
  await first.ready;
  form.remove();
  await vi.advanceTimersByTimeAsync(5000);
  expect(first.stopped).toBe(true);
  document.body.append(form);
  const second = module.sessionFor(input);
  live.push(second);
  expect(second).not.toBe(first);
  expect(module.sessionFor(input)).toBe(second);
  await second.ready;
});

test('handles subscription failures and maps translated collection controls through their physical names', async () => {
  vi.useFakeTimers();
  const failed = fixture();
  await failed.session.ready;
  await vi.advanceTimersByTimeAsync(0);
  failed.hub.member.closed();
  expect(failed.form.dataset.collaborateState).toBe('offline');
  // A draft the hub refused is followed by replays alone, and joins again only with a new connection.
  failed.hub.member.denied();
  expect(failed.session.live).toBe(false);
  await failed.session.connect();
  expect(failed.hub.join).toHaveBeenCalledTimes(2);
  failed.session.stopped = true;
  failed.hub.member.closed();
  await failed.session.connect();
  expect(failed.hub.join).toHaveBeenCalledTimes(2);
  const lone = new DraftSession(failed.form, failed.configuration, failed.fetcher, null);
  live.push(lone);
  await lone.connect();
  expect(lone.leaveHub).toBeUndefined();
  const translated = fixture('', {
    'fr:Record[items][__name__][title]': { type: 'text', initial: '', formName: 'Record[items][__name__][title]' },
  });
  await translated.session.ready;
  expect(translated.session.fieldKey('Record[items][n1][title]')).toBe('fr:Record[items][n1][title]');
  await translated.session.connect();
  expect(translated.hub.join).toHaveBeenCalledOnce();
});

test('replays again when a draft notice arrives while a running flush waits on its replay', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher, state } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  const before = replays();
  const transport = fetcher.getMockImplementation();
  let release;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        release = resolve;
      })
  );
  const flushing = session.flush();
  await vi.advanceTimersByTimeAsync(0);
  expect(replays()).toBe(before + 1);
  // The held replay read the draft before the peer's commit that this notice announces.
  const stale = { ...state, events: [] };
  message(hub.member, { kind: 'append', revision: 1 });
  await vi.advanceTimersByTimeAsync(150);
  release(Response.json(stale));
  fetcher.mockImplementation(transport);
  await flushing;
  await vi.advanceTimersByTimeAsync(0);
  expect(replays()).toBe(before + 2);
});

test('an append notice this page has read already, its own echo included, replays nothing', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  session.revision = 3;
  const before = replays();
  message(hub.member, { kind: 'append', generation: session.generation, revision: 3 });
  message(hub.member, { kind: 'append', generation: session.generation, revision: 2 });
  await vi.advanceTimersByTimeAsync(1000);
  expect(replays()).toBe(before);
  expect(session.stale).toBeFalsy();
  // A newer append, one of another generation, or a notice naming no revision each replays.
  for (const notice of [
    { kind: 'append', generation: session.generation, revision: 4 },
    { kind: 'append', generation: session.generation + 1, revision: 1 },
    { kind: 'append', generation: session.generation, revision: '9' },
    { kind: 'discard' },
  ]) {
    const counted = replays();
    session.revision = 3;
    message(hub.member, notice);
    await vi.advanceTimersByTimeAsync(1000);
    expect(replays()).toBe(counted + 1);
  }
});

test('a replay under way reads again only when a notice announced a revision it did not reach', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher, state } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  const transport = fetcher.getMockImplementation();
  const held = () => {
    let release;
    fetcher.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          release = resolve;
        })
    );
    return (answer) => release(Response.json(answer));
  };

  // The page's own append, announced while its replay runs: that replay reaches it.
  let before = replays();
  let release = held();
  let pulling = session.pull();
  await vi.advanceTimersByTimeAsync(0);
  message(hub.member, { kind: 'append', generation: session.generation, revision: 1 });
  await vi.advanceTimersByTimeAsync(150);
  release({ ...state, revision: 1, events: [] });
  await pulling;
  await vi.advanceTimersByTimeAsync(1000);
  expect(session.revision).toBe(1);
  expect(replays()).toBe(before + 1);

  // A peer's later append, announced while the replay runs: the replay read before it, and reads again.
  before = replays();
  release = held();
  pulling = session.pull();
  await vi.advanceTimersByTimeAsync(0);
  message(hub.member, { kind: 'append', generation: session.generation, revision: 3 });
  release({ ...state, revision: 2, events: [] });
  fetcher.mockImplementation(transport);
  await pulling;
  expect(replays()).toBe(before + 2);
});

test('a notice heard during a replay that fails schedules another', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  let fail;
  fetcher.mockImplementationOnce(
    () =>
      new Promise((_resolve, reject) => {
        fail = reject;
      })
  );
  const before = replays();
  const pulling = session.pull();
  await vi.advanceTimersByTimeAsync(0);
  message(hub.member, { kind: 'append', generation: session.generation, revision: 5 });
  fail(new TypeError('Failed to fetch'));
  await expect(pulling).rejects.toThrow('Failed to fetch');
  await vi.advanceTimersByTimeAsync(1000);
  expect(replays()).toBeGreaterThan(before + 1);
  // A failed replay nobody announced anything during leaves the retry to its caller.
  fetcher.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));
  const counted = replays();
  await expect(session.pull()).rejects.toThrow('Failed to fetch');
  await vi.advanceTimersByTimeAsync(1000);
  expect(replays()).toBe(counted + 1);
});

test('pulls every 5 s while the hub is down and every 30 s while it is connected', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  hub.member.opened();
  await vi.advanceTimersByTimeAsync(1000);
  let before = replays();
  await vi.advanceTimersByTimeAsync(25_000);
  expect(replays()).toBe(before);
  await vi.advanceTimersByTimeAsync(10_000);
  expect(replays()).toBe(before + 1);
  // A session already on the stream does not join it twice.
  await session.connect();
  expect(hub.join).toHaveBeenCalledOnce();
  hub.member.closed();
  expect(session.live).toBe(false);
  await vi.advanceTimersByTimeAsync(200);
  before = replays();
  await vi.advanceTimersByTimeAsync(5000);
  expect(replays()).toBe(before + 1);
});

test('compares the form with the saved record once a second after record notices', async () => {
  vi.useFakeTimers();
  const { session, hub } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  const refresh = vi.spyOn(session, 'refreshRecord').mockResolvedValue();
  const record = () => message(hub.member, { channel: 'draft', kind: 'record' });
  record();
  record();
  expect(refresh).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledOnce();
  refresh.mockRejectedValueOnce(new Error('offline'));
  record();
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(2);
  expect(session.stopped).not.toBe(true);
  // A busy server read nothing: the notice is compared again a second later, not left to Save.
  refresh.mockRejectedValueOnce(new HttpError('busy', 503));
  refresh.mockRejectedValueOnce(new HttpError('busy', 429));
  record();
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(3);
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(4);
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(5);
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(5);
  record();
  session.halt();
  await vi.advanceTimersByTimeAsync(1000);
  expect(refresh).toHaveBeenCalledTimes(5);

  const created = fixture(undefined, undefined, { configuration: { isNew: true } });
  await created.session.ready;
  const unused = vi.spyOn(created.session, 'refreshRecord');
  created.session.recordChanged();
  await vi.advanceTimersByTimeAsync(1000);
  expect(unused).not.toHaveBeenCalled();
});

test('stops its timers and stream when it stops following the draft', async () => {
  vi.useFakeTimers();
  const { session, hub, fetcher } = fixture();
  await session.ready;
  await vi.advanceTimersByTimeAsync(0);
  fetcher.mockResolvedValue(new Response('{}', { status: 403 }));
  await expect(session.pull()).rejects.toThrow('permission');
  expect(hub.members).toEqual([]);
  const calls = fetcher.mock.calls.length;
  await vi.advanceTimersByTimeAsync(300_000);
  expect(fetcher.mock.calls.length).toBe(calls);
});

test('rescans collections after a replay that brings changed field states only', async () => {
  const { session, fetcher, state } = fixture();
  await session.ready;
  session.scanCollections = vi.fn();
  await session.pull();
  expect(session.scanCollections).toHaveBeenCalledOnce();
  fetcher.mockResolvedValueOnce(Response.json({ ...state, fields: {}, events: [] }));
  await session.pull();
  expect(session.scanCollections).toHaveBeenCalledOnce();
});

test.each([
  ['a lost connection', 'offline', new TypeError('Failed to fetch')],
  ['a busy server', 'offline', new HttpError('busy', 503)],
  ['a rate limit', 'offline', new HttpError('limited', 429)],
  ['a refusal', 'failed', new HttpError('refused', 400)],
])('reports %s as %s', async (_, state, error) => {
  const { session, form } = fixture();
  await session.ready;
  session.failed(error);
  expect(form.dataset.collaborateState).toBe(state);
});

test('reports a draft changed meanwhile and reads it again; the next answer settles the state', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  fetcher.mockClear();
  session.failed(new HttpError('changed', 409));
  expect(form.dataset.collaborateState).toBe('changed');
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('saved'));
  expect(fetcher.mock.calls.some(([url]) => url === '/replay')).toBe(true);
  // An unchanged answer settles it too.
  session.setStatus('changed');
  fetcher.mockResolvedValueOnce(Response.json({ unchanged: true, stamp: 'x' }));
  await session.pull();
  expect(form.dataset.collaborateState).toBe('saved');
});

test('ignores an aborted request and a failure once the session stopped', async () => {
  const { session, form } = fixture();
  await session.ready;
  session.failed(new DOMException('aborted', 'AbortError'));
  expect(form.dataset.collaborateState).toBe('saved');
  session.halt('denied');
  session.failed(new HttpError('refused', 400));
  expect(form.dataset.collaborateState).toBe('denied');
});

test('names the other editor whose Save the draft holds, never this account', async () => {
  const fields = { 'Record[name]': { type: 'text', initial: '' } };
  const grant = `${btoa(JSON.stringify({ fields, userId: 7 }))}.signature`;
  const { session, form } = fixture(undefined, fields, {
    configuration: { grant },
    state: { savedBy: { userId: 8, label: 'Alice' } },
  });
  await session.ready;
  expect(form.dataset.collaborateState).toBe('saved');
  expect(form.querySelector('[data-collaborate-status]').textContent).toBe('Saved by Alice');
  session.state.savedBy = { userId: 7, label: 'Me' };
  session.settled();
  expect(form.querySelector('[data-collaborate-status]').textContent).toBe('Saved');
  expect(session.peerName(null)).toBeNull();
  expect(session.peerName({ userId: 0, label: 'System' })).toBeNull();
});

test('names the editor who created the record from another page', async () => {
  const { session, form } = fixture(undefined, undefined, {
    state: { recordId: '42', savedBy: { userId: 8, label: 'Alice' } },
    configuration: { isNew: true },
  });
  await session.ready;
  expect(form.dataset.collaborateState).toBe('created');
  expect(form.querySelector('[data-collaborate-status]').textContent).toBe('Created by Alice');
});

test('names who discarded a field this page holds unsent edits in, and tells a clean page why it reloaded', async () => {
  const { session, form, state } = fixture();
  await session.ready;
  const control = form.elements.namedItem('Record[name]');
  control.value = 'Unsent';
  control.dispatchEvent(new Event('input'));
  state.events.push({ kind: 'discard', field: '', revision: ++state.revision, userId: 8, label: 'Alice' });
  await expect(session.replay()).rejects.toThrow('discarded');
  expect(form.dataset.collaborateState).toBe('discarded');
  expect(form.querySelector('[data-collaborate-status]').textContent).toBe('Discarded by Alice');

  const clean = fixture();
  await clean.session.ready;
  const rejoin = vi.spyOn(clean.session, 'rejoin').mockImplementation(() => {});
  clean.state.events.push({ kind: 'discard', field: '', revision: ++clean.state.revision, userId: 8, label: 'Alice' });
  await expect(clean.session.replay()).rejects.toThrow('rebased');
  expect(rejoin).toHaveBeenCalledWith({ key: 'noticeDiscarded', name: 'Alice' });
});

test('shows the notice its previous load left once, then forgets it', async () => {
  const first = fixture();
  await first.session.ready;
  // jsdom reports the reload it cannot perform; the stored notice is what the next load reads.
  first.session.rejoin({ key: 'noticeDiscarded', name: 'Alice' });
  first.session.stop();
  const second = fixture();
  await second.session.ready;
  expect(second.form.querySelector('[data-collaborate-notice]').textContent).toBe('Alice discarded, reloaded');
  expect(second.form.querySelector('[data-collaborate-announcer]').textContent).toBe('Alice discarded, reloaded');
  const third = fixture();
  await third.session.ready;
  expect(third.form.querySelector('[data-collaborate-notice]')).toBeNull();
  // A page that stayed instead of reloading, opened again a while later: its notice is no longer said.
  third.session.rejoin({ key: 'noticeDiscarded', name: 'Alice' });
  const now = Date.now();
  const later = vi.spyOn(Date, 'now').mockReturnValue(now + 61_000);
  const fourth = fixture();
  await fourth.session.ready;
  expect(fourth.form.querySelector('[data-collaborate-notice]')).toBeNull();
  // Within its time, said; one stored before notices were stamped, said too.
  later.mockReturnValue(now + 59_000);
  third.session.rejoin({ key: 'noticeDiscarded', name: 'Alice' });
  later.mockReturnValue(now + 59_000 + 59_000);
  const fifth = fixture();
  await fifth.session.ready;
  expect(fifth.form.querySelector('[data-collaborate-notice]')?.textContent).toBe('Alice discarded, reloaded');
  later.mockRestore();
  // A load that is not a reload (a link followed on a page that stayed) says nothing of it.
  sessionStorage.setItem(fifth.session.noticeKey(), JSON.stringify({ key: 'noticeDiscarded', name: 'Bob' }));
  const navigation = vi.spyOn(performance, 'getEntriesByType').mockReturnValue([{ type: 'navigate' }]);
  const linked = fixture();
  await linked.session.ready;
  expect(linked.form.querySelector('[data-collaborate-notice]')).toBeNull();
  navigation.mockReturnValue([{ type: 'reload' }]);
  sessionStorage.setItem(fifth.session.noticeKey(), JSON.stringify({ key: 'noticeDiscarded', name: 'Bob' }));
  const sixth = fixture();
  await sixth.session.ready;
  expect(sixth.form.querySelector('[data-collaborate-notice]')?.textContent).toBe('Bob discarded, reloaded');
  navigation.mockRestore();
});

test('reloads without a notice where storage is unavailable or holds something else', async () => {
  const { session } = fixture();
  await session.ready;
  window.onbeforeunload = () => 'Changes may not be saved.';
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError');
  });
  session.rejoin({ key: 'noticeDiscardedOwn' });
  expect(window.onbeforeunload).toBeNull();
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError');
  });
  session.showNotice();
  vi.mocked(Storage.prototype.getItem).mockReturnValue('{"key":"unknown"}');
  session.showNotice();
  expect(session.form.querySelector('[data-collaborate-notice]')).toBeNull();
});

test('a Save its conflicts block brings the conflict review into view and focus', async () => {
  const { session, form } = fixture(undefined, undefined, { state: { conflicts: { 'Record[name]': 'External' } } });
  await session.ready;
  vi.spyOn(session, 'refreshRecord').mockResolvedValue();
  vi.spyOn(session, 'flush').mockResolvedValue();
  const scroll = vi.fn();
  session.conflictPanel.scrollIntoView = scroll;
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(scroll).toHaveBeenCalledWith({ block: 'nearest' }));
  expect(document.activeElement).toBe(session.conflictPanel.querySelector('h2'));
  expect(form.dataset.collaborateState).toBe('conflict');
});

test('keeps Discard off while its request runs and in states it cannot succeed from', async () => {
  let release;
  const { session, form, fetcher } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (url === '/discard') await new Promise((resolve) => (release = resolve));
    return transport(url, options);
  });
  vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  const button = form.querySelector('[data-collaborate-discard]');
  // Nothing to discard on a saved draft.
  expect(button.disabled).toBe(true);
  session.setStatus('draft');
  expect(button.disabled).toBe(false);
  const discarding = session.discardDraft();
  await vi.waitFor(() => expect(release).toBeTypeOf('function'));
  expect(button.disabled).toBe(true);
  release();
  await discarding;
  // No longer busy: an unsaved state offers it again.
  session.setStatus('draft');
  expect(button.disabled).toBe(false);
  session.halt('denied');
  expect(button.disabled).toBe(true);
});

test('times its Join retries and replays as the form configures', async () => {
  vi.useFakeTimers();
  let attempts = 0;
  const { session, hub, fetcher } = fixture(undefined, undefined, {
    configuration: { config: { joinRetry: 2500, replayConnected: 8000, replayDisconnected: 2000 } },
    get failJoin() {
      return ++attempts <= 2;
    },
  });
  await vi.advanceTimersByTimeAsync(2000);
  await session.ready;
  expect(attempts).toBe(3);
  const replays = () => fetcher.mock.calls.filter(([url]) => url === '/replay').length;
  await vi.advanceTimersByTimeAsync(0);
  hub.member.opened();
  await vi.advanceTimersByTimeAsync(500);
  let before = replays();
  await vi.advanceTimersByTimeAsync(6000);
  expect(replays()).toBe(before);
  await vi.advanceTimersByTimeAsync(4000);
  expect(replays()).toBe(before + 1);
  hub.member.closed();
  await vi.advanceTimersByTimeAsync(200);
  before = replays();
  await vi.advanceTimersByTimeAsync(2000);
  expect(replays()).toBe(before + 1);
});

test('gives up Join once the configured retry time is over', async () => {
  vi.useFakeTimers();
  const { session, fetcher } = fixture(undefined, undefined, {
    failJoin: true,
    configuration: { config: { joinRetry: 1500 } },
  });
  const rejected = expect(session.ready).rejects.toMatchObject({ status: 503 });
  await vi.advanceTimersByTimeAsync(2000);
  await rejected;
  expect(fetcher.mock.calls.filter(([url]) => url === '/join')).toHaveLength(3);
});

test('shows the edits of the journal Join answers in the fields it registers', async () => {
  const { session, form } = fixture(
    '<select name="Record[type]"><option value="a">A</option><option value="b">B</option></select>',
    { 'Record[type]': { type: 'value', initial: 'a' } },
    {
      state: {
        revision: 1,
        events: [{ id: 'peer', kind: 'value', field: 'Record[type]', value: 'b', version: 0, revision: 1 }],
      },
    }
  );
  await session.ready;
  expect(form.elements.namedItem('Record[type]').value).toBe('b');
  expect(session.adapters.get('Record[type]').next()).toBeNull();
});

test('follows a choice rendered as radio buttons', async () => {
  const { session, form, fetcher } = fixture(
    '<input type="radio" name="Record[type]" value="a" checked><input type="radio" name="Record[type]" value="b">',
    { 'Record[type]': { type: 'value', initial: 'a' } }
  );
  await session.ready;
  const [a, b] = form.querySelectorAll('input[type="radio"]');
  b.checked = true;
  b.dispatchEvent(new Event('change'));
  await session.flush();
  const append = fetcher.mock.calls.find(([url]) => url === '/append');
  expect(JSON.parse(append[1].body)).toMatchObject({ field: 'Record[type]', kind: 'value', value: 'b' });
  session.adapters.get('Record[type]').receive({ id: 'peer', kind: 'value', value: 'a', version: 1 });
  expect(a.checked).toBe(true);
});

test('both requests a lost signature failed go again with the full grant', async () => {
  const { session, fetcher, configuration } = fixture();
  await session.ready;
  expect(session.acknowledged).toBe(configuration.grant);
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) =>
    JSON.parse(options.body).grant === 'signature' ? new Response('{}', { status: 412 }) : transport(url, options)
  );
  fetcher.mockClear();
  await Promise.all([session.request('replay', { since: 0 }), session.request('replay', { since: 0 })]);
  const grants = fetcher.mock.calls.map(([, options]) => JSON.parse(options.body).grant);
  expect(grants).toEqual(['signature', 'signature', configuration.grant, configuration.grant]);
  expect(session.acknowledged).toBe(configuration.grant);
});

test('reloads once a Discard the server answers without content is done', async () => {
  const { session, fetcher } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) =>
    url === '/discard' ? new Response(null, { status: 204 }) : transport(url, options)
  );
  const rejoin = vi.spyOn(session, 'rejoin').mockImplementation(() => {});
  await session.discardDraft();
  expect(rejoin).toHaveBeenCalledWith({ key: 'noticeDiscardedOwn' });
});

test('keeps the changed status when the replay a refusal asks for fails', async () => {
  const { session, form, fetcher } = fixture();
  await session.ready;
  fetcher.mockClear();
  fetcher.mockResolvedValue(new Response('{}', { status: 500 }));
  session.failed(new HttpError('changed', 409));
  await vi.waitFor(() => expect(fetcher.mock.calls.some(([url]) => url === '/replay')).toBe(true));
  await vi.waitFor(() => expect(session.pulling).toBeNull());
  expect(form.dataset.collaborateState).toBe('changed');
});

test('sends a peer-refused edit again on the revision the replay brings', async () => {
  const { session, form, state, fetcher } = fixture();
  await session.ready;
  // A peer's edit of the field lands first: the server refuses this page's batch for its stale version.
  const transport = fetcher.getMockImplementation();
  let refused = false;
  fetcher.mockImplementation(async (url, options) => {
    if (url === '/append' && !refused) {
      refused = true;
      return new Response('{}', { status: 409 });
    }
    return transport(url, options);
  });
  const control = form.elements.namedItem('Record[name]');
  control.value = 'Local';
  control.dispatchEvent(new Event('input'));
  await session.flush();
  expect(fetcher.mock.calls.filter(([url]) => url === '/append')).toHaveLength(2);
  expect(state.events).toHaveLength(1);
  expect(form.dataset.collaborateState).toBe('draft');
});

test('reads the draft again for a renewed grant when the replay running meanwhile fails', async () => {
  const { session, fetcher, configuration } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  let fail = true;
  fetcher.mockImplementation(async (url, options) => {
    if (url === '/replay' && fail) {
      fail = false;
      return new Response('{}', { status: 503 });
    }
    return transport(url, options);
  });
  fetcher.mockClear();
  const failing = session.pull();
  const refreshed = session.refreshConfiguration({ ...configuration });
  await expect(failing).rejects.toMatchObject({ status: 503 });
  await refreshed;
  expect(fetcher.mock.calls.filter(([url]) => url === '/replay')).toHaveLength(2);
  expect(session.form.dataset.collaborateState).toBe('saved');
});

test('refreshes the grant from a record answer that carries no tokens', async () => {
  const { session, form, fetcher, configuration } = fixture(
    '<input name="Record[name]"><input type="hidden" name="Record[_token]" value="kept">'
  );
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation((url, options) =>
    options.method
      ? transport(url, options)
      : Promise.resolve(Response.json({ configuration: { ...configuration, csrf: 'renewed' } }))
  );
  await session.refreshRecord(false);
  expect(session.configuration.csrf).toBe('renewed');
  expect(form.elements.namedItem('Record[_token]').value).toBe('kept');
});

test('leaves the conflicts of an unchanged answer to the next replay when the grant changed meanwhile', async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  session.setStatus('offline');
  const render = vi.spyOn(session, 'renderConflicts');
  fetcher.mockImplementationOnce(async () => {
    await session.refreshConfiguration({ ...configuration }, false);
    return Response.json({ unchanged: true });
  });
  await session.pull();
  expect(form.dataset.collaborateState).toBe('saved');
  expect(render).not.toHaveBeenCalled();
});

test('tells a page whose draft changed only in a choice, nothing unsent, to rejoin, and keeps an unsent choice', async () => {
  const html = '<select name="Record[type]"><option value="a">A</option><option value="b">B</option></select>';
  const fields = { 'Record[type]': { type: 'value', initial: 'a' } };
  const clean = fixture(html, fields);
  await clean.session.ready;
  const rejoin = vi.spyOn(clean.session, 'rejoin').mockImplementation(() => {});
  clean.state.generation = 2;
  await expect(clean.session.replay()).rejects.toThrow('rebased');
  expect(rejoin).toHaveBeenCalledWith(null);

  const edited = fixture(html, fields);
  await edited.session.ready;
  const select = edited.form.elements.namedItem('Record[type]');
  select.value = 'b';
  select.dispatchEvent(new Event('change'));
  edited.state.generation = 2;
  await expect(edited.session.replay()).rejects.toThrow('discarded');
  expect(select.value).toBe('b');
});

test('shows a stored notice that names nobody with its plain message', async () => {
  const first = fixture();
  await first.session.ready;
  first.session.rejoin({ key: 'noticeDiscarded' });
  first.session.stop();
  const second = fixture();
  await second.session.ready;
  expect(second.form.querySelector('[data-collaborate-notice]').textContent).toBe('Discarded, reloaded');
});

test('registers no shared mode for a rich field whose grant shares none, and applies source typed only in Markdown mode', async () => {
  const name = 'Record[body]';
  const { session, form } = fixture(
    '<textarea id="body" name="Record[body]"></textarea><input type="radio" name="body_view" value="markdown"><input type="radio" name="body_view" value="wysiwyg" checked>',
    { [name]: { type: 'rich', initial: 'Body' } }
  );
  await session.ready;
  (await session.editorConfiguration(form.querySelector('textarea'))).collaborate.ready();
  const field = form.querySelector('textarea');
  const model = new Model();
  model.document.createRoot();
  const apply = vi.fn();
  vi.useFakeTimers();
  const remove = session.registerMode(name, { sourceElement: field, model }, apply);
  expect(session.adapters.has(`${name}[__mode__]`)).toBe(false);
  const type = (value) => {
    field.value = value;
    field.dispatchEvent(new Event('input'));
  };
  // The rich editor's own mirror writes the source while not in Markdown mode, focused or not.
  type('a');
  field.focus();
  type('ab');
  const markdown = form.querySelector('[value="markdown"]');
  markdown.checked = true;
  // A peer's edit the editor mirrors into its focused source is not typing either.
  session.applying = true;
  type('abc');
  session.applying = false;
  await vi.advanceTimersByTimeAsync(100);
  field.blur();
  expect(apply).not.toHaveBeenCalled();
  field.focus();
  type('abcd');
  await vi.advanceTimersByTimeAsync(100);
  expect(apply.mock.calls).toEqual([['abcd']]);
  remove();
  model.destroy();
});

describe('starting on its own', () => {
  afterEach(() => {
    vi.resetModules();
  });

  test('starts no session for a form without a field to follow, and keeps the ordinary form where Join fails', async () => {
    document.body.innerHTML =
      '<form id="hidden-only" data-collaborate="{}"><input type="hidden" name="Record[_token]"></form><form id="shared"><input name="Record[name]"></form>';
    const fields = { 'Record[name]': { type: 'text', initial: '' } };
    const shared = document.getElementById('shared');
    shared.dataset.collaborate = JSON.stringify({
      grant: `${btoa(JSON.stringify({ fields }))}.signature`,
      csrf: 'csrf',
      url: '/__action__',
      messages: { unavailable: 'Unavailable' },
    });
    const fetcher = vi.fn(async () => new Response('{}', { status: 400 }));
    vi.stubGlobal('fetch', fetcher);
    vi.resetModules();
    const module = await import('../../../assets/draft/session.js');
    expect(document.querySelector('#hidden-only [data-collaborate-status]')).toBeNull();
    const session = module.sessionFor(shared.elements.namedItem('Record[name]'));
    live.push(session);
    await expect(session.ready).rejects.toThrow();
    expect(fetcher).toHaveBeenCalledOnce();
    expect(shared.dataset.collaborateState).toBe('unavailable');
    expect(shared.querySelector('input').hasAttribute('inert')).toBe(false);
  });
});

test("without a bridge, the bar's Save stands for the form's first button, not EasyAdmin's", async () => {
  const { session, form } = fixture();
  await session.ready;
  form.insertAdjacentHTML('beforebegin', '<div data-collaborate-layer data-config=\'{"bridge":"none"}\'></div>');
  form.insertAdjacentHTML('afterbegin', '<button type="submit" value="saveAndContinue">Save and continue</button>');
  expect(session.submitter().textContent).toBe('Save and continue');
  form.insertAdjacentHTML('afterbegin', '<button type="submit" value="preview">Preview</button>');
  expect(session.submitter().value).toBe('preview');
});

test("the bar's Save stands for the button that keeps the editor on the page, or the one the host marks", async () => {
  const { session, form } = fixture();
  await session.ready;
  form.id = 'record-form';
  // EasyAdmin draws its page actions outside the form and ties them to it with `form="…"`.
  document.body.insertAdjacentHTML(
    'afterbegin',
    '<button type="submit" form="record-form" name="ea[newForm][btn]" value="saveAndReturn">Save changes</button>' +
      '<button type="submit" form="record-form" name="ea[newForm][btn]" value="saveAndContinue">Save and continue</button>'
  );
  const [ret, stay] = document.querySelectorAll('button[form]');
  expect(session.submitter()).toBe(stay);
  stay.disabled = true;
  expect(session.submitter()).toBe(ret);
  // A new page has no "continue": Save and return, never "add another", which would open an empty form.
  document.body.insertAdjacentHTML(
    'afterbegin',
    '<button type="submit" form="record-form" name="ea[newForm][btn]" value="saveAndAddAnother">Add another</button>'
  );
  expect(session.submitter()).toBe(ret);
  ret.disabled = true;
  expect(session.submitter().value).toBe('saveAndAddAnother');
  ret.disabled = false;
  stay.disabled = false;
  const own = form.querySelector('button[type="submit"]');
  own.setAttribute('data-collaborate-submitter', '');
  expect(session.submitter()).toBe(own);
  // The bar's own buttons are plain buttons, never a submitter.
  expect(session.bar.save.type).toBe('button');
});

test("the bar's Save submits through the draft's own submit path, with that submitter", async () => {
  const { session, form, fetcher, configuration } = fixture();
  await session.ready;
  const transport = fetcher.getMockImplementation();
  fetcher.mockImplementation(async (url, options) => {
    if (!options.method) return Response.json({ configuration, tokens: {} });
    return transport(url, options);
  });
  const nativeSubmit = vi.fn((event) => event.preventDefault());
  form.addEventListener('submit', nativeSubmit);
  const submitted = [];
  vi.spyOn(form, 'requestSubmit').mockImplementation((submitter) => {
    submitted.push(submitter);
    form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter }));
  });
  form.querySelector('input').value = 'Typed';
  form.querySelector('input').dispatchEvent(new Event('input', { bubbles: true }));
  await vi.waitFor(() => expect(session.bar.save.hidden).toBe(false));
  session.bar.save.click();
  await vi.waitFor(() => expect(nativeSubmit).toHaveBeenCalledOnce());
  const button = form.querySelector('button[type="submit"]');
  // The bar's click asks for a submission, which the draft holds until synchronized, then posts it again.
  expect(submitted).toEqual([button, button]);
  expect(nativeSubmit.mock.calls[0][0].submitter).toBe(button);
  expect(form.elements.namedItem('_collaborate_revision').value).toBe(String(session.revision));
});

test("the bar's Save asks for a plain submission when the form has no submit button", async () => {
  const { session, form } = fixture();
  await session.ready;
  form.querySelector('button[type="submit"]').remove();
  const requested = vi.spyOn(form, 'requestSubmit').mockImplementation(() => {});
  session.saveFromBar();
  expect(requested).toHaveBeenCalledWith();
});
