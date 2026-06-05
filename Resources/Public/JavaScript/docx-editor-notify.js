import Notification from '@typo3/backend/notification.js';

function readLabel(app, key, fallback) {
  const datasetKey = `label${key.charAt(0).toUpperCase()}${key.slice(1)}`;
  return app?.dataset?.[datasetKey] || fallback;
}

/**
 * Show save feedback via the TYPO3 backend Notification API.
 *
 * @param {HTMLElement | null | undefined} app
 * @param {'saved' | 'error' | 'saving' | string} state
 * @param {string} [detail]
 */
export function notifyDocxEditorStatus(app, state, detail = '') {
  switch (state) {
    case 'saved':
      Notification.success(
        readLabel(app, 'saved', 'Saved'),
        readLabel(app, 'savedDetail', app?.dataset?.filePath || app?.dataset?.fileName || ''),
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
