import labels from '~labels/docx_editor.messages';
import { notifySaved, notifySaveFailed } from '@webconsulting/docx-editor/notify.js';
import { mountDocxEditor } from './docx-editor-mount.jsx';
import { heartbeatSession, joinSession, leaveSession } from './docx-editor-api.js';

const HEARTBEAT_INTERVAL = 15000;

/**
 * <typo3-docx-editor>: hosts the React editor in light DOM (so the bundled
 * eigenpal styles apply), exposes save()/saveAsToFolder() and the `dirty`
 * state to the DocHeader glue (toolbar.js) and keeps the presence session
 * alive.
 *
 * Attributes: file-identifier, file-name, revision, can-write ("1"/"0"),
 * editor-locale. Page context comes from #docx-editor-app[data-*], labels
 * from the docx_editor.messages domain.
 *
 * Events (bubbling): `docx-editor:change` whenever `dirty` flips.
 */
export class Typo3DocxEditorElement extends HTMLElement {
  #api = null;

  #unmount = null;

  #sessionUid = 0;

  #heartbeatTimer = 0;

  #dirty = false;

  #teardownCollab = () => {
    window.removeEventListener('pagehide', this.#teardownCollab);
    window.clearInterval(this.#heartbeatTimer);
    if (this.#sessionUid) {
      leaveSession(this.fileIdentifier, this.#sessionUid).catch(() => {});
      this.#sessionUid = 0;
    }
  };

  get fileIdentifier() {
    return this.getAttribute('file-identifier') ?? '';
  }

  get fileName() {
    return this.getAttribute('file-name') ?? 'document.docx';
  }

  get canWrite() {
    return this.getAttribute('can-write') === '1';
  }

  /** Whether the document has changes that are not saved yet. */
  get dirty() {
    return this.#dirty;
  }

  async save() {
    if (!this.#api) {
      throw new Error(labels.get('editor.notReady'));
    }
    await this.#api.save();
  }

  async saveAsToFolder(folderIdentifier, fileName) {
    if (!this.#api) {
      throw new Error(labels.get('editor.notReady'));
    }
    return this.#api.saveAs(folderIdentifier, fileName);
  }

  connectedCallback() {
    const app = document.getElementById('docx-editor-app');
    const mount = document.createElement('div');
    mount.className = 'mount';
    this.replaceChildren(mount);

    this.#unmount = mountDocxEditor(mount, {
      fileIdentifier: this.fileIdentifier,
      fileName: this.fileName,
      canWrite: this.canWrite,
      initialRevision: Number(this.getAttribute('revision') ?? 0),
      editorLocale: this.getAttribute('editor-locale') ?? 'en',
      onApi: (api) => {
        this.#api = api;
      },
      onChange: () => this.#setDirty(true),
      onSaved: () => {
        this.#setDirty(false);
        notifySaved(app?.dataset.filePath ?? this.fileName);
      },
      onError: (message) => notifySaveFailed(message),
      onConflict: () => this.#showConflict(),
    });
    this.#startCollab();
  }

  disconnectedCallback() {
    this.#teardownCollab();
    this.#unmount?.();
    this.#unmount = null;
    this.#api = null;
  }

  #setDirty(dirty) {
    if (this.#dirty === dirty) {
      return;
    }
    this.#dirty = dirty;
    this.dispatchEvent(new CustomEvent('docx-editor:change', { bubbles: true, detail: { dirty } }));
  }

  async #startCollab() {
    window.addEventListener('pagehide', this.#teardownCollab);
    try {
      const joined = await joinSession(this.fileIdentifier);
      this.#sessionUid = joined.sessionUid;
      this.#renderPresence(joined.participants);
    } catch {
      return; // presence is optional
    }
    this.#heartbeatTimer = window.setInterval(async () => {
      try {
        const beat = await heartbeatSession(this.fileIdentifier, this.#sessionUid);
        this.#renderPresence(beat.participants);
      } catch {
        // ignore heartbeat errors
      }
    }, HEARTBEAT_INTERVAL);
  }

  /** Another editor stored a newer revision: offer to reload. */
  #showConflict() {
    const callout = document.querySelector('[data-docx-conflict]');
    if (!callout || !callout.hidden) {
      return;
    }
    callout.hidden = false;
    callout.querySelector('[data-docx-reload]')?.addEventListener('click', () => window.location.reload(), { once: true });
  }

  #renderPresence(participants = []) {
    const badge = document.querySelector('[data-docx-presence]');
    const label = badge?.querySelector('[data-docx-presence-label]');
    if (!badge || !label) {
      return;
    }
    badge.hidden = participants.length === 0;
    label.textContent = labels.get('editor.collaborators', { count: participants.length });
    badge.title = participants.map((participant) => participant.userName).join(', ');
  }
}

customElements.define('typo3-docx-editor', Typo3DocxEditorElement);
