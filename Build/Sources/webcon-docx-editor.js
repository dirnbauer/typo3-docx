import { dropUnusedMaterializedStyles, materializeCuratedStyles } from './editor/curated-styles.js';
import { mountDocxEditor } from './editor/mount.js';

const DEFAULT_LABELS = Object.freeze({
  normal: 'Normal',
  heading1: 'Heading 1',
  heading2: 'Heading 2',
  heading3: 'Heading 3',
  heading4: 'Heading 4',
  heading1Short: 'H1',
  heading2Short: 'H2',
  heading3Short: 'H3',
  heading4Short: 'H4',
  headingsGroup: 'Headings',
});

/**
 * <webcon-docx-editor>: the Word editor (@docx-editor.dev/vue) as a plain
 * custom element with a small byte-level API. It knows nothing about FAL or
 * AJAX routes; <typo3-docx-editor> and the page round-trip UI build on it.
 *
 * Attributes
 *   locale             "de" / "en" (editor chrome and date input)
 *   readonly           boolean; opens the document for viewing only
 *   content-controls   "show" draws every content control's boundary and
 *                      hides the Remove actions (page mode)
 *
 * Properties and methods
 *   labels             {normal, heading1…4, heading1Short…4Short, headingsGroup}
 *   load(source)       ArrayBuffer | Uint8Array | Blob → Promise, resolves once shown
 *   serialize()        → Promise<Uint8Array>, the document as DOCX (the opened
 *                      package, re-serialized; nothing unmodeled is dropped)
 *   revision           engine revision, rises with every edit
 *   dirty              whether edits happened since load() / markClean()
 *   markClean(revision = this.revision)
 *   editor             the @docx-editor.dev/core editor instance, once ready
 *
 * Events (bubbling, composed)
 *   docx-editor:ready         {editor}
 *   docx-editor:change        {dirty, revision} on every edit and when dirty flips
 *   docx-editor:save-request  the user asked to save (File › Save, toolbar, Ctrl/Cmd+S)
 *   docx-editor:error         {message} the document could not be opened
 *   docx-editor:font-error    {error} a font could not be loaded
 */
export class WebconDocxEditorElement extends HTMLElement {
  static get observedAttributes() {
    return ['locale', 'readonly', 'content-controls'];
  }

  #view = null;

  #editor = null;

  #document = null;

  #injected = [];

  #cleanRevision = 0;

  #dirty = false;

  #labels = DEFAULT_LABELS;

  #opening = null;

  get locale() {
    return this.getAttribute('locale') || 'en';
  }

  get readOnly() {
    return this.hasAttribute('readonly');
  }

  get contentControls() {
    return this.getAttribute('content-controls') === 'show' ? 'show' : 'default';
  }

  get labels() {
    return this.#labels;
  }

  set labels(labels) {
    this.#labels = { ...DEFAULT_LABELS, ...labels };
    this.#view?.update({ labels: this.#labels });
  }

  /** The @docx-editor.dev/core editor instance, or null before the document is shown. */
  get editor() {
    return this.#editor;
  }

  get revision() {
    return this.#editor?.getDocumentHandle().revision ?? 0;
  }

  get dirty() {
    return this.#dirty;
  }

  connectedCallback() {
    if (this.#view !== null) {
      return;
    }
    const host = document.createElement('div');
    host.className = 'webcon-docx-editor-host';
    this.replaceChildren(host);
    this.#view = mountDocxEditor(host, {
      locale: this.locale,
      readOnly: this.readOnly,
      contentControls: this.contentControls,
      labels: this.#labels,
      onReady: (editor) => this.#ready(editor),
      onChange: (change) => this.#changed(change),
      onSave: () => this.#emit('docx-editor:save-request'),
      onFontError: (error) => this.#emit('docx-editor:font-error', { error }),
    });
    if (this.#document !== null) {
      this.#view.setDocument(this.#document);
    }
  }

  disconnectedCallback() {
    this.#view?.unmount();
    this.#view = null;
    this.#editor = null;
  }

  attributeChangedCallback() {
    this.#view?.update({ locale: this.locale, readOnly: this.readOnly, contentControls: this.contentControls });
  }

  /**
   * Opens a DOCX package.
   *
   * @param {ArrayBuffer | Uint8Array | Blob} source
   * @returns {Promise<void>} resolves once the editor shows the document
   */
  async load(source) {
    const bytes = source instanceof Blob ? new Uint8Array(await source.arrayBuffer()) : new Uint8Array(source);
    const { bytes: prepared, injected } = materializeCuratedStyles(bytes);
    this.#opening?.reject(new Error('Superseded by another load().'));
    this.#editor = null;
    this.#injected = injected;
    this.#document = prepared;
    this.#setDirty(false);
    const opened = new Promise((resolve, reject) => {
      this.#opening = { resolve, reject };
    });
    this.#view?.setDocument(prepared);
    return opened;
  }

  /**
   * The document as DOCX bytes. Does not mark it clean: call markClean()
   * with the revision read before serializing once the bytes are stored.
   *
   * @returns {Promise<Uint8Array>}
   */
  async serialize() {
    if (this.#editor === null) {
      throw new Error('No document is open.');
    }
    const bytes = new Uint8Array(await this.#editor.save());
    return dropUnusedMaterializedStyles(bytes, this.#injected);
  }

  /** @param {number} [revision] the revision that was stored */
  markClean(revision = this.revision) {
    this.#cleanRevision = revision;
    this.#setDirty(this.revision !== revision);
  }

  focus() {
    this.#editor?.focus?.();
  }

  #ready(editor) {
    const parseError = editor.snapshot().parseError;
    if (parseError) {
      const message = typeof parseError === 'string' ? parseError : parseError.message ?? String(parseError);
      this.#opening?.reject(new Error(message));
      this.#opening = null;
      this.#emit('docx-editor:error', { message });
      return;
    }
    this.#editor = editor;
    this.#cleanRevision = editor.getDocumentHandle().revision;
    this.#setDirty(false);
    this.#opening?.resolve();
    this.#opening = null;
    this.#emit('docx-editor:ready', { editor });
  }

  #changed(change) {
    if (this.#editor === null) {
      return; // the change that completes opening
    }
    const dirty = change.revision !== this.#cleanRevision;
    if (!this.#setDirty(dirty)) {
      this.#emit('docx-editor:change', { dirty, revision: change.revision });
    }
  }

  /** @returns {boolean} whether a change event was dispatched */
  #setDirty(dirty) {
    if (this.#dirty === dirty) {
      return false;
    }
    this.#dirty = dirty;
    this.#emit('docx-editor:change', { dirty, revision: this.revision });
    return true;
  }

  #emit(type, detail = {}) {
    this.dispatchEvent(new CustomEvent(type, { bubbles: true, composed: true, detail }));
  }
}

if (!customElements.get('webcon-docx-editor')) {
  customElements.define('webcon-docx-editor', WebconDocxEditorElement);
}
