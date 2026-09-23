import assert from 'node:assert/strict';
import test from 'node:test';
import { pageRules, paperSizes } from '../Sources/editor/print.js';

/**
 * Printing maps every page to a named @page rule with the paper size of its section,
 * so a landscape section prints landscape and a Letter document on Letter paper.
 * (The PDF page count itself is checked in a real browser: see the Developer guide.)
 */
const PX = 96 / 25.4;
const box = (widthMm, heightMm) => ({ box: { width: widthMm * PX, height: heightMm * PX } });

test('pages of the same size share one paper size', () => {
  const { sizes, pageSize } = paperSizes([box(210, 297), box(210, 297), box(297, 210), box(210, 297)]);

  assert.deepEqual(sizes, [{ width: 210, height: 297 }, { width: 297, height: 210 }]);
  assert.deepEqual(pageSize, [0, 0, 1, 0]);
});

test('sub-millimetre layout differences do not make a new paper size', () => {
  const { sizes } = paperSizes([box(215.9, 279.4), box(215.94, 279.37)]);

  assert.equal(sizes.length, 1);
});

test('every paper size becomes a named page rule without margins', () => {
  assert.equal(
    pageRules([{ width: 215.9, height: 279.4 }, { width: 279.4, height: 215.9 }]),
    '@page webcon-docx-page-0 { size: 215.9mm 279.4mm; margin: 0; }\n'
      + '@page webcon-docx-page-1 { size: 279.4mm 215.9mm; margin: 0; }',
  );
});
