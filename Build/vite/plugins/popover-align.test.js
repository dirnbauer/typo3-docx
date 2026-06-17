import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  EIGENPAL_REACT_PACKAGE,
  SHAPES,
  patchPopoverAlign,
} from './popover-align.js';

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

test('at least one known popover-align shape still appears in the dist', () => {
  const source = readAllDistMjs();
  const matched = SHAPES.filter((shape) => source.includes(shape.needle));
  assert.ok(
    matched.length > 0,
    `No known popover-align shape matched in any dist/*.mjs chunk. Upstream reshaped the popover positioning — add a new entry to SHAPES in popover-align.js. Known shapes tried: ${SHAPES.map((s) => s.id).join(', ')}`,
  );
});

test('each shape rewrites the right-align expression to left-align', () => {
  for (const shape of SHAPES) {
    const input = `prefix ${shape.needle} suffix`;
    const out = shape.transform(input);
    assert.notEqual(out, input, `shape ${shape.id} did not transform`);
    assert.ok(/left:[a-z]\.left/.test(out), `shape ${shape.id} did not left-align`);
    assert.ok(!out.includes(shape.needle), `shape ${shape.id} left the right-align needle behind`);
  }
});

test('patchPopoverAlign is idempotent', () => {
  const input = `prefix ${SHAPES[0].needle} suffix`;
  const patched = patchPopoverAlign(input);
  assert.ok(patched);
  assert.equal(patchPopoverAlign(patched), null);
});
