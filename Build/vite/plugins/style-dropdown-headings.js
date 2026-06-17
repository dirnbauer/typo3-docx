/**
 * Curates eigenpal's paragraph-style dropdown to a fixed TYPO3 set —
 * Normal + Heading 1–4 — regardless of the styles a DOCX defines.
 *
 * Upstream builds the dropdown options from the document's own style registry,
 * so a Word file surfaces arbitrary names ("List Paragraph", "Normal", "Heading
 * 3"). We replace the option-source expression with a filter over eigenpal's
 * built-in fallback array (Co in 1.6.x, vo in 1.2.x), so every document offers
 * exactly H1–H4 plus Normal (the only way back to body text). Pairs with
 * heading4-fallback, which appends Heading4 to that fallback array.
 *
 * Resilient to chunk renames AND minor refactors: matches by content pattern
 * across all chunks. Two shapes are recognized — 1.2.x and 1.6.x. When
 * upstream bumps change the shape again, add a new SHAPES entry and the
 * test:build runner asserts at least one shape still matches.
 *
 * @see Build/Sources/README.md
 */
import { EIGENPAL_REACT_PACKAGE } from './heading4-fallback.js';

export { EIGENPAL_REACT_PACKAGE };

/** Curated set: ignore document styles; show Normal + Heading 1–4. */
const FILTER_BODY = '.filter(h=>/^(Normal|Heading[1-4])$/.test(h.styleId))';

/**
 * Known shapes of eigenpal's dropdown option-source expression and how to
 * replace each with the curated set. Each entry pairs a `needle` (substring
 * present in the chunk that uniquely identifies the shape) with a `transform`
 * that rewrites it.
 *
 * Add a new entry when a future upstream bump reshapes the expression; do NOT
 * remove old entries — they remain harmless and keep older fallback paths
 * working.
 *
 * @typedef {{ id: string, needle: string, transform: (code: string) => string }} Shape
 * @type {ReadonlyArray<Shape>}
 */
export const SHAPES = [
  // 1.6.x: `let u=resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(...)`
  {
    id: '1.6.x',
    needle: 'resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(',
    transform: (code) =>
      code.replace(
        'resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(',
        `resolveParagraphStyleOptions(o);return Co${FILTER_BODY};0&&u.map(`,
      ),
  },
  // 1.2.x: `!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")`
  {
    id: '1.2.x',
    needle: '!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")',
    transform: (code) =>
      code.replace(
        '!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")',
        `vo${FILTER_BODY}`,
      ),
  },
];

/** Marker injected by the patch; lets us detect idempotency. */
const PATCHED_MARKER = `${FILTER_BODY}`;

/**
 * @param {string} code
 * @returns {string | null}
 */
export function patchStyleDropdownHeadings(code) {
  if (code.includes(PATCHED_MARKER)) {
    return null;
  }
  for (const shape of SHAPES) {
    if (code.includes(shape.needle)) {
      return shape.transform(code);
    }
  }
  return null;
}

/**
 * Vite plugin: curate the paragraph-style dropdown wherever its
 * option-source expression appears in the dist (any chunk, any known shape).
 */
export function styleDropdownHeadingsPlugin() {
  return {
    name: 'typo3-docx-style-dropdown-headings',
    transform(code, id) {
      if (!id.includes(EIGENPAL_REACT_PACKAGE) || !id.includes('/dist/')) {
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
