import de from '@eigenpal/docx-editor-i18n/de';
import en from '@eigenpal/docx-editor-i18n/en';

/**
 * Extend eigenpal i18n with Heading 4 (missing upstream) and TYPO3-facing labels.
 *
 * @param {'de' | 'en' | string} locale
 * @param {Record<string, string>} [typo3Labels]
 */
export function buildDocxEditorI18n(locale, typo3Labels = {}) {
  const base = locale === 'de' ? de : en;
  const heading4 =
    typo3Labels.heading4 ?? (locale === 'de' ? 'Überschrift 4' : 'Heading 4');

  return {
    ...base,
    styles: {
      ...base.styles,
      heading4,
      ...(typo3Labels.heading1 ? { heading1: typo3Labels.heading1 } : {}),
      ...(typo3Labels.heading2 ? { heading2: typo3Labels.heading2 } : {}),
      ...(typo3Labels.heading3 ? { heading3: typo3Labels.heading3 } : {}),
    },
  };
}
