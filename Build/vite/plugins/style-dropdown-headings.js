/**
 * Curates eigenpal's paragraph-style dropdown (the `Co` style-select) to a fixed
 * TYPO3 set — Normal + Heading 1–4 — regardless of the styles a DOCX defines.
 *
 * Upstream builds the dropdown from the document's own style registry, so a Word
 * file surfaces arbitrary names ("List Paragraph", "Normal", "Heading 3"). We
 * force the option source to eigenpal's built-in `vo` list filtered to the
 * heading set, so every document offers exactly H1–H4 plus Normal (the only way
 * back to body text). Pairs with heading4-fallback, which appends Heading4 to
 * `vo` so all four levels are present.
 *
 * @see Build/Sources/README.md
 */
import {
  EIGENPAL_FALLBACK_STYLES_CHUNK,
  EIGENPAL_REACT_PACKAGE,
} from './heading4-fallback.js';

export { EIGENPAL_FALLBACK_STYLES_CHUNK, EIGENPAL_REACT_PACKAGE };

/**
 * Option-source expression inside the `Co` style-select useMemo
 * (docx-editor-react@1.2.1). The leading `!o||o.length===0?vo:` branch reads the
 * document style registry `o`; we discard it.
 */
export const STYLE_DROPDOWN_OPTION_SOURCE =
  '!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")';

/** Curated replacement: ignore document styles; show Normal + Heading 1–4. */
export const STYLE_DROPDOWN_HEADINGS_SOURCE =
  'vo.filter(h=>/^(Normal|Heading[1-4])$/.test(h.styleId))';

/**
 * @param {string} code
 * @returns {string | null}
 */
export function patchStyleDropdownHeadings(code) {
  if (
    !code.includes(STYLE_DROPDOWN_OPTION_SOURCE) ||
    code.includes(STYLE_DROPDOWN_HEADINGS_SOURCE)
  ) {
    return null;
  }
  return code.replace(STYLE_DROPDOWN_OPTION_SOURCE, STYLE_DROPDOWN_HEADINGS_SOURCE);
}

/**
 * Vite plugin: curate the paragraph-style dropdown to Normal + H1–H4 at build time.
 */
export function styleDropdownHeadingsPlugin() {
  return {
    name: 'typo3-docx-style-dropdown-headings',
    transform(code, id) {
      if (!id.includes(EIGENPAL_REACT_PACKAGE) || !id.includes(EIGENPAL_FALLBACK_STYLES_CHUNK)) {
        return null;
      }
      const patched = patchStyleDropdownHeadings(code);
      if (patched === null) {
        return null;
      }
      return { code: patched, map: null };
    },
  };
}
