import de from '@eigenpal/docx-editor-i18n/de';
import en from '@eigenpal/docx-editor-i18n/en';

const HEADING_KEYS = ['heading1', 'heading2', 'heading3', 'heading4'];

/**
 * eigenpal's i18n bundle plus the TYPO3-translated heading names. Heading 4 is
 * missing upstream, so the TYPO3 label is its only source.
 *
 * @param {'de' | 'en' | string} locale
 * @param {Record<string, string>} headingLabels
 */
export function buildDocxEditorI18n(locale, headingLabels = {}) {
  const base = locale === 'de' ? de : en;
  const styles = { ...base.styles, heading4: locale === 'de' ? 'Überschrift 4' : 'Heading 4' };
  for (const key of HEADING_KEYS) {
    if (headingLabels[key]) {
      styles[key] = headingLabels[key];
    }
  }
  return { ...base, styles };
}
