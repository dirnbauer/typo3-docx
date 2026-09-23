import Notification from '@typo3/backend/notification.js';
import labels from '~labels/docx_editor.messages';

/**
 * Save feedback through the TYPO3 notification API.
 *
 * @param {string} filePath e.g. "fileadmin / user_upload/report.docx"
 */
export function notifySaved(filePath) {
  Notification.success(labels.get('editor.saved'), labels.get('editor.savedDetail', [filePath]));
}

/**
 * @param {string} [detail]
 */
export function notifySaveFailed(detail = '') {
  Notification.error(labels.get('editor.saveFailed'), detail);
}
