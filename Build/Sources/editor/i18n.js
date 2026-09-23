import { deepMerge, en } from '@docx-editor.dev/i18n';
import de from '@docx-editor.dev/i18n/de';
import deOverlay from './i18n/de.json';

/**
 * Editor chrome catalogues. The upstream German catalogue leaves about a
 * third of its keys untranslated (they fall back to English); i18n/de.json
 * fills exactly those, so a German backend gets a German editor.
 */
const catalogs = new Map();

/**
 * @param {string} locale TYPO3 backend language ("de", "en", …)
 */
export function editorCatalog(locale) {
  const key = locale === 'de' ? 'de' : 'en';
  if (!catalogs.has(key)) {
    catalogs.set(key, key === 'de' ? deepMerge(deepMerge(en, de), deOverlay) : en);
  }
  return catalogs.get(key);
}

/** The engine's regional locale (date input, generated labels). */
export function engineLocale(locale) {
  return locale === 'de' ? 'de-DE' : 'en-US';
}
