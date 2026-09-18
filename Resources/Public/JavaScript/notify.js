import Notification from '@typo3/backend/notification.js';

/**
 * Save feedback via the TYPO3 Notification API. `labels` is the parsed
 * `data-labels` JSON of #docx-editor-app.
 *
 * @param {Record<string, string>} labels
 */
export function notifySaved(labels) {
  Notification.success(labels.saved ?? 'Saved', labels.savedDetail ?? '');
}

/**
 * @param {Record<string, string>} labels
 * @param {string} [detail]
 */
export function notifySaveFailed(labels, detail = '') {
  const title = labels.saveFailed ?? 'Save failed';
  Notification.error(title, detail || title);
}
