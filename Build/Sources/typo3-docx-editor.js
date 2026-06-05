import { LitElement, css, html } from 'lit';
import { notifyDocxEditorStatus } from '@webconsulting/docx-editor/notify.js';
import { mountDocxEditor } from './docx-editor-mount.jsx';
import { readAppLabels, readHeadingLabels } from './docx-labels.js';
import { heartbeatSession, joinSession, leaveSession } from './docx-editor-api.js';

/**
 * Lit glue for TYPO3: hosts the React-based eigenpal/docx-editor bundle.
 */
export class Typo3DocxEditorElement extends LitElement {
  /**
   * Light DOM so eigenpal/docx-editor global styles (.ep-root, toolbar, icons) apply.
   * Shadow DOM would block the Vite CSS bundle loaded by the TYPO3 backend.
   */
  createRenderRoot() {
    return this;
  }

  static properties = {
    fileIdentifier: { type: String, attribute: 'file-identifier' },
    fileName: { type: String, attribute: 'file-name' },
    fileHash: { type: String, attribute: 'file-hash' },
    revision: { type: Number },
    canWrite: {
      type: Boolean,
      attribute: 'can-write',
      converter: {
        fromAttribute: (value) => value === '1' || value === true,
        toAttribute: (value) => (value ? '1' : '0'),
      },
    },
  };

  static styles = css`
    :host {
      display: block;
      min-height: 70vh;
      width: 100%;
    }
    .mount {
      min-height: 70vh;
      width: 100%;
    }
  `;

  constructor() {
    super();
    this.unmountEditor = null;
    this.sessionUid = 0;
    this.heartbeatTimer = null;
    this.app = null;
    this.labels = {};
    this.headingLabels = {};
    this.docxEditorApi = null;
    this.remoteReloadBound = false;
  }

  async save() {
    if (!this.docxEditorApi?.save) {
      throw new Error('Editor is not ready yet.');
    }
    await this.docxEditorApi.save();
  }

  async saveAsToFolder(folderIdentifier, fileName) {
    return this.docxEditorApi?.saveAs?.(folderIdentifier, fileName);
  }

  getDocumentFileName() {
    return this.docxEditorApi?.getFileName?.() ?? '';
  }

  connectedCallback() {
    super.connectedCallback();
    this.app = document.getElementById('docx-editor-app');
    this.labels = readAppLabels(this.app);
    this.headingLabels = readHeadingLabels(this.app);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.teardownCollab();
    this.unmountEditor?.();
    this.unmountEditor = null;
  }

  render() {
    return html`<div class="mount" data-docx-mount></div>`;
  }

  firstUpdated() {
    const host = this.renderRoot.querySelector('[data-docx-mount]');
    this.unmountEditor = mountDocxEditor(
      host,
      {
        fileIdentifier: this.fileIdentifier,
        fileName: this.fileName,
        canWrite: this.canWrite,
        initialRevision: this.revision,
        loadingLabel: this.labels.labelLoading || 'Loading document…',
        editorLocale: this.labels.editorLocale || 'en',
        headingLabels: this.headingLabels,
        onStatus: (state, detail) => this.handleStatus(state, detail),
        onRemoteRevision: (revision, _hash, conflict) =>
          this.handleRemoteRevision(revision, conflict),
      },
      this,
    );
    this.startCollab();
  }

  async startCollab() {
    try {
      const joined = await joinSession(this.fileIdentifier);
      if (joined?.ok) {
        this.sessionUid = joined.sessionUid;
        this.renderPresence(joined.participants);
      }
    } catch {
      // presence is optional
    }
    this.heartbeatTimer = window.setInterval(async () => {
      if (!this.sessionUid) {
        return;
      }
      try {
        const beat = await heartbeatSession(this.fileIdentifier, this.sessionUid);
        if (beat?.ok) {
          this.renderPresence(beat.participants);
        }
      } catch {
        // ignore heartbeat errors
      }
    }, 15000);
    window.addEventListener('beforeunload', this.teardownCollabBound);
  }

  teardownCollabBound = () => {
    this.teardownCollab();
  };

  teardownCollab() {
    window.removeEventListener('beforeunload', this.teardownCollabBound);
    if (this.heartbeatTimer) {
      window.clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
    if (this.sessionUid) {
      leaveSession(this.fileIdentifier, this.sessionUid);
      this.sessionUid = 0;
    }
  }

  handleStatus(state, detail) {
    if (state === 'ready' || state === 'saving') {
      return;
    }
    notifyDocxEditorStatus(
      this.app,
      state,
      typeof detail === 'string' ? detail : '',
    );
  }

  handleRemoteRevision(revision, conflict) {
    if (!conflict) {
      this.revision = revision;
      return;
    }
    const banner = document.querySelector('[data-docx-remote-banner]');
    if (!banner) {
      return;
    }
    banner.classList.remove('d-none');
    const reloadButton = banner.querySelector('[data-docx-remote-reload]');
    if (reloadButton && !this.remoteReloadBound) {
      this.remoteReloadBound = true;
      reloadButton.addEventListener('click', () => window.location.reload());
    }
  }

  renderPresence(participants = []) {
    const target = document.querySelector('[data-docx-presence]');
    if (!target) {
      return;
    }
    const count = participants.length;
    if (count === 0) {
      target.textContent = '';
      return;
    }
    if (count === 1 && this.labels.labelCollaboratorsOne) {
      target.textContent = this.labels.labelCollaboratorsOne;
      return;
    }
    const template =
      this.labels.labelCollaboratorsOther || '{count} editors online';
    target.textContent = template.replace(/\{count\}/g, String(count));
  }
}

customElements.define('typo3-docx-editor', Typo3DocxEditorElement);
