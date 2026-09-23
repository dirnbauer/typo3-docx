import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { en } from '@docx-editor.dev/i18n';
import de from '@docx-editor.dev/i18n/de';

/**
 * Build/Sources/editor/i18n/de.json fills the keys the upstream German
 * catalogue leaves untranslated. It must only name keys the English
 * catalogue has, and only keys upstream still leaves open: after an upgrade
 * that translates one, drop it from the overlay.
 */
const overlay = JSON.parse(readFileSync(new URL('../Sources/editor/i18n/de.json', import.meta.url), 'utf8'));

function leaves(tree, prefix = '') {
  return Object.entries(tree).flatMap(([key, value]) =>
    value !== null && typeof value === 'object' ? leaves(value, `${prefix}${key}.`) : [[`${prefix}${key}`, value]],
  );
}

function lookup(tree, path) {
  return path.split('.').reduce((node, key) => (node === undefined || node === null ? undefined : node[key]), tree);
}

test('the German overlay only translates open upstream keys', () => {
  const entries = leaves(overlay);
  assert.ok(entries.length > 100, 'the overlay was read');
  for (const [path, value] of entries) {
    assert.equal(typeof lookup(en, path), 'string', `${path} is not an English catalogue key`);
    assert.equal(lookup(de, path), null, `${path} is translated upstream now; drop it from de.json`);
    assert.ok(typeof value === 'string' && value !== '', `${path} needs a translation`);
  }
});

test('the German overlay keeps the ICU placeholders of the English source', () => {
  for (const [path, value] of leaves(overlay)) {
    const placeholders = (text) => [...text.matchAll(/\{(\w+)/g)].map((match) => match[1]).sort();
    assert.deepEqual(placeholders(value), placeholders(lookup(en, path)), path);
  }
});
