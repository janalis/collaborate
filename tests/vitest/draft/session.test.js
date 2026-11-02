vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: vi.fn(() => Promise.resolve()) }));

vi.mock('../../../assets/draft/bar.js', async (original) => ({
  ...(await original()),
  confirmAction: vi.fn(async () => true),
}));

import { Model } from 'ckeditor5';
import { confirmAction } from '../../../assets/draft/bar.js';
import { DraftSession, sessionFor } from '../../../assets/draft/session.js';

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
  session.editorReady('Record[body]');
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
  const waiter = session.editorWaiter('body');
  session.editorReady('body');
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
  fetcher.mockResolvedValueOnce(new Response('{}', { status: 403 }));
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
  session.editorReady(name);
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
  session.registerMode(name, { sourceElement: null }, apply)();
  model.destroy();
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
  session.editorReady(name);
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
  const fourth = fixture();
  await fourth.session.ready;
  fourth.state.events.push({ field: 'Record[name]', version: 100, revision: 1, operations: [] });
  await expect(fourth.session.pull()).rejects.toThrow('gap');
  expect(fourth.form.dataset.collaborateState).toBe('incompatible');
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
  const calls = fetcher.mock.calls.length;
  await vi.advanceTimersByTimeAsync(5000);
  expect(fetcher.mock.calls.length).toBe(calls);
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
  session.stopped = true;
  form.querySelector('[data-collaborate-discard]').click();
  await vi.waitFor(() => expect(fetcher.mock.calls.some(([url]) => url === '/discard')).toBe(true));
  await session.reconcile(false);
  expect(fetcher.mock.calls.some(([url]) => url === '/reconcile')).toBe(true);
});

test('waits for rich editor initialization and rejects an editor that never becomes ready', async () => {
  vi.useFakeTimers();
  const { session } = fixture('<textarea name="Record[body]"></textarea>', {
    'Record[body]': { type: 'rich', initial: 'Body' },
  });
  await session.ready;
  const pending = session.flush();
  const rejected = expect(pending).rejects.toThrow('editors are not ready');
  await vi.advanceTimersByTimeAsync(15_000);
  await rejected;
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
  fetcher.mockRejectedValue(new Error('offline'));
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true }));
  await vi.waitFor(() => expect(form.dataset.collaborateState).toBe('offline'));
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
  refresh.mockRejectedValueOnce(Object.assign(new Error('busy'), { status: 503 }));
  refresh.mockRejectedValueOnce(Object.assign(new Error('busy'), { status: 429 }));
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
  ['a busy server', 'offline', Object.assign(new Error('busy'), { status: 503 })],
  ['a rate limit', 'offline', Object.assign(new Error('limited'), { status: 429 })],
  ['a refusal', 'failed', Object.assign(new Error('refused'), { status: 400 })],
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
  session.failed(Object.assign(new Error('changed'), { status: 409 }));
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
  session.failed(Object.assign(new Error('refused'), { status: 400 }));
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
  expect(button.disabled).toBe(false);
  const discarding = session.discardDraft();
  await vi.waitFor(() => expect(release).toBeTypeOf('function'));
  expect(button.disabled).toBe(true);
  release();
  await discarding;
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
  session.failed(Object.assign(new Error('changed'), { status: 409 }));
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
  session.editorReady(name);
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
