import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  EIGENPAL_FALLBACK_STYLES_CHUNK,
  EIGENPAL_REACT_PACKAGE,
  HEADING3_FALLBACK_TAIL,
  patchHeading4FallbackStyles,
} from './heading4-fallback.js';

const packageRoot = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  'node_modules',
  EIGENPAL_REACT_PACKAGE,
);

test('eigenpal fallback styles chunk still contains Heading3 needle', () => {
  const chunkPath = join(packageRoot, 'dist', `${EIGENPAL_FALLBACK_STYLES_CHUNK}.mjs`);
  const source = readFileSync(chunkPath, 'utf8');
  assert.ok(
    source.includes(HEADING3_FALLBACK_TAIL),
    `Expected ${EIGENPAL_FALLBACK_STYLES_CHUNK}.mjs to contain the Heading3 fallback tail`,
  );
  assert.ok(
    !source.includes('styles.heading4'),
    'Upstream already ships Heading4 — remove the Vite patch and this test',
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
