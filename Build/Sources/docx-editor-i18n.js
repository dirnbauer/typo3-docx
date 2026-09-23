import de from '@eigenpal/docx-editor-i18n/de';
import en from '@eigenpal/docx-editor-i18n/en';
import labels from '~labels/docx_editor.messages';

const HEADING_LEVELS = [1, 2, 3, 4];

/**
 * eigenpal's i18n bundle with the heading names from the docx_editor.messages
 * domain. Heading 4 is missing upstream, so TYPO3 is its only source.
 *
 * @param {'de' | 'en' | string} locale
 */
export function buildDocxEditorI18n(locale) {
  const base = locale === 'de' ? de : en;
  const styles = { ...base.styles };
  for (const level of HEADING_LEVELS) {
    styles[`heading${level}`] = labels.get(`editor.headings.h${level}.title`);
  }
  return { ...base, styles };
}
