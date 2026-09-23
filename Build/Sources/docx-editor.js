/**
 * Bundle entry (import map: @webconsulting/docx-editor/editor.js).
 *
 * Defines <webcon-docx-editor> (the editor with a byte-level API) and
 * <typo3-docx-editor> (the backend module around it) and exports both, plus
 * the Vue mount for hosts that render the editor themselves.
 */
import '@docx-editor.dev/vue/styles.css';
import './typo3-docx-editor.js';

export { WebconDocxEditorElement } from './webcon-docx-editor.js';
export { Typo3DocxEditorElement } from './typo3-docx-editor.js';
export { mountDocxEditor } from './editor/mount.js';
export { CURATED_ROLES, curatedStyleOptions } from './editor/curated-styles.js';
