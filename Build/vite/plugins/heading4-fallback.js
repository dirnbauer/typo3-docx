/**
 * Patches eigenpal's built-in fallback paragraph styles so Heading 4 appears
 * in the style dropdown when a DOCX has no custom styles.xml entries.
 *
 * @see Build/Sources/README.md
 */

/** @type {const} */
export const EIGENPAL_REACT_PACKAGE = '@eigenpal/docx-editor-react';

/** Chunk filename for docx-editor-react@1.2.1 (verify with npm run test:build). */
export const EIGENPAL_FALLBACK_STYLES_CHUNK = 'chunk-SW2JOSQG';

export const HEADING3_FALLBACK_TAIL =
  '{styleId:"Heading3",name:"Heading 3",nameKey:"styles.heading3",type:"paragraph",priority:5,qFormat:true,fontSize:28,bold:true}]';

export const HEADING3_AND_HEADING4_FALLBACK_TAIL =
  '{styleId:"Heading3",name:"Heading 3",nameKey:"styles.heading3",type:"paragraph",priority:5,qFormat:true,fontSize:28,bold:true},{styleId:"Heading4",name:"Heading 4",nameKey:"styles.heading4",type:"paragraph",priority:6,qFormat:true,fontSize:24,bold:true}]';

/**
 * @param {string} code
 * @returns {string | null}
 */
export function patchHeading4FallbackStyles(code) {
  if (!code.includes(HEADING3_FALLBACK_TAIL) || code.includes('styles.heading4')) {
    return null;
  }
  return code.replace(HEADING3_FALLBACK_TAIL, HEADING3_AND_HEADING4_FALLBACK_TAIL);
}

/**
 * Vite plugin: inject Heading4 into eigenpal fallback styles at build time.
 */
export function heading4FallbackPlugin() {
  return {
    name: 'typo3-docx-heading4-fallback',
    transform(code, id) {
      if (!id.includes(EIGENPAL_REACT_PACKAGE) || !id.includes(EIGENPAL_FALLBACK_STYLES_CHUNK)) {
        return null;
      }
      const patched = patchHeading4FallbackStyles(code);
      if (patched === null) {
        return null;
      }
      return { code: patched, map: null };
    },
  };
}
