import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  EIGENPAL_FALLBACK_STYLES_CHUNK,
  EIGENPAL_REACT_PACKAGE,
  STYLE_DROPDOWN_HEADINGS_SOURCE,
  STYLE_DROPDOWN_OPTION_SOURCE,
  patchStyleDropdownHeadings,
} from './style-dropdown-headings.js';

const chunkPath = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  'node_modules',
  EIGENPAL_REACT_PACKAGE,
  'dist',
  `${EIGENPAL_FALLBACK_STYLES_CHUNK}.mjs`,
);

test('eigenpal style-select still uses the known option-source expression', () => {
  const source = readFileSync(chunkPath, 'utf8');
  assert.ok(
    source.includes(STYLE_DROPDOWN_OPTION_SOURCE),
    `Expected ${EIGENPAL_FALLBACK_STYLES_CHUNK}.mjs to contain the style-select option source — update STYLE_DROPDOWN_OPTION_SOURCE`,
  );
});

test('patchStyleDropdownHeadings restricts the dropdown to Normal + H1–H4', () => {
  const input = `let c=j.useMemo(()=>${STYLE_DROPDOWN_OPTION_SOURCE}.filter(u=>u.qFormat?true:!(u.hidden||u.semiHidden)),[o]);`;
  const patched = patchStyleDropdownHeadings(input);
  assert.ok(patched);
  assert.ok(patched.includes(STYLE_DROPDOWN_HEADINGS_SOURCE));
  assert.ok(!patched.includes(STYLE_DROPDOWN_OPTION_SOURCE));
});

test('patchStyleDropdownHeadings is idempotent', () => {
  const input = `${STYLE_DROPDOWN_OPTION_SOURCE}.filter(u=>u.qFormat)`;
  const patched = patchStyleDropdownHeadings(input);
  assert.equal(patchStyleDropdownHeadings(patched), null);
});
