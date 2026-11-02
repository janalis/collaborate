import { hasPendingUpload } from '../ckeditor5/source.js';
import { hubFor } from '../hub.js';
import { CollaborateBar, confirmAction } from './bar.js';
import { initializeCollections } from './collections.js';
import { renderConflicts } from './conflicts.js';
import { initializeText, initializeValue, valueAdapter } from './fields.js';

// Captured when the module is evaluated: draft traffic is background traffic, and a host that wraps
// `window.fetch` (a loading indicator, say) must load easyadmin.js before installing its wrapper.
const backgroundFetch = window.fetch.bind(window);
const sessions = new WeakMap();
const grantScope = (grant) =>
  JSON.parse(
    new TextDecoder().decode(Uint8Array.from(atob(grant.split('.')[0]), (character) => character.charCodeAt(0)))
  );
const escapePattern = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const matchers = new WeakMap();
/** Compiled once per grant schema: every form mutation looks each control up again. */
const matcher = (schema) => {
  if (!matchers.has(schema)) {
    const entries = Object.entries(schema);
    matchers.set(schema, {
      definitions: entries.map(([pattern, definition]) => [
        pattern,
        definition,
        new RegExp(
          `^${escapePattern(pattern)
            .replaceAll('__name__', '[a-zA-Z0-9_-]{1,80}')
            .replaceAll('__member__', '@[a-zA-Z0-9_-]{1,80}')}$`
        ),
      ]),
      keys: entries.map(([key, field]) => {
        const physical = field.formName || key;
        return [
          key,
          physical,
          new RegExp(`^${escapePattern(physical).replaceAll('__name__', '[a-zA-Z0-9_-]{1,80}')}$`),
        ];
      }),
      fieldKeys: new Map(),
    });
  }
  return matchers.get(schema);
};

/** A form owns one ordered network queue; individual fields own their model versions. */
export class DraftSession {
  constructor(form, configuration, fetcher = backgroundFetch, hub = hubFor()) {
    this.form = form;
    this.configuration = configuration;
    this.fetcher = fetcher;
    this.hub = hub;
    this.adapters = new Map();
    this.history = new Map();
    // Typed Markdown source not yet applied to its editor's model: flush() applies it before sending.
    this.sources = new Map();
    this.revision = 0;
    this.generation = 0;
    // The highest revision a notice announced while a replay was under way (Infinity: unknown).
    this.awaited = 0;
    this.abort = new AbortController();
    this.removers = [];
    this.bound = new WeakSet();
    this.editorWaiters = new Map();
    this.schema = grantScope(configuration.grant).fields;
    // Lock existing content, not the live status/recovery controls added below.
    // Inert leaves Symfony/widget disabled and readonly settings untouched.
    // Widgets clone individual controls while booting. Lock their containers
    // so those clones cannot inherit a temporary inert attribute.
    this.startupControls = Array.from(
      new Set([...form.children, ...Array.from(form.elements).filter((element) => !form.contains(element))])
    ).filter((element) => !element.hasAttribute('inert'));
    for (const element of this.startupControls) element.setAttribute('inert', '');
    this.bar = new CollaborateBar(configuration.messages, () => this.discardDraft());
    this.discard = this.bar.discard;
    form.prepend(this.bar.element);
    this.setStatus('connecting');
    this.conflictPanel = document.createElement('section');
    this.conflictPanel.className = 'collaborate-conflicts alert alert-warning';
    this.conflictPanel.hidden = true;
    this.conflictPanel.dataset.collaborateConflicts = '';
    this.bar.element.after(this.conflictPanel);
    this.earlySubmit = (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
    };
    form.addEventListener('submit', this.earlySubmit, true);
    this.ready = this.start();
    this.compositionStart = () => {
      this.composing = true;
    };
    this.compositionEnd = () => {
      this.composing = false;
      this.schedule();
    };
    form.addEventListener('compositionstart', this.compositionStart);
    form.addEventListener('compositionend', this.compositionEnd);
    this.unload = (event) => (event.persisted ? this.suspend() : this.stop());
    this.restore = (event) => {
      if (event.persisted) this.resume();
    };
    window.addEventListener('pagehide', this.unload);
    window.addEventListener('pageshow', this.restore);
  }

  /** A timing or size the form's `config` sets, or its default. */
  setting(name, fallback) {
    return this.configuration.config?.[name] ?? fallback;
  }

  /** Shows a state; `name`, another editor's, takes the message that names who did it. */
  setStatus(key, name = null) {
    this.form.dataset.collaborateState = key;
    this.bar.setState(key, this.message(key, name));
  }

  message(key, name = null) {
    const messages = this.configuration.messages;
    if (name && messages[`${key}By`]) return messages[`${key}By`].replace('%name%', name);
    return messages[key] || key;
  }

  /** The other editor who did what `actor` names, or nobody: this account's own sessions are no one else. */
  peerName(actor) {
    return actor?.label && actor.userId && actor.userId !== grantScope(this.configuration.grant).userId
      ? actor.label
      : null;
  }

  /** Saved or unsaved, naming the other editor whose Save the draft holds. */
  settled() {
    if (this.revision === this.state.savedRevision) this.setStatus('saved', this.peerName(this.state.savedBy));
    else this.setStatus('draft');
  }

  /** What a failed action tells the editor: a lost connection waits, a refusal does not. */
  failed(error) {
    if (this.stopped || this.suspended || error?.name === 'AbortError') return;
    const status = error?.status;
    if (!status || status === 429 || status >= 500) this.setStatus('offline');
    else if (status === 409) {
      this.setStatus('changed');
      this.pull().catch(() => {});
    } else this.setStatus('failed');
  }

  async request(action, body = {}, renewed = false) {
    if (this.suspended) throw new DOMException('Collaborate session suspended.', 'AbortError');
    const signal = this.abort.signal;
    const grant = this.configuration.grant;
    // Once the server has verified this grant, its signature stands for the whole field schema.
    const short = this.acknowledged === grant;
    const response = await this.fetcher(this.configuration.url.replace('__action__', action), {
      method: 'POST',
      credentials: 'same-origin',
      signal,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.configuration.csrf },
      body: JSON.stringify({ grant: short ? grant.split('.')[1] : grant, ...body }),
    });
    signal.throwIfAborted();
    if (short && response.status === 412) {
      if (this.acknowledged === grant) this.acknowledged = null;
      return this.request(action, body, renewed);
    }
    if (response.ok) this.acknowledged = grant;
    if (response.redirected || [401, 403].includes(response.status)) {
      // A grant expires after 10 h: the form's action signs another one, unless the editor lost the right to it.
      if (!response.redirected && !renewed && (await this.renewGrant(grant))) return this.request(action, body, true);
      if (!this.stopped) this.halt('denied');
      throw new Error('Collaborate permission expired.');
    }
    if (!response.ok) {
      if (response.status === 413) this.halt('full');
      const error = new Error(`Collaborate ${action}: ${response.status}`);
      error.status = response.status;
      throw error;
    }
    return response.status === 204 ? null : response.json();
  }

  async start() {
    try {
      // A Save holds the draft for up to 10 s: Join keeps asking a little longer than that.
      const deadline = Date.now() + this.setting('joinRetry', 12_000);
      for (;;) {
        try {
          this.state = await this.request('join');
          break;
        } catch (error) {
          if (this.stopped || Date.now() >= deadline || ![429, 503].includes(error.status)) throw error;
          await new Promise((resolve) => setTimeout(resolve, 1000));
        }
      }
      this.generation = this.state.generation;
      this.revision = this.state.revision;
      this.digest = this.state.digest;
      this.stamp = this.state.stamp;
      for (const event of this.state.events) this.remember(event);
      for (const [name, field] of Object.entries(this.state.fields)) {
        if (field.type === 'rich' && this.form.elements.namedItem(this.formName(name))) this.editorWaiter(name);
      }
      this.removers.push(initializeCollections(this));
      this.initializeControls();
      if (this.state.recordId && this.configuration.isNew) {
        this.recordCreated(this.state.recordId);
        return;
      }
      this.onSubmit = async (event) => {
        if (this.submitting) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        // This handler stops EasyAdmin's own double-submit guard: a second Save meanwhile would post the form twice.
        if (this.composing || this.suspended || this.resuming || this.saving) return;
        this.saving = true;
        try {
          await this.refreshRecord();
          await this.flush();
          if (this.stopped || this.suspended || this.resuming || this.composing) return;
          if (Object.keys(this.conflicts).length) {
            this.setStatus('conflict');
            this.conflictPanel.scrollIntoView?.({ block: 'nearest' });
            this.conflictPanel.querySelector('h2')?.focus();
            return;
          }
          this.hidden('_collaborate_grant', this.configuration.grant);
          this.hidden('_collaborate_revision', String(this.revision));
          this.hidden('_collaborate_generation', String(this.generation));
          this.submitting = true;
          // The form posts the revision above: an edit sent after it would read as a peer's and fail the save.
          // A submission that no handler cancelled leaves the page; one blocked by validation dispatches nothing.
          const posted = (submission) => {
            if (!submission.defaultPrevented) this.posted = true;
          };
          window.addEventListener('submit', posted);
          try {
            if (event.submitter) this.form.requestSubmit(event.submitter);
            else this.form.requestSubmit();
          } finally {
            window.removeEventListener('submit', posted);
            this.submitting = false;
          }
        } catch (error) {
          this.failed(error);
        } finally {
          this.saving = false;
        }
      };
      this.form.addEventListener('submit', this.onSubmit, true);
      this.form.removeEventListener('submit', this.earlySubmit, true);
      if (this.configuration.invitation) this.bar.invitation(this.configuration.invitation);
      this.startTimers();
      this.settled();
      this.showNotice();
      // Every Save and replay comes after this: `this.conflicts` holds a set of conflicts from here on.
      this.renderConflicts(this.state.conflicts || {});
      this.initialized = true;
      this.unlockControls();
      this.connect();
    } catch (error) {
      if (!this.stopped) this.setStatus('unavailable');
      // Without a shared draft the form keeps the ordinary Symfony submission path.
      this.stop();
      throw error;
    }
  }

  hidden(name, value) {
    let control = this.form.elements.namedItem(name);
    if (!control) {
      control = document.createElement('input');
      control.type = 'hidden';
      control.name = name;
      this.form.append(control);
    }
    control.value = value;
  }

  async editorConfiguration(field) {
    try {
      await this.ready;
    } catch {
      return {};
    }
    const name = this.fieldKey(field.name);
    const state = this.ensureField(name);
    if (!state) return {};
    this.editorWaiter(name);
    return { initialData: state.initial, collaborate: { getSession: () => this, field: name } };
  }

  editorWaiter(name) {
    if (!this.editorWaiters.has(name)) {
      const waiter = { ready: false };
      const promise = new Promise((done) => {
        waiter.resolve = () => {
          waiter.ready = true;
          done();
          this.unlockControls();
        };
      });
      waiter.promise = promise;
      this.editorWaiters.set(name, waiter);
    }
    return this.editorWaiters.get(name);
  }

  editorReady(name) {
    this.editorWaiter(this.fieldKey(name)).resolve();
  }

  // Without every editor the draft cannot be followed; keep the ordinary submission path.
  editorFailed() {
    if (this.stopped) return;
    this.setStatus('unavailable');
    this.stop();
  }

  unlockControls() {
    if (!this.initialized || this.stopped || Array.from(this.editorWaiters.values()).some((waiter) => !waiter.ready))
      return;
    this.releaseControls();
  }

  releaseControls() {
    for (const element of this.startupControls) element.removeAttribute('inert');
    this.startupControls = [];
  }

  register(name, buffer) {
    const state = this.state.fields[name];
    if (
      Number.isInteger(buffer.version) &&
      state.initialVersion !== undefined &&
      buffer.version !== state.initialVersion
    ) {
      this.incompatible();
      throw new Error('Collaborate editor initialization differs from the stored draft.');
    }
    this.adapters.set(name, buffer);
    try {
      for (const event of this.history.get(name) || []) buffer.receive(event);
    } catch (error) {
      this.incompatible();
      throw error;
    }
    return () => this.adapters.delete(name);
  }

  /** What a field registered later replays: a value replaces the one before it, model operations accumulate. */
  remember(event) {
    if (event.kind === 'discard') return;
    const events = this.history.get(event.field);
    if (event.kind === 'value' || !events) this.history.set(event.field, [event]);
    else events.push(event);
  }

  // Save stays paused, but the page must remain readable so unsent content can be copied.
  incompatible() {
    this.halt('incompatible');
    this.releaseControls();
  }

  /** Stops following the draft: no more requests, stream or timers. The form keeps its collaborative Save guard. */
  halt(status) {
    this.stopped = true;
    if (status) this.setStatus(status);
    this.disconnect();
    clearInterval(this.poll);
    clearTimeout(this.timer);
    clearTimeout(this.recordTimer);
    this.timer = null;
    this.recordTimer = null;
  }

  registerMode(name, editor, applySource) {
    const field = editor.sourceElement;
    if (!field) return () => {};
    const radios = Array.from(this.form.querySelectorAll('input[type="radio"]')).filter(
      (radio) => radio.name === `${field.id}_view`
    );
    const readOnly = field.readOnly;
    const uploadChanged = () => {
      const pendingUpload = hasPendingUpload(editor.model.document.getRoot());
      field.readOnly = readOnly || pendingUpload;
      for (const radio of radios) {
        if (radio.value === 'markdown') radio.disabled = field.disabled || pendingUpload;
      }
    };
    editor.model.document.on('change:data', uploadChanged);
    uploadChanged();
    let remote = false;
    const adapter = valueAdapter(this, (event, pending) => {
      if (pending) return;
      remote = true;
      try {
        for (const radio of radios) {
          radio.checked = radio.value === event.value;
          if (radio.checked) radio.dispatchEvent(new Event('change'));
        }
      } finally {
        remote = false;
      }
    });
    const modeName = `${name}[__mode__]`;
    if (this.ensureField(modeName)) this.register(modeName, adapter);
    const modeChanged = (event) => {
      if (remote || !event.target.checked) return;
      adapter.send(event.target.value);
    };
    // Parsing the whole document on every keystroke lags a long field: typing settles first. Blur (a mode
    // switch blurs the source) and flush() apply what is still pending.
    let sourceTimer = null;
    const settle = () => {
      if (sourceTimer === null) return;
      clearTimeout(sourceTimer);
      sourceTimer = null;
      applySource(field.value);
    };
    const sourceChanged = () => {
      if (
        !this.applying &&
        document.activeElement === field &&
        radios.some((radio) => radio.checked && radio.value === 'markdown')
      ) {
        clearTimeout(sourceTimer);
        sourceTimer = setTimeout(settle, 100);
      }
    };
    this.sources.set(name, settle);
    for (const radio of radios) radio.addEventListener('change', modeChanged);
    field.addEventListener('input', sourceChanged);
    field.addEventListener('blur', settle);
    return () => {
      clearTimeout(sourceTimer);
      this.sources.delete(name);
      editor.model.document.off('change:data', uploadChanged);
      this.adapters.delete(modeName);
      for (const radio of radios) radio.removeEventListener('change', modeChanged);
      field.removeEventListener('input', sourceChanged);
      field.removeEventListener('blur', settle);
    };
  }

  /** Applies a rich field's source typing that still waits for its pause. */
  settleSource(name) {
    this.sources.get(name)?.();
  }

  initializeControls() {
    for (const control of this.form.elements) {
      if (control.name && !this.state.fields[this.fieldKey(control.name)])
        this.ensureField(this.fieldKey(control.name));
    }
    for (const [name, state] of Object.entries(this.state.fields)) {
      const control = this.form.elements.namedItem(this.formName(name));
      const element = control instanceof RadioNodeList ? control[0] : control;
      if (!element || !['text', 'value'].includes(state.type) || this.bound.has(element)) continue;
      this.bound.add(element);
      if (state.type === 'text' && control instanceof HTMLElement) {
        initializeText(this, control, name, state);
      } else {
        initializeValue(this, control, name, state);
      }
    }
  }

  ensureField(name) {
    if (this.state.fields[name]) return this.state.fields[name];
    const definition = this.definition(name);
    if (definition) {
      const state = { ...definition, value: definition.initial, saved: definition.initial, version: null };
      this.state.fields[name] = state;
      return state;
    }
    return null;
  }

  definition(name, schema = this.schema) {
    for (const [pattern, definition, expression] of matcher(schema).definitions) {
      if (expression.test(name)) {
        return {
          ...definition,
          ...(definition.formName ? { formName: name.slice(pattern.length - definition.formName.length) } : {}),
        };
      }
    }
    return null;
  }

  async refreshConfiguration(configuration, pull = true) {
    const before = grantScope(this.configuration.grant);
    const after = grantScope(configuration.grant);
    if (before.id !== after.id || before.userId !== after.userId || before.rolesHash !== after.rolesHash) {
      this.halt('denied');
      return;
    }
    for (const [name, state] of Object.entries(this.state.fields)) {
      const definition = this.definition(name, after.fields);
      if (state.schema && definition?.schema && state.schema !== definition.schema) {
        this.halt('incompatible');
        return;
      }
    }
    this.configuration = configuration;
    this.schema = after.fields;
    // The conflicts compare the draft with this grant's record: the next replay reads them again.
    this.stamp = null;
    if (!pull) return;
    // A replay already running answers for the previous grant: read again once it is done.
    await this.pulling?.catch(() => {});
    await this.pull();
  }

  /** Renews a refused grant once for every request it failed: whether they may go again with the new one. */
  async renewGrant(grant) {
    if (this.stopped || !this.initialized) return false;
    if (this.configuration.grant !== grant) return true;
    // The request it failed may be the replay a pull waits for: the retry, or the next replay, reads the draft.
    this.renewal ??= this.refreshRecord(false)
      .then(
        () => true,
        () => false
      )
      .finally(() => {
        this.renewal = null;
      });
    return (await this.renewal) && !this.stopped && this.configuration.grant !== grant;
  }

  async refreshRecord(pull = true) {
    if (this.stopped) return;
    // The action the form posts to answers its grant and CSRF tokens alone (the `X-Collaborate-Refresh` header);
    // a new-record form's action carries the draft key its grant names.
    const response = await this.fetcher(this.form.action, {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: this.abort.signal,
      headers: { Accept: 'application/json', 'X-Collaborate-Refresh': '1' },
    });
    // A transient failure keeps the session; Save retries the refresh.
    if (!response.ok && !response.redirected && ![401, 403, 404].includes(response.status)) {
      const error = new Error(`Collaborate record refresh: ${response.status}`);
      error.status = response.status;
      throw error;
    }
    // A sign-in page, or a form no longer shared, reads as no configuration.
    const refreshed = response.ok && !response.redirected ? await response.json().catch(() => null) : null;
    if (!refreshed?.configuration) {
      this.halt('denied');
      throw new Error('Collaborate record is unavailable.');
    }
    for (const [name, value] of Object.entries(refreshed.tokens || {})) {
      const current = this.form.elements.namedItem(name);
      if (current) current.value = value;
    }
    await this.refreshConfiguration(refreshed.configuration, pull);
  }

  formName(name) {
    return this.state.fields[name]?.formName || name;
  }

  fieldKey(name) {
    if (this.schema[name]) return name;
    const { keys, fieldKeys } = matcher(this.schema);
    if (!fieldKeys.has(name)) {
      const match = keys.find(([, , expression]) => expression.test(name));
      fieldKeys.set(name, match ? match[0].slice(0, match[0].length - match[1].length) + name : name);
    }
    return fieldKeys.get(name);
  }

  schedule(delay = 150) {
    if (this.stopped || this.suspended || this.posted || this.timer) return;
    this.timer = setTimeout(() => {
      this.timer = null;
      this.flush().catch((error) => {
        if (this.stopped || this.suspended) return;
        // The server refused this batch: sending it again would be refused again.
        if (error?.status >= 400 && error.status < 500 && ![409, 429].includes(error.status)) {
          this.halt('incompatible');
          return;
        }
        // A save holds the draft: the server answers at once, and the edits wait here a moment.
        const busy = [429, 503].includes(error?.status);
        if (!busy) this.setStatus('offline');
        this.schedule(busy ? 1000 : 150);
      });
    }, delay);
  }

  /** The record was saved outside this draft: compare the form with it again, once a second at most. */
  recordChanged() {
    if (this.configuration.isNew || this.stopped || this.suspended || this.recordTimer) return;
    this.recordTimer = setTimeout(async () => {
      this.recordTimer = null;
      try {
        await this.refreshRecord();
      } catch (error) {
        // A busy server read nothing: compare again a second later. Otherwise Save refreshes the record again before it submits.
        if ([429, 503].includes(error?.status)) this.recordChanged();
      }
    }, 1000);
  }

  /** Whether this page shows exactly the saved draft: nothing unsaved, nothing unsent. */
  clean() {
    return this.revision === this.state.savedRevision && this.sent();
  }

  /** Every edit made on this page reached the journal. */
  sent() {
    return Array.from(this.adapters.values()).every((adapter) =>
      adapter.hasPending ? !adapter.hasPending() : !adapter.next()
    );
  }

  // EasyAdmin's DirtyForm prompts for any form edited since it loaded, a peer's edits included; the draft or
  // the editor's explicit choice holds what this reload keeps, and a page left halted would be stuck.
  // `notice` says, once the page is back, why it reloaded.
  rejoin(notice = null) {
    if (notice) {
      try {
        sessionStorage.setItem(this.noticeKey(), JSON.stringify(notice));
      } catch {
        // Without storage the page reloads without saying why.
      }
    }
    window.onbeforeunload = null;
    location.reload();
  }

  noticeKey() {
    return `collaborate-notice:${grantScope(this.configuration.grant).id}`;
  }

  /** The reason the previous load of this page gave for reloading it, shown once. */
  showNotice() {
    let notice = null;
    try {
      notice = JSON.parse(sessionStorage.getItem(this.noticeKey()) || 'null');
      sessionStorage.removeItem(this.noticeKey());
    } catch {
      return;
    }
    if (typeof notice?.key === 'string' && this.configuration.messages[notice.key])
      this.bar.notice(this.message(notice.key, typeof notice.name === 'string' ? notice.name : null));
  }

  async pull() {
    if (this.composing) return;
    if (this.pulling) return this.pulling;
    this.pulling = (async () => {
      // A notice that arrives while a replay is under way may announce a write that replay read before.
      do {
        this.stale = false;
        this.awaited = 0;
        await this.replay();
      } while (this.stale && this.awaited > this.revision && !this.stopped && !this.suspended && !this.composing);
    })();
    try {
      await this.pulling;
    } catch (error) {
      // A notice heard during the failed replay still needs one.
      if (this.stale) this.schedule();
      throw error;
    } finally {
      this.pulling = null;
    }
  }

  /**
   * The draft no longer holds what this page shows: compacted, purged, or a field of it discarded.
   * `discard` is the Discard event that reset a field, naming who discarded it; `rebased` says a compaction
   * replaced this page's generation.
   */
  superseded(discard = null, rebased = false) {
    const name = this.peerName(discard);
    // A draft that had nothing unsaved holds what this page shows. A compaction keeps only saved content, so
    // a page that sent every edit loses none by reloading, even one that has not heard of the save yet.
    if (rebased ? this.sent() : this.clean()) {
      this.halt('connecting');
      this.rejoin(name ? { key: 'noticeDiscarded', name } : null);
      throw new Error('Collaborate draft was rebased; rejoining.');
    }
    this.halt('discarded');
    if (name) this.setStatus('discarded', name);
    throw new Error('Collaborate draft was discarded; local content is retained.');
  }

  async replay() {
    // The digest names the field states already held, and the stamp the draft row last read: an unchanged
    // draft sends no field state, and one nobody wrote to answers from its row alone.
    const configuration = this.configuration;
    let state;
    try {
      state = await this.request('replay', { since: this.revision, digest: this.digest, stamp: this.stamp });
    } catch (error) {
      // Replay answers 409 for a missing draft only: the purge deleted it while this page stayed open.
      if (error.status === 409) this.superseded();
      throw error;
    }
    this.pulled = Date.now();
    if (this.composing) return;
    if (state.unchanged) {
      // Nobody wrote to the draft since, but the answer proves the connection is back.
      if (['offline', 'changed'].includes(this.form.dataset.collaborateState)) {
        this.settled();
        if (this.configuration === configuration) this.renderConflicts(this.conflicts);
      }
      return;
    }
    if (state.generation !== this.generation) this.superseded(null, state.rebasedFrom.includes(this.generation));
    if (state.revision < this.revision) return;
    for (const [name, field] of Object.entries(state.fields)) {
      const definition = this.definition(name);
      if (field.schema && definition?.schema && field.schema !== definition.schema) {
        this.halt('incompatible');
        throw new Error('Collaborate schema changed.');
      }
      this.state.fields[name] = { ...this.state.fields[name], ...field };
    }
    for (const event of state.events) {
      if (event.revision <= this.revision) continue;
      // The server names a Discard only to the pages showing a field it reset.
      if (event.kind === 'discard') this.superseded(event);
      try {
        this.adapters.get(event.field)?.receive(event);
      } catch (error) {
        this.halt('incompatible');
        throw error;
      }
      this.remember(event);
    }
    this.revision = state.revision;
    this.digest = state.digest;
    // Conflicts read with a grant replaced meanwhile compare the previous record: keep reading them.
    const current = this.configuration === configuration;
    this.stamp = current ? state.stamp : null;
    this.state.savedRevision = state.savedRevision;
    this.state.savedBy = state.savedBy;
    if (Object.keys(state.fields).length) this.scanCollections?.();
    if (state.recordId && this.configuration.isNew && !this.submitting) {
      this.recordCreated(state.recordId);
      throw new Error('Collaborate record created.');
    }
    this.settled();
    if (current) this.renderConflicts(state.conflicts || {});
  }

  renderConflicts(conflicts) {
    renderConflicts(this, conflicts);
  }

  /** Asks before an action that every editor of the record sees: `kind` names its messages. */
  confirm(kind) {
    const messages = this.configuration.messages;
    return confirmAction({
      title: messages[`${kind}Title`],
      body: messages[`${kind}Body`],
      confirm: messages[`${kind}Accept`],
      cancel: messages.cancel,
    });
  }

  async reconcile(keep) {
    if (!keep && !(await this.confirm('confirmSaved'))) return;
    this.bar.setBusy(true);
    try {
      if (keep) {
        // A field without draft edits has nothing to keep: it takes the saved record value,
        // as an ordinary edit, so peers converge and Save does not revert the external change.
        for (const [name, value] of Object.entries(this.conflicts)) {
          const state = this.state.fields[name];
          const adapter = this.adapters.get(name);
          if (state && state.version === (state.savedVersion ?? null) && !adapter?.next()) adapter?.adopt?.(value);
        }
      }
      if (!this.stopped) await this.flush();
      await this.request('reconcile', { generation: this.generation, revision: this.revision, keep });
      if (keep) await this.pull();
      else this.rejoin({ key: 'noticeUsedSaved' });
    } catch (error) {
      this.failed(error);
    } finally {
      this.bar.setBusy(false);
    }
  }

  async flush() {
    if (this.stopped) throw new Error('Collaborate session stopped.');
    if (this.suspended) throw new DOMException('Collaborate session suspended.', 'AbortError');
    if (this.running) return this.running;
    this.running = (async () => {
      await this.ready;
      let editorTimeout;
      try {
        await Promise.race([
          Promise.all(Array.from(this.editorWaiters.values(), (waiter) => waiter.promise)),
          new Promise((_resolve, reject) => {
            editorTimeout = setTimeout(() => reject(new Error('Collaborate editors are not ready.')), 15_000);
          }),
        ]);
      } finally {
        clearTimeout(editorTimeout);
      }
      await this.pull();
      for (const settle of this.sources.values()) settle();
      for (;;) {
        // A flush already under way when the form was posted sends nothing more.
        if (this.composing || this.posted) return;
        let outgoing;
        for (const [field, adapter] of this.adapters) {
          const batch = adapter.next();
          if (batch) {
            // `seen` names the revision this edit was made on: one made before a Discard reset its field is refused.
            outgoing = { kind: 'operations', ...batch, field, generation: this.generation, seen: this.revision };
            break;
          }
        }
        if (!outgoing) return;
        this.setStatus('pending');
        try {
          await this.request('append', outgoing);
        } catch (error) {
          if (error.status !== 409) throw error;
        }
        await this.pull();
      }
    })();
    try {
      await this.running;
    } finally {
      this.running = null;
    }
  }

  /** Follows the draft's topic on the page's one Mercure stream; without it, the page replays every 5 s (`replayDisconnected`). */
  connect() {
    if (this.stopped || this.suspended || this.leaveHub || !this.hub) return;
    this.leaveHub = this.hub.join({
      channel: 'draft',
      draft: grantScope(this.configuration.grant).id,
      // Once the server has verified this grant, its signature stands for the whole field schema.
      grant: (full) => {
        const grant = this.configuration.grant;
        return !full && this.acknowledged === grant ? grant.split('.')[1] : grant;
      },
      receive: (_message, event) => this.received(event),
      opened: () => {
        this.live = true;
        this.schedule();
      },
      closed: () => {
        this.live = false;
        if (!this.stopped && !this.suspended) this.setStatus('offline');
      },
      denied: () => {
        this.live = false;
        this.leaveHub = null;
      },
    });
  }

  disconnect() {
    this.live = false;
    this.leaveHub?.();
    this.leaveHub = null;
  }

  received(event) {
    if (event.kind === 'record') {
      this.recordChanged();
      return;
    }
    // An append of this generation names its revision: one this page has read, its own echo included,
    // needs no replay. Any other notice may change anything.
    const revision =
      event.kind === 'append' && event.generation === this.generation && Number.isInteger(event.revision)
        ? event.revision
        : Infinity;
    if (revision <= this.revision) return;
    this.stale = true;
    this.awaited = Math.max(this.awaited, revision);
    // A replay under way reads again once done, unless it reached that revision.
    if (!this.pulling) this.schedule();
  }

  async discardDraft() {
    if (!(await this.confirm('confirmDiscard'))) return;
    this.bar.setBusy(true);
    try {
      if (!this.stopped) await this.flush();
      await this.request('discard', { generation: this.generation, revision: this.revision });
      this.rejoin({ key: 'noticeDiscardedOwn' });
    } catch (error) {
      this.failed(error);
    } finally {
      this.bar.setBusy(false);
    }
  }

  recordCreated(id) {
    this.releaseControls();
    this.halt('created');
    const name = this.peerName(this.state.savedBy);
    if (name) this.setStatus('created', name);
    this.bar.openRecord(this.configuration.editUrl.replace('__record__', encodeURIComponent(id)));
  }

  startTimers() {
    if (this.stopped || this.suspended) return;
    this.poll = setInterval(
      () => {
        // The hub announces every change. While it is connected, a pull every 30 s only covers a lost publication.
        if (!this.live || !(Date.now() - this.pulled < this.setting('replayConnected', 30_000))) this.schedule();
      },
      this.setting('replayDisconnected', 5000)
    );
  }

  suspend() {
    this.suspended = true;
    // Join must finish building adapters even if navigation freezes startup.
    if (this.initialized || this.stopped) this.abort.abort();
    this.disconnect();
    clearInterval(this.poll);
    clearTimeout(this.timer);
    clearTimeout(this.recordTimer);
    this.timer = null;
    this.recordTimer = null;
  }

  resume() {
    if (!this.suspended || this.stopped) return;
    if (this.resuming) return this.resuming.then(() => this.resume());
    this.resuming = (async () => {
      // Aborted requests must settle before replacing their shared transport.
      await Promise.allSettled([this.ready, this.running, this.pulling]);
      if (this.stopped) return;
      await this.ready;
      this.abort = new AbortController();
      this.suspended = false;
      // Back from the page a Save left: edits made since then are sent again.
      this.posted = false;
      this.startTimers();
      await this.refreshRecord();
      await this.flush();
      this.connect();
    })()
      .catch((error) => this.failed(error))
      .finally(() => {
        this.resuming = null;
      });
    return this.resuming;
  }

  stop() {
    this.releaseControls();
    this.stopped = true;
    this.suspend();
    this.form.removeEventListener('submit', this.onSubmit, true);
    this.form.removeEventListener('submit', this.earlySubmit, true);
    this.form.removeEventListener('compositionstart', this.compositionStart);
    this.form.removeEventListener('compositionend', this.compositionEnd);
    window.removeEventListener('pagehide', this.unload);
    window.removeEventListener('pageshow', this.restore);
    for (const remove of this.removers) remove();
  }
}

export function sessionFor(field) {
  const form = field.form;
  if (!form?.dataset.collaborate) return null;
  if (!sessions.has(form)) sessions.set(form, new DraftSession(form, JSON.parse(form.dataset.collaborate)));
  return sessions.get(form);
}

export function initializeCollaboration() {
  for (const form of document.querySelectorAll('form[data-collaborate]')) {
    const field = Array.from(form.elements).find((element) => element.name && element.type !== 'hidden');
    if (field) sessionFor(field)?.ready.catch(() => {});
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeCollaboration);
else initializeCollaboration();
