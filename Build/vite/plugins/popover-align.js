/**
 * Make eigenpal's toolbar popovers (editing-mode picker, etc.) open to the
 * RIGHT of their trigger instead of to the left.
 *
 * The popover component right-aligns its menu when its align prop is "right":
 * it sets `left = trigger.right - menu.width`, so the menu's right edge meets
 * the trigger's right edge and the menu extends leftward. In a narrow editor
 * that runs off the viewport's left edge. The component already supports the
 * other direction in its else branch (`left = trigger.left`), so we rewrite the
 * right-align branch to left-align — the menu then opens rightward. The runtime
 * clamp in `Resources/Public/JavaScript/toolbar.js` still nudges it
 * back if opening rightward would overflow the right edge.
 *
 * Resilient to chunk renames AND minor refactors: matches by content pattern
 * across all dist chunks. When an upstream bump reshapes the expression, add a
 * new SHAPES entry; the test:build runner asserts at least one shape matches.
 *
 * @see Documentation/Developer/Index.rst
 */
import { EIGENPAL_REACT_PACKAGE, shapeMatches } from './heading4-fallback.js';

export { EIGENPAL_REACT_PACKAGE, shapeMatches };

/**
 * Known shapes of the popover's right-align expression and how to rewrite each
 * to left-align (open rightward). Do NOT remove old entries — they stay
 * harmless and keep older builds working.
 *
 * @typedef {{ id: string, needle: string | RegExp, sample: string, transform: (code: string) => string }} Shape
 * @type {ReadonlyArray<Shape>}
 */
export const SHAPES = [
  // 1.6.x–1.9.x: the editing-mode picker positions itself with
  // `a({top:g.bottom+2,left:g.right-220})` — `g` is the trigger rect (minified
  // name, `f` in 1.6.x), 220 the hard-coded menu width → right-aligned. Rewrite
  // to `left:g.left` so it opens rightward from the trigger.
  {
    id: '1.9.x-mode',
    needle: /left:(\w+)\.right-220/,
    sample: 'left:g.right-220',
    transform: (code) => code.replace(/left:(\w+)\.right-220/, 'left:$1.left'),
  },
  {
    id: '1.6.x-mode',
    needle: 'left:f.right-220',
    sample: 'left:f.right-220',
    transform: (code) => code.replace('left:f.right-220', 'left:f.left'),
  },
];

/**
 * @param {string} code
 * @returns {string | null}
 */
export function patchPopoverAlign(code) {
  for (const shape of SHAPES) {
    if (shapeMatches(shape, code)) {
      return shape.transform(code);
    }
  }
  return null;
}

/**
 * Vite plugin: flip the toolbar popover to open rightward wherever its
 * right-align expression appears in the dist (any chunk, any known shape).
 */
export function popoverAlignPlugin() {
  return {
    name: 'typo3-docx-popover-align',
    transform(code, id) {
      if (!id.includes(EIGENPAL_REACT_PACKAGE) || !id.includes('/dist/')) {
        return null;
      }
      const patched = patchPopoverAlign(code);
      if (patched === null) {
        return null;
      }
      return { code: patched, map: null };
    },
  };
}
