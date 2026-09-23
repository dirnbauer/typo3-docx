import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import labels from '~labels/docx_editor.messages';
import coreLabels from '~labels/backend.alt_doc';
import { notifySaveFailed } from '@webconsulting/docx-editor/notify.js';

/**
 * DocHeader glue for <typo3-docx-editor>: the core Save split button (Save,
 * Save and close, Save as…), Close with the core "unsaved changes" dialog,
 * Ctrl/Cmd+S, the folder browser and file name dialog for "Save as…", and a
 * viewport clamp for eigenpal's fixed-position toolbar popovers.
 */

const SAVE_AS_FIELD = 'docxEditorSaveAsFolder';

const app = document.getElementById('docx-editor-app');

/** Set once the user decided to leave; the unload warning stays quiet. */
let leaving = false;

function editorElement() {
  return document.querySelector('typo3-docx-editor');
}

function isDirty() {
  return editorElement()?.dirty === true;
}

function close() {
  leaving = true;
  window.location.href = app.dataset.returnUrl;
}

/** Resolves to whether the document was saved. */
async function save() {
  try {
    await editorElement()?.save();
    return true;
  } catch {
    // The editor element already raised the error notification.
    return false;
  }
}

async function saveAndClose() {
  if (await save()) {
    close();
  }
}

/** Close, asking first when there are unsaved changes, like FormEngine. */
function confirmClose() {
  if (!isDirty()) {
    close();
    return;
  }
  const buttons = [
    { text: coreLabels.get('buttons.confirm.close_without_save.no'), btnClass: 'btn-default', name: 'no', active: true },
    { text: coreLabels.get('buttons.confirm.close_without_save.yes'), btnClass: 'btn-default', name: 'yes' },
  ];
  if (app.dataset.canWrite === '1') {
    buttons.push({ text: coreLabels.get('buttons.confirm.save_and_close'), btnClass: 'btn-primary', name: 'save' });
  }
  const modal = Modal.confirm(
    coreLabels.get('label.confirm.close_without_save.title'),
    coreLabels.get('label.confirm.close_without_save.content'),
    SeverityEnum.warning,
    buttons,
  );
  modal.addEventListener('button.clicked', (event) => {
    const name = event.target.getAttribute('name');
    modal.hideModal();
    if (name === 'yes') {
      close();
    } else if (name === 'save') {
      saveAndClose();
    }
  });
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

/** A modal with one text field; resolves to the name, or null when cancelled. */
function askForFileName(defaultName) {
  return new Promise((resolve) => {
    const form = document.createElement('form');
    const group = document.createElement('div');
    group.className = 'form-group';
    const label = document.createElement('label');
    label.className = 'form-label';
    label.htmlFor = 'docx-editor-save-as-name';
    label.textContent = labels.get('editor.saveAs.fileName');
    const input = document.createElement('input');
    input.className = 'form-control';
    input.id = 'docx-editor-save-as-name';
    input.name = 'fileName';
    input.required = true;
    input.value = defaultName;
    group.append(label, input);
    form.append(group);

    let result = null;
    const modal = Modal.advanced({
      title: labels.get('editor.saveAs.title'),
      content: form,
      severity: SeverityEnum.notice,
      buttons: [
        { text: labels.get('editor.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
        { text: labels.get('editor.saveAs.submit'), btnClass: 'btn-primary', name: 'save', trigger: () => form.requestSubmit() },
      ],
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (input.value.trim() === '') {
        input.reportValidity();
        return;
      }
      result = input.value.trim();
      modal.hideModal();
    });
    modal.addEventListener('typo3-modal-shown', () => input.select(), { once: true });
    modal.addEventListener('typo3-modal-hidden', () => resolve(result), { once: true });
  });
}

async function saveAs(folderIdentifier) {
  const editor = editorElement();
  if (!editor) {
    return;
  }
  const fileName = await askForFileName(editor.fileName);
  if (!fileName) {
    return;
  }
  try {
    const result = await editor.saveAsToFolder(folderIdentifier, fileName);
    if (!result?.file) {
      return;
    }
    Notification.success(labels.get('editor.saved'), labels.get('editor.saveAsSuccess'));
    const target = new URL(app.dataset.editorUrl, window.location.origin);
    target.searchParams.set('file', result.file);
    target.searchParams.set('returnUrl', app.dataset.returnUrl);
    window.location.href = target.toString();
  } catch (error) {
    notifySaveFailed(error?.message || String(error));
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

const ACTIONS = {
  _savedok: save,
  _saveandclosedok: saveAndClose,
  _saveasdok: openFolderBrowser,
};

if (app) {
  // The DocHeader lives outside #docx-editor-app: the Save split button
  // (<button name> and dropdown <a data-name>) and Close.
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('button[name], a[data-name], [data-docx-action="close"]');
    if (!trigger) {
      return;
    }
    if (trigger.dataset.docxAction === 'close') {
      event.preventDefault();
      confirmClose();
      return;
    }
    const action = ACTIONS[trigger.getAttribute('name') ?? trigger.dataset.name];
    if (action) {
      event.preventDefault();
      action();
    }
  });
  document.addEventListener('typo3:element-browser:message', (event) => onFolderPicked(event.detail));
  window.addEventListener('message', (event) => {
    if (MessageUtility.verifyOrigin(event.origin)) {
      onFolderPicked(event.data);
    }
  });
  window.addEventListener('keydown', onKeydown);
  // Reload, breadcrumb and module navigation leave the page without asking:
  // let the browser warn about unsaved changes.
  window.addEventListener('beforeunload', (event) => {
    if (!leaving && isDirty()) {
      event.preventDefault();
    }
  });
  document.addEventListener('click', scheduleClamp, true);
  document.addEventListener('keyup', scheduleClamp, true);
  window.addEventListener('resize', clampPopoversIntoView);
}
