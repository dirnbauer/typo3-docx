import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import { notifyDocxEditorStatus } from '@webconsulting/docx-editor/notify.js';

function getEditorElement() {
  return document.querySelector('typo3-docx-editor');
}

function readLabel(app, key, fallback) {
  const datasetKey = `label${key.charAt(0).toUpperCase()}${key.slice(1)}`;
  return app?.dataset?.[datasetKey] || fallback;
}

function getDocHeaderButton(identifier) {
  return document.querySelector(`[data-identifier="${identifier}"]`);
}

/**
 * eigenpal positions its toolbar popovers (mode picker, line-spacing, …) as
 * `position: fixed` with a JS-computed `left` that right-aligns them to the
 * trigger — so in a narrow editor they open leftward and clip off the viewport
 * edge. Nudge any such popover back on-screen. Only popovers that actually
 * overflow are moved; well-placed ones are left untouched.
 *
 * @param {HTMLElement} el
 */
function clampPopoverIntoView(el) {
  const rect = el.getBoundingClientRect();
  if (rect.width === 0 || rect.height === 0) {
    return;
  }
  const margin = 8;
  const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
  const left = Math.min(Math.max(rect.left, margin), maxLeft);
  if (Math.abs(left - rect.left) > 1) {
    el.style.left = `${Math.round(left)}px`;
  }
}

/**
 * Find any open editor popover (eigenpal renders them with an inline
 * `position: fixed` + high z-index) and clamp it into the viewport. The panel is
 * inserted first and positioned a frame later, so this runs deferred after a
 * toolbar interaction rather than on DOM insertion.
 */
function sweepClampPopovers() {
  document
    .querySelectorAll('[style*="position: fixed"], [style*="position:fixed"]')
    .forEach((el) => {
      const cs = window.getComputedStyle(el);
      if (cs.position === 'fixed' && (parseInt(cs.zIndex, 10) || 0) >= 1000) {
        clampPopoverIntoView(el);
      }
    });
}

/** Run the clamp sweep on the next frame and again shortly after (covers the
 * delay between a popover being inserted and positioned). */
function scheduleClampSweep() {
  window.requestAnimationFrame(sweepClampPopovers);
  window.setTimeout(sweepClampPopovers, 120);
}

async function triggerSave() {
  const editor = getEditorElement();
  if (!editor) {
    return;
  }
  try {
    await editor.save();
  } catch {
    // Error toast is handled by typo3-docx-editor via notifyDocxEditorStatus.
  }
}

function openFolderBrowser(app) {
  const browseButton = document.getElementById('docx-editor-save-as-browse');
  if (!browseButton) {
    return;
  }

  const params = new URLSearchParams({
    mode: 'folder',
    fieldReference: app?.dataset?.saveAsFieldName || 'docxEditorSaveAsFolder',
    useEvents: '1',
  });
  const identifier = app?.dataset?.defaultFolderIdentifier || browseButton.dataset.identifier || '';
  if (identifier) {
    params.set('identifier', identifier);
  }

  const baseUrl = app?.dataset?.elementBrowserUrl || browseButton.href;
  const separator = baseUrl.includes('?') ? '&' : '?';

  Modal.advanced({
    type: Modal.types.iframe,
    content: `${baseUrl}${separator}${params.toString()}`,
    size: Modal.sizes.large,
  });
}

async function completeSaveAs(app, folderIdentifier) {
  const editor = getEditorElement();
  if (!app || !editor || !folderIdentifier) {
    return;
  }

  const defaultName = editor.getDocumentFileName() || 'document.docx';
  const fileName = window.prompt(
    readLabel(app, 'saveAsPrompt', 'File name'),
    defaultName,
  );
  if (!fileName) {
    return;
  }

  try {
    const result = await editor.saveAsToFolder(folderIdentifier, fileName);
    if (!result?.file) {
      return;
    }

    Notification.success(
      readLabel(app, 'saved', 'Saved'),
      readLabel(app, 'saveAsSuccess', 'Document saved to the selected folder.'),
    );

    const moduleUrl = app.dataset.editorModuleUrl;
    if (moduleUrl) {
      const target = new URL(moduleUrl, window.location.origin);
      target.searchParams.set('file', result.file);
      window.location.href = target.toString();
    }
  } catch (error) {
    notifyDocxEditorStatus(app, 'error', error?.message || String(error));
  }
}

function handleFolderSelection(app, data) {
  const fieldName = app?.dataset?.saveAsFieldName || 'docxEditorSaveAsFolder';
  if (data.fieldName !== fieldName || typeof data.value !== 'string' || data.value === '') {
    return;
  }

  const hiddenField = document.getElementById('docxEditorSaveAsFolder');
  if (hiddenField) {
    hiddenField.value = data.value;
  }

  Modal.dismiss();
  completeSaveAs(app, data.value);
}

function bindDocHeaderButton(button, handler) {
  if (!button || button.dataset.bound === '1') {
    return;
  }
  button.dataset.bound = '1';
  button.addEventListener('click', (event) => {
    event.preventDefault();
    handler();
  });
}

class DocxEditorToolbarController {
  /**
   * @param {HTMLElement} app
   */
  constructor(app) {
    this.app = app;
    this.onMessage = (event) => this.handleElementBrowserMessage(event);
    this.onKeydown = (event) => this.handleKeydown(event);
    this.onToolbarPointer = () => scheduleClampSweep();
    this.onResize = () => sweepClampPopovers();
  }

  bind() {
    bindDocHeaderButton(getDocHeaderButton('docx-editor-save'), () => triggerSave());
    bindDocHeaderButton(getDocHeaderButton('docx-editor-save-as'), () => openFolderBrowser(this.app));
    window.addEventListener('message', this.onMessage);
    document.addEventListener('typo3:element-browser:message', this.onMessage);
    window.addEventListener('keydown', this.onKeydown);
    // Keep eigenpal toolbar popovers (mode picker, line-spacing, …) on-screen —
    // they open leftward from the trigger and clip off the viewport in a narrow
    // editor. Sweep after any click/keyup in the editor and on resize.
    document.addEventListener('click', this.onToolbarPointer, true);
    document.addEventListener('keyup', this.onToolbarPointer, true);
    window.addEventListener('resize', this.onResize);
  }

  destroy() {
    window.removeEventListener('message', this.onMessage);
    document.removeEventListener('typo3:element-browser:message', this.onMessage);
    window.removeEventListener('keydown', this.onKeydown);
    document.removeEventListener('click', this.onToolbarPointer, true);
    document.removeEventListener('keyup', this.onToolbarPointer, true);
    window.removeEventListener('resize', this.onResize);
  }

  handleElementBrowserMessage(event) {
    if (event.type === 'typo3:element-browser:message') {
      handleFolderSelection(this.app, event.detail || {});
      return;
    }

    if (!MessageUtility.verifyOrigin(event.origin)) {
      return;
    }

    const data = event.data;
    if (!data || data.actionName !== 'typo3:elementBrowser:elementAdded') {
      return;
    }

    handleFolderSelection(this.app, data);
  }

  handleKeydown(event) {
    if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 's' || event.shiftKey) {
      return;
    }
    const editor = getEditorElement();
    if (!editor || this.app?.dataset?.canWrite !== '1') {
      return;
    }
    event.preventDefault();
    triggerSave();
  }
}

/** @type {DocxEditorToolbarController | null} */
let activeController = null;

/**
 * @param {HTMLElement | null} app
 */
export function initDocxEditorToolbar(app) {
  if (!app) {
    return;
  }
  if (activeController?.app === app) {
    return;
  }
  activeController?.destroy();
  activeController = new DocxEditorToolbarController(app);
  activeController.bind();
}

const app = document.getElementById('docx-editor-app');
if (app) {
  initDocxEditorToolbar(app);
}
