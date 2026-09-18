import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import { notifySaveFailed } from '@webconsulting/docx-editor/notify.js';

/**
 * Docheader glue for <typo3-docx-editor>: Save / Save as buttons, Ctrl/Cmd+S,
 * the folder browser for "Save as", and a viewport clamp for eigenpal's
 * fixed-position toolbar popovers.
 */

const SAVE_AS_FIELD = 'docxEditorSaveAsFolder';

const app = document.getElementById('docx-editor-app');
const labels = JSON.parse(app?.dataset.labels ?? '{}');

function editorElement() {
  return document.querySelector('typo3-docx-editor');
}

async function save() {
  try {
    await editorElement()?.save();
  } catch {
    // The editor element already raised the error notification.
  }
}

function openFolderBrowser() {
  const url = new URL(app.dataset.elementBrowserUrl, window.location.origin);
  url.searchParams.set('mode', 'folder');
  url.searchParams.set('fieldReference', SAVE_AS_FIELD);
  url.searchParams.set('useEvents', '1');
  if (app.dataset.defaultFolderIdentifier) {
    url.searchParams.set('identifier', app.dataset.defaultFolderIdentifier);
  }
  Modal.advanced({ type: Modal.types.iframe, content: url.toString(), size: Modal.sizes.large });
}

async function saveAs(folderIdentifier) {
  const editor = editorElement();
  if (!editor) {
    return;
  }
  const fileName = window.prompt(labels.saveAsPrompt ?? 'File name', editor.fileName);
  if (!fileName) {
    return;
  }
  try {
    const result = await editor.saveAsToFolder(folderIdentifier, fileName);
    if (!result?.file) {
      return;
    }
    Notification.success(labels.saved ?? 'Saved', labels.saveAsSuccess ?? '');
    const target = new URL(app.dataset.editorUrl, window.location.origin);
    target.searchParams.set('file', result.file);
    window.location.href = target.toString();
  } catch (error) {
    notifySaveFailed(labels, error?.message || String(error));
  }
}

/** The element browser reports the picked folder either as a DOM event
 * (useEvents) or as a postMessage from the modal iframe. */
function onFolderPicked(data) {
  if (data?.actionName !== 'typo3:elementBrowser:elementAdded' || data.fieldName !== SAVE_AS_FIELD) {
    return;
  }
  if (typeof data.value !== 'string' || data.value === '') {
    return;
  }
  Modal.dismiss();
  saveAs(data.value);
}

function onKeydown(event) {
  if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.key.toLowerCase() !== 's') {
    return;
  }
  if (app.dataset.canWrite !== '1') {
    return;
  }
  event.preventDefault();
  save();
}

/**
 * eigenpal positions its popovers (mode picker, line spacing, …) with
 * `position: fixed` and a JS-computed `left`; in a narrow editor they can run
 * off the viewport. Nudge any overflowing popover back on-screen.
 */
function clampPopoversIntoView() {
  const margin = 8;
  document.querySelectorAll('[style*="position: fixed"], [style*="position:fixed"]').forEach((el) => {
    const style = window.getComputedStyle(el);
    if (style.position !== 'fixed' || (parseInt(style.zIndex, 10) || 0) < 1000) {
      return;
    }
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) {
      return;
    }
    const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
    const left = Math.min(Math.max(rect.left, margin), maxLeft);
    if (Math.abs(left - rect.left) > 1) {
      el.style.left = `${Math.round(left)}px`;
    }
  });
}

/** Popovers are inserted first and positioned a frame later. */
function scheduleClamp() {
  window.requestAnimationFrame(clampPopoversIntoView);
  window.setTimeout(clampPopoversIntoView, 120);
}

if (app) {
  document.querySelector('[data-identifier="docx-editor-save"]')?.addEventListener('click', (event) => {
    event.preventDefault();
    save();
  });
  document.querySelector('[data-identifier="docx-editor-save-as"]')?.addEventListener('click', (event) => {
    event.preventDefault();
    openFolderBrowser();
  });
  document.addEventListener('typo3:element-browser:message', (event) => onFolderPicked(event.detail));
  window.addEventListener('message', (event) => {
    if (MessageUtility.verifyOrigin(event.origin)) {
      onFolderPicked(event.data);
    }
  });
  window.addEventListener('keydown', onKeydown);
  document.addEventListener('click', scheduleClamp, true);
  document.addEventListener('keyup', scheduleClamp, true);
  window.addEventListener('resize', clampPopoversIntoView);
}
