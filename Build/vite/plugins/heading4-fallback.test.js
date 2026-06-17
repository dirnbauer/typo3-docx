import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  EIGENPAL_REACT_PACKAGE,
  HEADING3_FALLBACK_TAIL,
  patchHeading4FallbackStyles,
} from './heading4-fallback.js';

const distRoot = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  'node_modules',
  EIGENPAL_REACT_PACKAGE,
  'dist',
);

/** Concat all .mjs chunks under dist/ so needle scans survive chunk renames. */
function readAllDistMjs() {
  return readdirSync(distRoot)
    .filter((name) => name.endsWith('.mjs'))
    .map((name) => readFileSync(join(distRoot, name), 'utf8'))
    .join('\n');
}

test('eigenpal fallback styles still contain the Heading3 array tail (any chunk)', () => {
  const source = readAllDistMjs();
  assert.ok(
    source.includes(HEADING3_FALLBACK_TAIL),
    'Expected the Heading3 fallback-array tail in some dist/*.mjs chunk — update HEADING3_FALLBACK_TAIL in heading4-fallback.js',
  );
  assert.ok(
    !source.includes('styles.heading4'),
    'Upstream already ships Heading4 natively — remove the Vite patch and this test',
  );
});

test('patchHeading4FallbackStyles injects Heading4 once', () => {
  const input = `const x=[${HEADING3_FALLBACK_TAIL};`;
  const patched = patchHeading4FallbackStyles(input);
  assert.ok(patched);
  assert.equal((patched.match(/styles\.heading4/g) ?? []).length, 1);
  assert.ok(patched.includes('styles.heading3'));
});

test('patchHeading4FallbackStyles is idempotent', () => {
  const input = `const x=[${HEADING3_FALLBACK_TAIL};`;
  const patched = patchHeading4FallbackStyles(input);
  assert.equal(patchHeading4FallbackStyles(patched), null);
});
