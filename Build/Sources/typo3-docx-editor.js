import labels from '~labels/docx_editor.messages';
import { notifySaved, notifySaveFailed } from '@webconsulting/docx-editor/notify.js';
import {
  decodeBase64ToArrayBuffer,
  fetchRevision,
  heartbeatSession,
  joinSession,
  leaveSession,
  loadDocument,
  saveDocument,
  saveDocumentAs,
} from './docx-editor-api.js';
import './webcon-docx-editor.js';

const HEARTBEAT_INTERVAL = 15000;
const REVISION_POLL_INTERVAL = 5000;

/**
 * <typo3-docx-editor>: the backend module's editor. Wraps <webcon-docx-editor>
 * and adds what TYPO3 needs around it: loading and saving through the FAL
 * AJAX routes, "Save as…" into a folder, the presence badge and the
 * newer-version warning. The DocHeader glue (toolbar.js) drives it through
 * save(), saveAsToFolder() and `dirty`.
 *
 * Attributes
 *   file-identifier, file-name, revision   the FAL file and its revision
 *   can-write                              "1" to allow saving
 *   editor-locale                          backend language ("de", "en")
 *   content-controls                       "show" (see <webcon-docx-editor>)
 *   load-url                               GET endpoint instead of the FAL load
 *                                          route; answers {ok, data (base64), revision}
 *   save-url                               POST endpoint instead of the FAL save
 *                                          route; receives {file, revision, data (base64)},
 *                                          answers {ok, revision}
 *
 * Events (bubbling): `docx-editor:change` {dirty} from the inner editor,
 * `docx-editor:saved` {revision} after a successful save.
 */
export class Typo3DocxEditorElement extends HTMLElement {
  #editor = null;

  #revision = 0;

  #saving = null;

  #sessionUid = 0;

  #heartbeatTimer = 0;

  #pollTimer = 0;

  #teardownCollab = () => {
    window.removeEventListener('pagehide', this.#teardownCollab);
    window.clearInterval(this.#heartbeatTimer);
    window.clearInterval(this.#pollTimer);
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
    return this.#editor?.dirty === true;
  }

  /** The inner <webcon-docx-editor> (load(), serialize(), editor, …). */
  get editorElement() {
    return this.#editor;
  }

  connectedCallback() {
    if (this.#editor !== null) {
      return;
    }
    this.#revision = Number(this.getAttribute('revision') ?? 0);
    const editor = document.createElement('webcon-docx-editor');
    editor.className = 'docx-editor-module__editor';
    editor.setAttribute('locale', this.getAttribute('editor-locale') || 'en');
    if (!this.canWrite) {
      editor.setAttribute('readonly', '');
    }
    if (this.getAttribute('content-controls') === 'show') {
      editor.setAttribute('content-controls', 'show');
    }
    editor.labels = {
      normal: labels.get('editor.styles.normal'),
      heading1: labels.get('editor.headings.h1.title'),
      heading2: labels.get('editor.headings.h2.title'),
      heading3: labels.get('editor.headings.h3.title'),
      heading4: labels.get('editor.headings.h4.title'),
      heading1Short: labels.get('editor.headings.h1'),
      heading2Short: labels.get('editor.headings.h2'),
      heading3Short: labels.get('editor.headings.h3'),
      heading4Short: labels.get('editor.headings.h4'),
      headingsGroup: labels.get('editor.headings.group'),
    };
    editor.addEventListener('docx-editor:save-request', () => {
      if (this.canWrite) {
        this.save().catch(() => {});
      }
    });
    editor.addEventListener('docx-editor:error', (event) => notifySaveFailed(event.detail.message));
    this.replaceChildren(editor);
    this.#editor = editor;
    this.#open();
    if (this.fileIdentifier !== '') {
      this.#startCollab();
    }
  }

  disconnectedCallback() {
    this.#teardownCollab();
    this.#editor?.remove();
    this.#editor = null;
  }

  /** Saves to the FAL file (or `save-url`); resolves once stored. */
  async save() {
    if (!this.canWrite) {
      return null;
    }
    if (this.#saving) {
      return this.#saving;
    }
    this.#saving = this.#persist(async (bytes) => {
      const result = await saveDocument(this.fileIdentifier, this.#revision, bytes, this.getAttribute('save-url'));
      this.#revision = result.revision ?? this.#revision;
      return result;
    }).finally(() => {
      this.#saving = null;
    });
    return this.#saving;
  }

  /** Stores the document as a new file in a folder; resolves to {file, …}. */
  async saveAsToFolder(folderIdentifier, fileName) {
    return this.#persist((bytes) => saveDocumentAs(folderIdentifier, fileName || this.fileName, bytes), false);
  }

  async #open() {
    try {
      const payload = await loadDocument(this.fileIdentifier, this.getAttribute('load-url'));
      this.#revision = payload.revision ?? this.#revision;
      await this.#editor.load(decodeBase64ToArrayBuffer(payload.data));
    } catch (error) {
      notifySaveFailed(error?.message || String(error));
    }
  }

  /** Serializes, hands the bytes to `store`, and marks the stored revision clean. */
  async #persist(store, markClean = true) {
    const editor = this.#editor;
    if (!editor?.editor) {
      throw new Error(labels.get('editor.notReady'));
    }
    const revision = editor.revision;
    try {
      const bytes = await editor.serialize();
      const result = await store(bytes);
      if (markClean) {
        editor.markClean(revision);
        notifySaved(document.getElementById('docx-editor-app')?.dataset.filePath ?? this.fileName);
        this.dispatchEvent(new CustomEvent('docx-editor:saved', { bubbles: true, detail: { revision: this.#revision } }));
      }
      return result;
    } catch (error) {
      notifySaveFailed(error?.message || String(error));
      if (error?.httpStatus === 409) {
        this.#showConflict();
      }
      throw error;
    }
  }

  async #startCollab() {
    window.addEventListener('pagehide', this.#teardownCollab);
    this.#pollTimer = window.setInterval(async () => {
      try {
        const state = await fetchRevision(this.fileIdentifier);
        if (state.revision > this.#revision) {
          this.#showConflict();
        }
      } catch {
        // polling is best effort
      }
    }, REVISION_POLL_INTERVAL);
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

if (!customElements.get('typo3-docx-editor')) {
  customElements.define('typo3-docx-editor', Typo3DocxEditorElement);
}
