/**
 * Patches eigenpal's built-in fallback paragraph styles so Heading 4 appears
 * in the style dropdown when a DOCX has no custom styles.xml entries.
 *
 * Resilient to chunk renames: matches by content pattern across all chunks in
 * @eigenpal/docx-editor-react/dist, NOT by a hardcoded chunk filename. When
 * upstream bumps reshuffle chunks, only the needle string check needs to
 * survive — and the test:build runner asserts it does.
 *
 * @see Build/Sources/README.md
 */

/** @type {const} */
export const EIGENPAL_REACT_PACKAGE = '@eigenpal/docx-editor-react';

/**
 * Tail of the built-in fallback style array, ending at Heading 3 (eigenpal
 * stops there — we extend to Heading 4). This shape has been stable across
 * 1.2.x → 1.6.x; only the chunk filename it lives in has changed.
 */
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
 * Vite plugin: inject Heading4 into the eigenpal fallback styles wherever
 * the array tail appears in the dist (any chunk).
 */
export function heading4FallbackPlugin() {
  return {
    name: 'typo3-docx-heading4-fallback',
    transform(code, id) {
      if (!id.includes(EIGENPAL_REACT_PACKAGE) || !id.includes('/dist/')) {
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
