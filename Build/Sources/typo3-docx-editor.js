import { LitElement, css, html } from 'lit';
import { mountDocxEditor } from './docx-editor-mount.jsx';
import { heartbeatSession, joinSession, leaveSession } from './docx-editor-api.js';

/**
 * Lit glue for TYPO3: hosts the React-based eigenpal/docx-editor bundle.
 */
export class Typo3DocxEditorElement extends LitElement {
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
    this.labels = {};
  }

  connectedCallback() {
    super.connectedCallback();
    const app = document.getElementById('docx-editor-app');
    if (app?.dataset) {
      this.labels = { ...app.dataset };
    }
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
    this.unmountEditor = mountDocxEditor(host, {
      fileIdentifier: this.fileIdentifier,
      fileName: this.fileName,
      canWrite: this.canWrite,
      initialRevision: this.revision,
      onStatus: (state, detail) => this.handleStatus(state, detail),
      onRemoteRevision: (revision, _hash, conflict) =>
        this.handleRemoteRevision(revision, conflict),
    });
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
    const target = document.querySelector('[data-docx-status]');
    if (!target) {
      return;
    }
    const map = {
      saving: this.labels.labelSaving,
      saved: this.labels.labelSaved,
      error: `${this.labels.labelSaveFailed}: ${detail || ''}`,
      ready: '',
    };
    target.textContent = map[state] || '';
  }

  handleRemoteRevision(revision, conflict) {
    if (!conflict) {
      this.revision = revision;
      return;
    }
    let banner = document.querySelector('[data-docx-remote-banner]');
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'docx-editor-remote-banner';
      banner.dataset.docxRemoteBanner = '';
      const app = document.getElementById('docx-editor-app');
      app?.prepend(banner);
    }
    banner.innerHTML = '';
    const text = document.createElement('span');
    text.textContent = this.labels.labelRemoteUpdate || 'Document updated remotely.';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-warning';
    button.textContent = this.labels.labelReload || 'Reload';
    button.addEventListener('click', () => window.location.reload());
    banner.append(text, button);
  }

  renderPresence(participants = []) {
    const target = document.querySelector('[data-docx-presence]');
    if (!target) {
      return;
    }
    const count = participants.length;
    const template = this.labels.labelCollaborators || '{count} editors online';
    target.textContent = template.replace('{count}', String(count));
  }
}

customElements.define('typo3-docx-editor', Typo3DocxEditorElement);
