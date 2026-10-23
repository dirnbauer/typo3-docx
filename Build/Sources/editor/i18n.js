import { deepMerge, en } from '@docx-editor.dev/i18n';
import de from '@docx-editor.dev/i18n/de';

/**
 * Editor chrome catalogues. Upstream supplies the complete German catalogue;
 * merge it over English to retain defaults if a future catalogue adds keys.
 */
const catalogs = new Map();

/**
 * @param {string} locale TYPO3 backend language ("de", "en", …)
 */
export function editorCatalog(locale) {
  const key = locale === 'de' ? 'de' : 'en';
  if (!catalogs.has(key)) {
    catalogs.set(key, key === 'de' ? deepMerge(en, de) : en);
  }
  return catalogs.get(key);
}

/** The engine's regional locale (date input, generated labels). */
export function engineLocale(locale) {
  return locale === 'de' ? 'de-DE' : 'en-US';
}
