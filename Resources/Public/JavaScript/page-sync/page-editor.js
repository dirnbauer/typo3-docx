import '@webconsulting/docx-editor/editor.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import labels from '~labels/docx_editor.pagesync';
import editorLabels from '~labels/docx_editor.messages';
import coreLabels from '~labels/backend.alt_doc';
import { decodeBase64, loadPage, previewImport } from './api.js';
import { review } from './review.js';

/**
 * "Edit in Word": the page as a Word document in <webcon-docx-editor>.
 * Every content element is a content control; saving (DocHeader Save, the
 * editor's File › Save, Ctrl/Cmd+S) shows the review of what the import would
 * change and writes it once confirmed, then reloads the page's document so
 * new elements carry their records. "Upload Word file" reviews a document
 * edited elsewhere the same way.
 */

const app = document.getElementById('docx-page-sync-app');
const surface = app?.querySelector('[data-page-sync-surface]');
const upload = app?.querySelector('[data-page-sync-upload]');
const dirtyBadge = document.querySelector('[data-page-sync-dirty]');

let editor = null;
let leaving = false;
let working = false;

function page() {
  return Number(app.dataset.page);
}

function language() {
  return Number(app.dataset.language);
}

function isDirty() {
  return editor?.dirty === true;
}

function setBusy(busy) {
  working = busy;
  surface.setAttribute('aria-busy', busy ? 'true' : 'false');
  document.querySelectorAll('[data-page-sync-action="save"], [data-page-sync-action="upload"]').forEach((button) => {
    button.disabled = busy;
  });
}

function createEditor() {
  const element = document.createElement('webcon-docx-editor');
  element.className = 'docx-editor-module__editor';
  element.setAttribute('locale', app.dataset.editorLocale || 'en');
  element.setAttribute('content-controls', 'show');
  element.labels = {
    normal: editorLabels.get('editor.styles.normal'),
    heading1: editorLabels.get('editor.headings.h1.title'),
    heading2: editorLabels.get('editor.headings.h2.title'),
    heading3: editorLabels.get('editor.headings.h3.title'),
    heading4: editorLabels.get('editor.headings.h4.title'),
    heading1Short: editorLabels.get('editor.headings.h1'),
    heading2Short: editorLabels.get('editor.headings.h2'),
    heading3Short: editorLabels.get('editor.headings.h3'),
    heading4Short: editorLabels.get('editor.headings.h4'),
    headingsGroup: editorLabels.get('editor.headings.group'),
  };
  element.addEventListener('docx-editor:save-request', () => save());
  element.addEventListener('docx-editor:change', (event) => {
    if (dirtyBadge) {
      dirtyBadge.hidden = !event.detail?.dirty;
    }
  });
  element.addEventListener('docx-editor:error', (event) => {
    Notification.error(labels.get('ui.error.open'), event.detail?.message ?? '');
  });
  surface.replaceChildren(element);
  return element;
}

/** Loads the page's current document into the editor. */
async function open() {
  setBusy(true);
  try {
    const payload = await loadPage(page(), language());
    await editor.load(decodeBase64(payload.data));
    editor.markClean();
    if (dirtyBadge) {
      dirtyBadge.hidden = true;
    }
    if (payload.skipped?.length) {
      Notification.info(labels.get('ui.edit.skippedTitle'), labels.get('ui.edit.skipped', { count: payload.skipped.length }));
    }
  } catch (error) {
    Notification.error(labels.get('ui.error.open'), error?.message || String(error));
  } finally {
    setBusy(false);
  }
}

/** Reviews a document and, once imported, reloads the page's document. */
async function importDocument(bytes, cleanRevision = null) {
  let preview;
  try {
    preview = await previewImport({ mode: 'update', page: page(), language: language() }, bytes);
  } catch (error) {
    Notification.error(labels.get('ui.error.preview'), error?.message || String(error));
    return;
  }
  const outcome = await review(preview, { title: labels.get('ui.review.title') });
  if (outcome.applied) {
    if (cleanRevision !== null) {
      editor.markClean(cleanRevision);
    }
    await open();
  } else if (outcome.outdated) {
    Notification.warning(labels.get('ui.outdated.title'), labels.get('ui.outdated.text'));
  }
}

async function save() {
  if (working || !editor?.editor) {
    return;
  }
  setBusy(true);
  try {
    const revision = editor.revision;
    const bytes = await editor.serialize();
    await importDocument(bytes, revision);
  } catch (error) {
    Notification.error(labels.get('ui.error.preview'), error?.message || String(error));
  } finally {
    setBusy(false);
  }
}

function confirm(title, message, confirmLabel) {
  return new Promise((resolve) => {
    let confirmed = false;
    const modal = Modal.confirm(title, message, SeverityEnum.warning, [
      { text: coreLabels.get('buttons.confirm.close_without_save.no'), btnClass: 'btn-default', name: 'no', active: true },
      { text: confirmLabel, btnClass: 'btn-warning', name: 'yes' },
    ]);
    modal.addEventListener('button.clicked', (event) => {
      confirmed = event.target.getAttribute('name') === 'yes';
      modal.hideModal();
    });
    modal.addEventListener('typo3-modal-hidden', () => resolve(confirmed), { once: true });
  });
}

async function uploadFile(file) {
  const limit = Number(app.dataset.maxUpload || 25) * 1024 * 1024;
  if (!/\.docx$/i.test(file.name)) {
    Notification.error(labels.get('ui.error.preview'), labels.get('error.notADocx'));
    return;
  }
  if (file.size > limit) {
    Notification.error(labels.get('ui.error.preview'), labels.get('error.fileTooLarge', [Number(app.dataset.maxUpload || 25)]));
    return;
  }
  if (isDirty() && !(await confirm(labels.get('ui.upload.dirtyTitle'), labels.get('ui.upload.dirtyText'), labels.get('ui.upload.continue')))) {
    return;
  }
  setBusy(true);
  try {
    await importDocument(new Uint8Array(await file.arrayBuffer()));
  } finally {
    setBusy(false);
  }
}

function close() {
  leaving = true;
  window.location.href = app.dataset.returnUrl;
}

async function confirmClose() {
  if (!isDirty()) {
    close();
    return;
  }
  if (await confirm(
    coreLabels.get('label.confirm.close_without_save.title'),
    coreLabels.get('label.confirm.close_without_save.content'),
    coreLabels.get('buttons.confirm.close_without_save.yes'),
  )) {
    close();
  }
}

if (app && surface) {
  editor = createEditor();
  open();

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-page-sync-action]');
    if (!trigger) {
      return;
    }
    const action = trigger.dataset.pageSyncAction;
    if (action === 'save') {
      event.preventDefault();
      save();
    } else if (action === 'upload') {
      event.preventDefault();
      upload?.click();
    } else if (action === 'close') {
      event.preventDefault();
      confirmClose();
    }
  });
  upload?.addEventListener('change', () => {
    const file = upload.files?.[0];
    upload.value = '';
    if (file) {
      uploadFile(file);
    }
  });
  window.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || !(event.ctrlKey || event.metaKey) || event.shiftKey || event.key.toLowerCase() !== 's') {
      return;
    }
    event.preventDefault();
    save();
  });
  window.addEventListener('beforeunload', (event) => {
    if (!leaving && isDirty()) {
      event.preventDefault();
    }
  });
}
