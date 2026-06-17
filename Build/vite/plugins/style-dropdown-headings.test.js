import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  EIGENPAL_REACT_PACKAGE,
  SHAPES,
  patchStyleDropdownHeadings,
} from './style-dropdown-headings.js';

const distRoot = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  'node_modules',
  EIGENPAL_REACT_PACKAGE,
  'dist',
);

function readAllDistMjs() {
  return readdirSync(distRoot)
    .filter((name) => name.endsWith('.mjs'))
    .map((name) => readFileSync(join(distRoot, name), 'utf8'))
    .join('\n');
}

test('at least one known style-dropdown shape still appears in the dist', () => {
  const source = readAllDistMjs();
  const matched = SHAPES.filter((shape) => source.includes(shape.needle));
  assert.ok(
    matched.length > 0,
    `No known dropdown shape matched in any dist/*.mjs chunk. Upstream refactored the style-select — add a new entry to SHAPES in style-dropdown-headings.js. Known shapes tried: ${SHAPES.map((s) => s.id).join(', ')}`,
  );
});

test('each shape transforms its own needle into the curated filter', () => {
  for (const shape of SHAPES) {
    const input = `prefix ${shape.needle} suffix`;
    const out = shape.transform(input);
    assert.notEqual(out, input, `shape ${shape.id} did not transform`);
    assert.ok(out.includes('Normal|Heading[1-4]'), `shape ${shape.id} did not inject the curated filter`);
  }
});

test('patchStyleDropdownHeadings is idempotent', () => {
  const shape = SHAPES[0];
  const input = `prefix ${shape.needle} suffix`;
  const patched = patchStyleDropdownHeadings(input);
  assert.ok(patched);
  assert.equal(patchStyleDropdownHeadings(patched), null);
});
