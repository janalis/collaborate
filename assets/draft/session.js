import { bridge } from '../bridge.js';
import { hasPendingUpload } from '../ckeditor5/source.js';
import { backgroundFetch, HttpError, isAccepted, isDenied, postJson, statusOf } from '../http.js';
import { hubFor } from '../hub.js';
import { CollaborateBar, confirmAction } from './bar.js';
import { initializeCollections } from './collections.js';
import { renderConflicts } from './conflicts.js';
import { initializeText, initializeValue, valueAdapter } from './fields.js';

/**
 * What a form's `data-collaborate` attribute holds (FormDraft's Configuration).
 *
 * @typedef {object} DraftConfiguration
 * @property {string} grant
 * @property {string} url
 * @property {string} csrf
 * @property {Record<string, string>} messages
 * @property {boolean} isNew
 * @property {string} editUrl
 * @property {string | null} invitation
 * @property {Record<string, number>} config
 */

/**
 * A field's state in the draft, as Join and Replay answer it, or as the grant's schema defines a field added since.
 *
 * @typedef {object} DraftField
 * @property {string} type text, value, rich, member or collection
 * @property {any} initial
 * @property {any} value
 * @property {any} [saved]
 * @property {number | null} [version]
 * @property {number | null} [savedVersion]
 * @property {number} [initialVersion]
 * @property {string} [schema]
 * @property {string} [formName]
 */

/**
 * What Join answers: the draft at its current revision.
 *
 * @typedef {object} DraftState
 * @property {Record<string, DraftField>} fields
 * @property {DraftEvent[]} events
 * @property {number} generation
 * @property {number} revision
 * @property {number} savedRevision
 * @property {Actor | null} [savedBy]
 * @property {string} [digest]
 * @property {string} [stamp]
 * @property {string | number | null} [recordId]
 * @property {Record<string, unknown>} [conflicts]
 */

/**
 * A journal event, or a hub notice of one: a field's value or model operations, a Discard, a record saved.
 *
 * @typedef {object} DraftEvent
 * @property {string} kind
 * @property {string} field
 * @property {number} revision
 * @property {number} [generation]
 * @property {number} [version]
 * @property {string} [id]
 * @property {any} [value]
 * @property {Record<string, string>} [labels]
 */

/** @typedef {{ label?: string, userId?: string | number } | null | undefined} Actor */

/**
 * A field's model as the session drives it: an OperationBuffer, or a value adapter (fields.js).
 *
 * @typedef {object} Adapter
 * @property {() => object | null} next the batch to send, marked as sent
 * @property {(event: any) => void} receive
 * @property {number} [version]
 * @property {() => boolean} [hasPending]
 * @property {(value: any) => void} [adopt]
 * @property {() => void} [destroy]
 */

/**
 * @typedef {{ ready: boolean, claimed?: boolean, grace?: ReturnType<typeof setTimeout>, resolve: () => void,
 *   promise: Promise<void> }} EditorWaiter
 */
/**
 * What `editorConfiguration()` hands one editor of a field, as `collaborate`: the `Collaborate` plugin follows the
 * draft only while `current` answers `true`, and calls `ready` once it does; the host calls `failed` when the editor
 * cannot be created or wired, or `release` when the field leaves the form without one. Each settles this editor's
 * wait only, and nothing once a later editor of the field or the field's removal replaced it.
 *
 * @typedef {object} EditorHandle
 * @property {() => DraftSession} getSession
 * @property {string} field
 * @property {() => boolean} current
 * @property {() => void} ready
 * @property {() => void} failed
 * @property {() => void} release
 */

/** @type {WeakMap<HTMLFormElement, DraftSession>} */
const sessions = new WeakMap();

/** What a grant signs, its first part: the draft, the user and the field schema, as base64 JSON. */
/** @param {string} grant */
const grantScope = (grant) =>
  JSON.parse(
    new TextDecoder().decode(Uint8Array.from(atob(grant.split('.')[0]), (character) => character.charCodeAt(0)))
  );
/** @param {unknown} error */
const messageOf = (error) => (error instanceof Error ? error.message : String(error));
/** @param {string} text */
const escapePattern = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const matchers = new WeakMap();
/**
 * Compiled once per grant schema: every form mutation looks each control up again.
 *
 * @param {Record<string, any>} schema the grant's field definitions, by pattern
 */
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
  /**
   * @param {HTMLFormElement} form
   * @param {DraftConfiguration} configuration
   * @param {typeof fetch} [fetcher]
   * @param {import('../hub.js').CollaborateHub | null} [hub]
   */
  constructor(form, configuration, fetcher = backgroundFetch, hub = hubFor()) {
    this.form = form;
    this.configuration = configuration;
    this.fetcher = fetcher;
    this.hub = hub;
    /** @type {Map<string, Adapter>} */
    this.adapters = new Map();
    /** @type {Map<string, DraftEvent[]>} */
    this.history = new Map();
    /** @type {Set<string>} */
    this.reported = new Set();
    // Typed Markdown source not yet applied to its editor's model: flush() applies it before sending.
    /** @type {Map<string, () => void>} */
    this.sources = new Map();
    this.revision = 0;
    this.generation = 0;
    // The highest revision a notice announced while a replay was under way (Infinity: unknown).
    this.awaited = 0;
    this.abort = new AbortController();
    /** @type {(() => void)[]} */
    this.removers = [];
    /** @type {HTMLInputElement[]} the save fields this session added to the form */
    this.added = [];
    // Settled once the draft stops: a save awaiting editors then waits no more. One rejection serves every save.
    /** @type {(value: unknown) => void} */
    let end;
    this.stopping = new Promise((resolve) => {
      end = resolve;
    }).then(() => {
      throw new Error('Collaborate draft stopped.');
    });
    this.stopping.catch(() => {});
    this.ended = () => end(undefined);
    /** @type {WeakSet<Element>} */
    this.bound = new WeakSet();
    // Set by the modules sharing the session: a received value being written to a control (fields.js and the
    // CKEditor plugin), the scan of the form's collection rows (collections.js), the conflicts the review shows
    // (conflicts.js, once the join answered).
    this.applying = false;
    /** @type {(() => void) | null} */
    this.scanCollections = null;
    /** @type {Record<string, unknown> | undefined} */
    this.conflicts = undefined;
    // Join answers it before anything reads it: start() waits for the join.
    this.state = /** @type {DraftState} */ (/** @type {unknown} */ (undefined));
    // When the last replay answered: none yet.
    this.pulled = 0;
    /** @type {Map<string, EditorWaiter>} */
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
    this.bar = new CollaborateBar(
      configuration.messages,
      () => this.discardDraft(),
      () => this.saveFromBar()
    );
    this.discard = this.bar.discard;
    form.prepend(this.bar.element);
    this.setStatus('connecting');
    this.conflictPanel = document.createElement('section');
    this.conflictPanel.className = 'collaborate-conflicts alert alert-warning';
    this.conflictPanel.hidden = true;
    this.conflictPanel.dataset.collaborateConflicts = '';
    this.bar.element.after(this.conflictPanel);
    /** @param {Event} event */
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
    /** @param {PageTransitionEvent} event */
    this.unload = (event) => (event.persisted ? this.suspend() : this.stop());
    /** @param {PageTransitionEvent} event */
    this.restore = (event) => {
      if (event.persisted) this.resume();
    };
    window.addEventListener('pagehide', this.unload);
    window.addEventListener('pageshow', this.restore);
  }

  /**
   * A timing or size the form's `config` sets, or its default.
   *
   * @param {string} name
   * @param {number} fallback
   */
  setting(name, fallback) {
    return this.configuration.config?.[name] ?? fallback;
  }

  /**
   * Shows a state; `name`, another editor's, takes the message that names who did it.
   *
   * @param {string} key
   * @param {string | null} [name]
   */
  setStatus(key, name = null) {
    this.form.dataset.collaborateState = key;
    this.bar.setState(key, this.message(key, name));
  }

  /**
   * @param {string} key
   * @param {string | null} [name]
   */
  message(key, name = null) {
    const messages = this.configuration.messages;
    if (name && messages[`${key}By`]) return messages[`${key}By`].replace('%name%', name);
    return messages[key] || key;
  }

  /**
   * The other editor who did what `actor` names, or nobody: this account's own sessions are no one else.
   *
   * @param {Actor} actor
   * @returns {string | null}
   */
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

  /**
   * What a failed action tells the editor: a lost connection waits, a refusal does not.
   *
   * @param {any} error whatever the action threw: an HttpError, an AbortError, a network failure
   */
  failed(error) {
    if (this.stopped || this.suspended || error?.name === 'AbortError') return;
    const status = error?.status;
    if (!status || status === 429 || status >= 500) this.setStatus('offline');
    else if (status === 409) {
      this.setStatus('changed');
      this.pull().catch(() => {});
    } else this.setStatus('failed');
  }

  /**
   * @param {string} action
   * @param {object} [body]
   * @param {boolean} [renewed]
   * @returns {Promise<any>} the server's JSON answer, or null for 204
   */
  async request(action, body = {}, renewed = false) {
    if (this.suspended) throw new DOMException('Collaborate session suspended.', 'AbortError');
    const signal = this.abort.signal;
    const grant = this.configuration.grant;
    // Once the server has verified this grant, its signature stands for the whole field schema.
    const short = this.acknowledged === grant;
    const response = await postJson(
      this.fetcher,
      this.configuration.url.replace('__action__', action),
      this.configuration.csrf,
      { grant: short ? grant.split('.')[1] : grant, ...body },
      { signal }
    );
    signal.throwIfAborted();
    if (short && response.status === 412) {
      if (this.acknowledged === grant) this.acknowledged = null;
      return this.request(action, body, renewed);
    }
    if (response.ok) {
      /** @type {string | null} the grant the server verified: requests send its signature alone */
      this.acknowledged = grant;
    }
    if (isDenied(response)) {
      // A grant expires after 10 h: the form's action signs another one, unless the editor lost the right to it.
      if (!response.redirected && !renewed && (await this.renewGrant(grant))) return this.request(action, body, true);
      if (!this.stopped) this.halt('denied');
      throw new Error('Collaborate permission expired.');
    }
    if (!response.ok) {
      if (response.status === 413) this.halt('full');
      throw new HttpError(`Collaborate ${action}: ${response.status}`, response.status);
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
          const status = statusOf(error);
          if (this.stopped || Date.now() >= deadline || status === null || ![429, 503].includes(status)) throw error;
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
      /** @param {SubmitEvent} event */
      this.onSubmit = async (event) => {
        if (this.submitting) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        // This handler stops EasyAdmin's own double-submit guard: a second Save meanwhile would post the form twice.
        if (this.composing || this.suspended || this.resuming || this.saving) return;
        this.saving = true;
        try {
          /** @type {unknown} */
          let failure = null;
          try {
            await this.refreshRecord();
            await this.flush();
          } catch (error) {
            failure = error;
          }
          // An editor that never came stopped the draft meanwhile: the Save posts the form as usual.
          if (this.ordinary) {
            if (!this.post(event.submitter)) console.error('Collaborate: the form could not be saved; save again.');
            return;
          }
          if (failure) throw failure;
          if (this.stopped || this.suspended || this.resuming || this.composing) return;
          // A form that can save has joined: its conflicts are known.
          if (Object.keys(/** @type {Record<string, unknown>} */ (this.conflicts)).length) {
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
          /** @param {SubmitEvent} submission */
          const posted = (submission) => {
            if (!submission.defaultPrevented) this.posted = true;
          };
          window.addEventListener('submit', posted);
          try {
            if (!this.post(event.submitter)) this.setStatus('failed');
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

  /**
   * @param {string} name
   * @param {string} value
   */
  hidden(name, value) {
    // The hidden inputs this session added, or the host's of the same name.
    let control = /** @type {HTMLInputElement | null} */ (this.form.elements.namedItem(name));
    if (!control) {
      control = document.createElement('input');
      control.type = 'hidden';
      control.name = name;
      this.form.append(control);
      this.added.push(control);
    }
    control.value = value;
  }

  // The save fields this page added: a form left to ordinary saving posts none of them.
  forgetSaveFields() {
    for (const control of this.added.splice(0)) control.remove();
  }

  /**
   * Posts the form with the button the user pressed. One gone from the form meanwhile (its actions rendered again)
   * posts nothing, the browser refusing it: another button would run another action.
   *
   * @param {HTMLElement | null} [submitter]
   * @returns {boolean} whether the form was submitted
   */
  post(submitter) {
    try {
      if (submitter) this.form.requestSubmit(submitter);
      else this.form.requestSubmit();
      return true;
    } catch (error) {
      console.error(error);
      return false;
    }
  }

  /** @param {HTMLTextAreaElement} field the rich editor's source element */
  async editorConfiguration(field) {
    try {
      await this.ready;
    } catch {
      return {};
    }
    const name = this.fieldKey(field.name);
    const state = this.ensureField(name);
    if (!state) return {};
    // A field the draft follows as text gets an editor it cannot follow (a Drupal format switched to CKEditor 5):
    // what is typed there would not be shared, and a peer's edits would be lost, so the draft stops.
    if (state.type !== 'rich') {
      console.error(
        `Collaborate: "${field.name}" is shared as text, not rich text, and the draft cannot follow it in an editor ` +
          '(a Symfony form type: list its block prefix in drafts.rich_text.block_prefixes; Drupal: its text format ' +
          'was switched to one with CKEditor 5).'
      );
      this.editorFailed();
      return {};
    }
    return { initialData: state.initial, collaborate: this.editorHandle(name) };
  }

  /**
   * Whether the draft follows a field, once joined, without taking it on: a host hiding a followed field behind an
   * editor the draft cannot follow (one on a field followed as text, another than CKEditor 5) stops the draft.
   *
   * @param {{ name: string }} field
   */
  async follows(field) {
    try {
      await this.ready;
    } catch {
      return false;
    }
    const name = this.fieldKey(field.name);
    // A field the draft follows from its definition on (a row typed in, a row added since) counts too.
    return Boolean(this.state?.fields[name] ?? this.definition(name));
  }

  /**
   * The wait for the editor of a field asked for now: the one the join set up, or a new one once an editor claimed
   * that, whoever awaited the previous one awaiting this one.
   *
   * @param {string} name
   * @returns {EditorHandle}
   */
  editorHandle(name) {
    const previous = this.editorWaiters.get(name);
    if (previous?.claimed) {
      clearTimeout(previous.grace);
      this.editorWaiters.delete(name);
    }
    const waiter = this.editorWaiter(name);
    // Whoever awaited the previous editor awaits this one.
    if (previous && previous !== waiter && !previous.ready) waiter.promise.then(previous.resolve);
    waiter.claimed = true;
    const current = () => this.editorWaiters.get(name) === waiter;
    // The editor asked for now has the whole while to come: the join's wait, taken, starts again (a wait made now
    // started with it).
    if (waiter === previous) this.awaitEditor(name, waiter);
    return {
      getSession: () => this,
      field: name,
      current,
      ready: () => {
        if (!current()) return;
        clearTimeout(waiter.grace);
        waiter.resolve();
      },
      failed: () => {
        if (current()) this.editorFailed();
      },
      // An editor gone: nothing waits for a field gone from the form; one still there awaits its next editor
      // (rebuilt, or of another format), which takes this wait in time.
      release: () => {
        if (!current() || this.stopped) return;
        if (this.fieldGone(name)) this.letGo(name);
        else this.awaitEditor(name, waiter);
      },
    };
  }

  /**
   * The wait for a field's editor, set up as the join or a host first asks for it: its editor has a while to come,
   * the form held inert meanwhile.
   *
   * @param {string} name
   * @returns {EditorWaiter}
   */
  editorWaiter(name) {
    if (!this.editorWaiters.has(name)) {
      // Its resolve and promise are set right below.
      const waiter = /** @type {EditorWaiter} */ ({ ready: false });
      const promise = /** @type {Promise<void>} */ (
        new Promise((done) => {
          waiter.resolve = () => {
            waiter.ready = true;
            done();
            this.unlockControls();
          };
        })
      );
      waiter.promise = promise;
      this.editorWaiters.set(name, waiter);
      this.awaitEditor(name, waiter);
    }
    return /** @type {EditorWaiter} */ (this.editorWaiters.get(name));
  }

  /**
   * Gives a field's editor `nextEditorGrace` to be ready: then nothing waits for a field gone from the form, and the
   * draft, which cannot follow a field still there without an editor, stops.
   *
   * @param {string} name
   * @param {EditorWaiter} waiter
   */
  awaitEditor(name, waiter) {
    clearTimeout(waiter.grace);
    if (this.stopped) return;
    waiter.grace = setTimeout(
      // Replaced, let go or ready, a wait has its time cleared: one that runs out is still the field's.
      () => {
        if (this.fieldGone(name)) this.letGo(name);
        else this.editorFailed();
      },
      this.setting('nextEditorGrace', 10_000)
    );
  }

  /** @param {string} name */
  fieldGone(name) {
    return !this.form.isConnected || !this.form.elements.namedItem(this.formName(name));
  }

  /**
   * Nothing waits for the field's editor any more: its wait is settled for whoever awaits it, and forgotten, so an
   * entry added again under its key awaits its own editor.
   *
   * @param {string} name
   */
  letGo(name) {
    const waiter = this.editorWaiters.get(name);
    if (!waiter) return;
    clearTimeout(waiter.grace);
    this.editorWaiters.delete(name);
    waiter.resolve();
  }

  // Without every editor the draft cannot be followed; keep the ordinary submission path.
  editorFailed() {
    if (this.stopped) return;
    // Its form is edited and saved as usual from now on: a Save under way posts it so.
    this.ordinary = true;
    this.setStatus('unavailable');
    this.stop();
  }

  unlockControls() {
    if (!this.initialized || this.stopped || Array.from(this.editorWaiters.values()).some((waiter) => !waiter.ready))
      return;
    this.releaseControls();
  }

  // A stopped draft awaits no editor: the time it gave each to come runs out on nothing, and a save awaiting them
  // waits no more.
  forgetGraces() {
    for (const waiter of this.editorWaiters.values()) clearTimeout(waiter.grace);
    this.ended();
  }

  releaseControls() {
    for (const element of this.startupControls) element.removeAttribute('inert');
    this.startupControls = [];
  }

  /**
   * Runs what a peer's edit does to the page (a control set, its events dispatched): the field's listeners tell it
   * from the editor's own edits.
   *
   * @param {() => void} work
   */
  withApplying(work) {
    this.applying = true;
    try {
      work();
    } finally {
      this.applying = false;
    }
  }

  /** @param {() => void} remove what undoes a binding of the form, once the session stops */
  addRemover(remove) {
    this.removers.push(remove);
  }

  /**
   * A field no longer on the page (its collection entry removed): its adapter ends, and nothing waits for its editor.
   *
   * @param {string} name
   */
  detachField(name) {
    this.adapters.get(name)?.destroy?.();
    this.adapters.delete(name);
    this.letGo(name);
  }

  /**
   * @param {string} name
   * @param {Adapter} buffer
   */
  register(name, buffer) {
    const state = this.state.fields[name];
    if (
      Number.isInteger(buffer.version) &&
      state.initialVersion !== undefined &&
      buffer.version !== state.initialVersion
    ) {
      this.incompatible('version', name, `editor ${buffer.version}, draft ${state.initialVersion}`);
      throw new Error('Collaborate editor initialization differs from the stored draft.');
    }
    this.adapters.set(name, buffer);
    try {
      for (const event of this.history.get(name) || []) buffer.receive(event);
    } catch (error) {
      this.incompatible('replay', name, messageOf(error));
      throw error;
    }
    // A later editor of the field may have registered since: its adapter stays.
    return () => {
      if (this.adapters.get(name) === buffer) this.adapters.delete(name);
    };
  }

  /**
   * What a field registered later replays: a value replaces the one before it, model operations accumulate.
   *
   * @param {DraftEvent} event
   */
  remember(event) {
    if (event.kind === 'discard') return;
    const events = this.history.get(event.field);
    if (event.kind === 'value' || !events) this.history.set(event.field, [event]);
    else events.push(event);
  }

  // Save stays paused, but the page must remain readable so unsent content can be copied.
  /**
   * @param {string} cause
   * @param {string} field
   * @param {string} detail
   */
  incompatible(cause, field, detail) {
    this.halt('incompatible');
    this.report(cause, field, detail);
  }

  /**
   * Tells the server why this page stopped following the draft as incompatible, once per cause and field: nothing
   * else records a halt the page decides itself, and the bar only asks the editor to reload.
   *
   * @param {string} cause
   * @param {string | null} [field]
   * @param {unknown} [detail]
   * @param {number | null} [status]
   */
  report(cause, field = null, detail = null, status = null) {
    const key = `${cause}:${field}`;
    if (this.reported.has(key)) return;
    this.reported.add(key);
    console.error(`Collaborate: this page stopped following the shared draft (${cause}).`, { field, status, detail });
    const grant = this.configuration.grant;
    const body = {
      grant: this.acknowledged === grant ? grant.split('.')[1] : grant,
      cause,
      field,
      status,
      detail: detail == null ? null : String(detail),
    };
    const url = this.configuration.url.replace('__action__', 'report');
    Promise.resolve()
      .then(() => postJson(this.fetcher, url, this.configuration.csrf, body))
      // A report is best effort: the page has already stopped, and nothing waits on it.
      .catch(() => {});
  }

  /**
   * Stops following the draft: no more requests, stream or timers. The form keeps its collaborative Save guard, and
   * its controls are released: an editor still awaited no longer holds them inert.
   *
   * @param {string} [status]
   * @param {boolean} [release] false for a page about to reload, where whatever is typed meanwhile would be lost
   */
  halt(status, release = true) {
    if (release) this.releaseControls();
    this.stopped = true;
    this.forgetGraces();
    if (status) this.setStatus(status);
    this.disconnect();
    clearInterval(this.poll);
    clearTimeout(this.timer);
    clearTimeout(this.recordTimer);
    this.timer = null;
    this.recordTimer = null;
  }

  /**
   * @param {string} name
   * @param {any} editor the CKEditor instance, its `sourceElement` the textarea
   * @param {(source: string) => void} applySource
   */
  registerMode(name, editor, applySource) {
    const field = editor.sourceElement;
    if (!field) return () => {};
    const radios = Array.from(
      /** @type {NodeListOf<HTMLInputElement>} */ (this.form.querySelectorAll('input[type="radio"]'))
    ).filter((radio) => radio.name === `${field.id}_view`);
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
    /** @param {Event} event */
    const modeChanged = (event) => {
      if (remote || !(/** @type {HTMLInputElement} */ (event.target).checked)) return;
      adapter.send(/** @type {HTMLInputElement} */ (event.target).value);
    };
    // Parsing the whole document on every keystroke lags a long field: typing settles first. Blur (a mode
    // switch blurs the source) and flush() apply what is still pending.
    /** @type {ReturnType<typeof setTimeout> | null} */
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
        clearTimeout(/** @type {number | undefined} */ (sourceTimer));
        sourceTimer = setTimeout(settle, 100);
      }
    };
    this.sources.set(name, settle);
    for (const radio of radios) radio.addEventListener('change', modeChanged);
    field.addEventListener('input', sourceChanged);
    field.addEventListener('blur', settle);
    // A later editor of the field may have registered its own since: they stay.
    return () => {
      clearTimeout(/** @type {number | undefined} */ (sourceTimer));
      if (this.sources.get(name) === settle) this.sources.delete(name);
      editor.model.document.off('change:data', uploadChanged);
      if (this.adapters.get(modeName) === adapter) this.adapters.delete(modeName);
      for (const radio of radios) radio.removeEventListener('change', modeChanged);
      field.removeEventListener('input', sourceChanged);
      field.removeEventListener('blur', settle);
    };
  }

  /**
   * Applies a rich field's source typing that still waits for its pause.
   *
   * @param {string} name
   */
  settleSource(name) {
    this.sources.get(name)?.();
  }

  initializeControls() {
    // A row that is no entry yet, or one its host is removing, follows no draft.
    /** @param {Element} element */
    const outside = (element) => element.closest('[data-collaborate-pending], [data-collaborate-removed]');
    for (const control of /** @type {Iterable<HTMLInputElement>} */ (this.form.elements)) {
      if (control.name && !outside(control) && !this.state.fields[this.fieldKey(control.name)])
        this.ensureField(this.fieldKey(control.name));
    }
    for (const [name, state] of Object.entries(this.state.fields)) {
      const control = this.form.elements.namedItem(this.formName(name));
      const element = control instanceof RadioNodeList ? control[0] : control;
      if (!element || !['text', 'value'].includes(state.type) || this.bound.has(element) || outside(element)) continue;
      this.bound.add(element);
      if (state.type === 'text' && control instanceof HTMLElement) {
        initializeText(this, /** @type {HTMLInputElement | HTMLTextAreaElement} */ (control), name, state);
      } else {
        initializeValue(
          this,
          /** @type {RadioNodeList | import('./fields.js').SingleControl} */ (control),
          name,
          state
        );
      }
    }
  }

  /**
   * @param {string} name
   * @returns {DraftField | null}
   */
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

  /**
   * @param {string} name
   * @param {Record<string, any>} [schema]
   */
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

  /**
   * @param {DraftConfiguration} configuration
   * @param {boolean} [pull]
   */
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
        this.report('schema', name, `${state.schema} → ${definition.schema}`);
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

  /**
   * Renews a refused grant once for every request it failed: whether they may go again with the new one.
   *
   * @param {string} grant
   */
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
        /** @type {Promise<boolean> | null} */
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
      throw new HttpError(`Collaborate record refresh: ${response.status}`, response.status);
    }
    // A sign-in page, or a form no longer shared, reads as no configuration.
    const refreshed = isAccepted(response) ? await response.json().catch(() => null) : null;
    if (!refreshed?.configuration) {
      this.halt('denied');
      throw new Error('Collaborate record is unavailable.');
    }
    for (const [name, value] of Object.entries(refreshed.tokens || {})) {
      const current = this.form.elements.namedItem(name);
      if (current) /** @type {HTMLInputElement} */ (current).value = value;
    }
    await this.refreshConfiguration(refreshed.configuration, pull);
  }

  /**
   * @param {string} name
   * @returns {string}
   */
  formName(name) {
    return this.state.fields[name]?.formName || name;
  }

  /**
   * @param {string} name
   * @returns {string}
   */
  fieldKey(name) {
    if (this.schema[name]) return name;
    const { keys, fieldKeys } = matcher(this.schema);
    if (!fieldKeys.has(name)) {
      const match = keys.find(
        /** @param {[string, string, RegExp]} key the logical key, its form name, and the pattern of that name */
        ([, , expression]) => expression.test(name)
      );
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
          this.report('refused', error.field ?? null, error.message, error.status);
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
        const status = statusOf(error);
        if (status === 429 || status === 503) this.recordChanged();
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
  /** @param {{ key: string, name?: string } | null} [notice] */
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
   *
   * @param {any} [discard]
   * @param {boolean} [rebased]
   */
  superseded(discard = null, rebased = false) {
    const name = this.peerName(discard);
    // A draft that had nothing unsaved holds what this page shows. A compaction keeps only saved content, so
    // a page that sent every edit loses none by reloading, even one that has not heard of the save yet.
    if (rebased ? this.sent() : this.clean()) {
      this.halt('connecting', false);
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
      if (statusOf(error) === 409) this.superseded();
      throw error;
    }
    this.pulled = Date.now();
    if (this.composing) return;
    if (state.unchanged) {
      // Nobody wrote to the draft since, but the answer proves the connection is back.
      if (['offline', 'changed'].includes(/** @type {string} */ (this.form.dataset.collaborateState))) {
        this.settled();
        if (this.configuration === configuration)
          this.renderConflicts(/** @type {Record<string, unknown>} */ (this.conflicts));
      }
      return;
    }
    if (state.generation !== this.generation) this.superseded(null, state.rebasedFrom.includes(this.generation));
    if (state.revision < this.revision) return;
    for (const [name, field] of Object.entries(state.fields)) {
      const definition = this.definition(name);
      if (field.schema && definition?.schema && field.schema !== definition.schema) {
        this.halt('incompatible');
        this.report('schema', name, `${definition.schema} → ${field.schema}`);
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
        this.report('replay', event.field, messageOf(error));
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

  /** @param {Record<string, unknown>} conflicts */
  renderConflicts(conflicts) {
    renderConflicts(this, conflicts);
  }

  /**
   * Asks before an action that every editor of the record sees: `kind` names its messages.
   *
   * @param {string} kind
   */
  confirm(kind) {
    const messages = this.configuration.messages;
    return confirmAction({
      title: messages[`${kind}Title`],
      body: messages[`${kind}Body`],
      confirm: messages[`${kind}Accept`],
      cancel: messages.cancel,
    });
  }

  /** @param {boolean} keep */
  async reconcile(keep) {
    if (!keep && !(await this.confirm('confirmSaved'))) return;
    this.bar.setBusy(true);
    try {
      if (keep) {
        // A field without draft edits has nothing to keep: it takes the saved record value,
        // as an ordinary edit, so peers converge and Save does not revert the external change.
        for (const [name, value] of Object.entries(/** @type {Record<string, unknown>} */ (this.conflicts))) {
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
          this.stopping,
          new Promise((_resolve, reject) => {
            // At least as long as an editor is given to come.
            const wait = Math.max(15_000, this.setting('nextEditorGrace', 10_000));
            editorTimeout = setTimeout(() => reject(new Error('Collaborate editors are not ready.')), wait);
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
          // A refusal names its field to the report it may end in.
          if (error instanceof HttpError) error.field = outgoing.field;
          if (statusOf(error) !== 409) throw error;
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

  /** Follows the draft's topic on the page's one hub stream; without it, the page replays every 5 s (`replayDisconnected`). */
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

  /** @param {{ kind: string, generation?: number, revision?: number }} event a notice the hub delivered */
  received(event) {
    if (event.kind === 'record') {
      this.recordChanged();
      return;
    }
    // An append of this generation names its revision: one this page has read, its own echo included,
    // needs no replay. Any other notice may change anything.
    const revision =
      event.kind === 'append' && event.generation === this.generation && Number.isInteger(event.revision)
        ? /** @type {number} */ (event.revision)
        : Infinity;
    if (revision <= this.revision) return;
    this.stale = true;
    this.awaited = Math.max(this.awaited, revision);
    // A replay under way reads again once done, unless it reached that revision.
    if (!this.pulling) this.schedule();
  }

  /**
   * The submit button the bar's Save stands for: one the host marks `data-collaborate-submitter`, else the first
   * the bridge names by its value (EasyAdmin's `saveAndContinue`, which keeps the editor on the record, then its
   * `saveAndReturn`: never "save and add another", which would open an empty form), else the form's first.
   * Buttons outside the form that name it (`form="…"`, as EasyAdmin's page actions do) count.
   */
  submitter() {
    const buttons = /** @type {HTMLButtonElement[]} */ (Array.from(this.form.elements)).filter(
      (control) => control.type === 'submit' && !control.disabled
    );
    return (
      buttons.find((button) => button.hasAttribute('data-collaborate-submitter')) ??
      bridge()
        .submitters.map((value) => buttons.find((button) => button.value === value))
        .find(Boolean) ??
      buttons[0] ??
      null
    );
  }

  /** The bar's Save submits the form as its own button would, through the same draft-aware submit handler. */
  saveFromBar() {
    this.post(this.submitter());
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

  /** @param {string | number} id */
  recordCreated(id) {
    this.halt('created');
    const name = this.peerName(this.state.savedBy);
    if (name) this.setStatus('created', name);
    this.bar.openRecord(this.configuration.editUrl.replace('__record__', encodeURIComponent(id)));
  }

  startTimers() {
    if (this.stopped || this.suspended) return;
    this.poll = setInterval(
      () => {
        // A host that replaced its content took the form away: the session goes with it, its timers,
        // listeners and hub membership included.
        if (!this.form.isConnected) {
          this.stop();
          // Put back, the form starts a session of its own. A session stopped for any other reason (a Join
          // refused, say) keeps its form: each field asking for it must not try again.
          if (sessions.get(this.form) === this) sessions.delete(this.form);
          return;
        }
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
    this.forgetGraces();
    this.forgetSaveFields();
    this.suspend();
    this.form.removeEventListener('submit', /** @type {(event: SubmitEvent) => Promise<void>} */ (this.onSubmit), true);
    this.form.removeEventListener('submit', this.earlySubmit, true);
    this.form.removeEventListener('compositionstart', this.compositionStart);
    this.form.removeEventListener('compositionend', this.compositionEnd);
    window.removeEventListener('pagehide', this.unload);
    window.removeEventListener('pageshow', this.restore);
    for (const remove of this.removers) remove();
  }
}

/**
 * @param {{ form: HTMLFormElement | null }} field a control of the form
 * @returns {DraftSession | null | undefined}
 */
export function sessionFor(field) {
  const form = field.form;
  if (!form?.dataset.collaborate) return null;
  if (!sessions.has(form)) sessions.set(form, new DraftSession(form, JSON.parse(form.dataset.collaborate)));
  return sessions.get(form);
}

export function initializeCollaboration() {
  for (const form of document.querySelectorAll('form[data-collaborate]')) {
    const controls = /** @type {HTMLInputElement[]} */ (Array.from(/** @type {HTMLFormElement} */ (form).elements));
    const field = controls.find((element) => element.name && element.type !== 'hidden');
    if (field) sessionFor(field)?.ready.catch(() => {});
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeCollaboration);
else initializeCollaboration();
