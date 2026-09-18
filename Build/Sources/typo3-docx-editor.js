import { notifySaved, notifySaveFailed } from '@webconsulting/docx-editor/notify.js';
import { mountDocxEditor } from './docx-editor-mount.jsx';
import { heartbeatSession, joinSession, leaveSession } from './docx-editor-api.js';
import { formatIcu } from './docx-icu-format.js';

const HEARTBEAT_INTERVAL = 15000;

/**
 * <typo3-docx-editor>: hosts the React editor in light DOM (so the bundled
 * eigenpal styles apply), exposes save()/saveAs() to the docheader toolbar
 * and keeps the presence session alive.
 *
 * Attributes: file-identifier, file-name, revision, can-write ("1"/"0"),
 * editor-locale. Labels come from #docx-editor-app[data-labels].
 */
export class Typo3DocxEditorElement extends HTMLElement {
  #api = null;

  #unmount = null;

  #sessionUid = 0;

  #heartbeatTimer = 0;

  #labels = {};

  #teardownCollab = () => {
    window.removeEventListener('beforeunload', this.#teardownCollab);
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

  async save() {
    if (!this.#api) {
      throw new Error('Editor is not ready yet.');
    }
    await this.#api.save();
  }

  async saveAsToFolder(folderIdentifier, fileName) {
    return this.#api?.saveAs(folderIdentifier, fileName);
  }

  connectedCallback() {
    const app = document.getElementById('docx-editor-app');
    this.#labels = JSON.parse(app?.dataset.labels ?? '{}');
    const mount = document.createElement('div');
    mount.className = 'mount';
    this.replaceChildren(mount);

    this.#unmount = mountDocxEditor(mount, {
      fileIdentifier: this.fileIdentifier,
      fileName: this.fileName,
      canWrite: this.canWrite,
      initialRevision: Number(this.getAttribute('revision') ?? 0),
      editorLocale: this.getAttribute('editor-locale') ?? 'en',
      loadingLabel: this.#labels.loading ?? 'Loading document…',
      headingLabels: this.#labels.headings ?? {},
      onApi: (api) => {
        this.#api = api;
      },
      onSaved: () => notifySaved(this.#labels),
      onError: (message) => notifySaveFailed(this.#labels, message),
      onConflict: () => this.#showConflictBanner(),
    });
    this.#startCollab();
  }

  disconnectedCallback() {
    this.#teardownCollab();
    this.#unmount?.();
    this.#unmount = null;
    this.#api = null;
  }

  async #startCollab() {
    window.addEventListener('beforeunload', this.#teardownCollab);
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

  #showConflictBanner() {
    const banner = document.querySelector('[data-docx-remote-banner]');
    if (!banner || !banner.classList.contains('d-none')) {
      return;
    }
    banner.classList.remove('d-none');
    banner
      .querySelector('[data-docx-remote-reload]')
      ?.addEventListener('click', () => window.location.reload(), { once: true });
  }

  #renderPresence(participants = []) {
    const target = document.querySelector('[data-docx-presence]');
    if (!target) {
      return;
    }
    const count = participants.length;
    const template =
      this.#labels.collaborators || '{count, plural, one {1 editor online} other {# editors online}}';
    target.textContent = count === 0 ? '' : formatIcu(template, { count }, this.getAttribute('editor-locale') ?? 'en');
  }
}

customElements.define('typo3-docx-editor', Typo3DocxEditorElement);
