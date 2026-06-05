import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

function getApp() {
  return document.getElementById('docx-editor-app');
}

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

function notifyStatus(app, state, detail = '') {
  switch (state) {
    case 'saved':
      Notification.success(
        readLabel(app, 'saved', 'Saved'),
        '',
      );
      break;
    case 'error':
      Notification.error(
        readLabel(app, 'saveFailed', 'Save failed'),
        detail || readLabel(app, 'saveFailed', 'Save failed'),
      );
      break;
    case 'saving':
      break;
    default:
      break;
  }
}

async function triggerSave() {
  const editor = getEditorElement();
  if (!editor) {
    return;
  }
  try {
    await editor.save();
  } catch {
    // Error toast is emitted via docx-editor:status from typo3-docx-editor.
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

async function completeSaveAs(folderIdentifier) {
  const app = getApp();
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
    notifyStatus(app, 'error', error?.message || String(error));
  }
}

function handleFolderSelection(data) {
  const app = getApp();
  const fieldName = app?.dataset?.saveAsFieldName || 'docxEditorSaveAsFolder';
  if (data.fieldName !== fieldName || typeof data.value !== 'string' || data.value === '') {
    return;
  }

  const hiddenField = document.getElementById('docxEditorSaveAsFolder');
  if (hiddenField) {
    hiddenField.value = data.value;
  }

  Modal.dismiss();
  completeSaveAs(data.value);
}

function handleElementBrowserMessage(event) {
  if (event.type === 'typo3:element-browser:message') {
    handleFolderSelection(event.detail || {});
    return;
  }

  if (!MessageUtility.verifyOrigin(event.origin)) {
    return;
  }

  const data = event.data;
  if (!data || data.actionName !== 'typo3:elementBrowser:elementAdded') {
    return;
  }

  handleFolderSelection(data);
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

function bindToolbar(app) {
  bindDocHeaderButton(getDocHeaderButton('docx-editor-save'), () => triggerSave());
  bindDocHeaderButton(getDocHeaderButton('docx-editor-save-as'), () => openFolderBrowser(app));

  if (!window.docxEditorToolbarMessageListener) {
    window.docxEditorToolbarMessageListener = true;
    window.addEventListener('message', handleElementBrowserMessage);
    document.addEventListener('typo3:element-browser:message', handleElementBrowserMessage);
  }

  if (!window.docxEditorToolbarKeyListener) {
    window.docxEditorToolbarKeyListener = true;
    window.addEventListener('keydown', (event) => {
      if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 's' || event.shiftKey) {
        return;
      }
      const editor = getEditorElement();
      if (!editor || app?.dataset?.canWrite !== '1') {
        return;
      }
      event.preventDefault();
      triggerSave();
    });
  }

  if (!window.docxEditorStatusListener) {
    window.docxEditorStatusListener = true;
    document.addEventListener('docx-editor:status', (event) => {
      const detail = event.detail || {};
      notifyStatus(getApp(), detail.state, detail.message);
    });
  }
}

const app = getApp();
if (app) {
  bindToolbar(app);
}
