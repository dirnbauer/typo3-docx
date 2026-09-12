/**
 * Curates eigenpal's paragraph-style dropdown to a fixed TYPO3 set —
 * Normal + Heading 1–4 — regardless of the styles a DOCX defines.
 *
 * Upstream builds the dropdown options from the document's own style registry,
 * so a Word file surfaces arbitrary names ("List Paragraph", "Normal", "Heading
 * 3"). We replace the option-source expression with a filter over eigenpal's
 * built-in fallback array (wo in 1.9.x, Co in 1.6.x, vo in 1.2.x), so every
 * document offers exactly H1–H4 plus Normal (the only way back to body text).
 * Pairs with heading4-fallback, which appends Heading4 to that fallback array.
 *
 * Resilient to chunk renames AND minor refactors: matches by content pattern
 * across all chunks. Three shapes are recognized — 1.2.x, 1.6.x and 1.9.x (the
 * last one is identifier-agnostic, so minifier renames alone do not break it).
 * When upstream bumps change the shape again, add a new SHAPES entry and the
 * test:build runner asserts at least one shape still matches.
 *
 * @see Build/Sources/README.md
 */
import { EIGENPAL_REACT_PACKAGE, shapeMatches } from './heading4-fallback.js';

export { EIGENPAL_REACT_PACKAGE, shapeMatches };

/** Curated set: ignore document styles; show Normal + Heading 1–4. */
const FILTER_BODY = '.filter(h=>/^(Normal|Heading[1-4])$/.test(h.styleId))';

/**
 * Known shapes of eigenpal's dropdown option-source expression and how to
 * replace each with the curated set. Each entry pairs a `needle` (string or
 * RegExp that uniquely identifies the shape in a chunk) with a `sample` (a
 * literal excerpt the needle matches, used by the tests) and a `transform`
 * that rewrites the matched expression.
 *
 * Add a new entry when a future upstream bump reshapes the expression; do NOT
 * remove old entries — they remain harmless and keep older fallback paths
 * working.
 *
 * @typedef {{ id: string, needle: string | RegExp, sample: string, transform: (code: string) => string }} Shape
 * @type {ReadonlyArray<Shape>}
 */
export const SHAPES = [
  // 1.9.x (also 1.6.x–1.8.x): `let f=resolveParagraphStyleOptions(o);return f.length===0?wo:f.map(...)`
  // Identifier names are minifier output and change between releases, so the
  // needle captures them: $1 = styles argument, $2 = resolved options, $3 =
  // built-in fallback array.
  {
    id: '1.9.x',
    needle: /resolveParagraphStyleOptions\((\w+)\);return (\w+)\.length===0\?(\w+):\2\.map\(/,
    sample: 'resolveParagraphStyleOptions(o);return f.length===0?wo:f.map(',
    transform: (code) =>
      code.replace(
        /resolveParagraphStyleOptions\((\w+)\);return (\w+)\.length===0\?(\w+):\2\.map\(/,
        (_match, stylesArg, options, fallback) =>
          `resolveParagraphStyleOptions(${stylesArg});return ${fallback}${FILTER_BODY};0&&${options}.map(`,
      ),
  },
  // 1.6.x: `let u=resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(...)`
  {
    id: '1.6.x',
    needle: 'resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(',
    sample: 'resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(',
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
    sample: '!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")',
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
    if (shapeMatches(shape, code)) {
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
