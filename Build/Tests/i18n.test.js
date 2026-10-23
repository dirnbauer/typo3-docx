import assert from 'node:assert/strict';
import test from 'node:test';
import { en } from '@docx-editor.dev/i18n';
import de from '@docx-editor.dev/i18n/de';
import { editorCatalog } from '../Sources/editor/i18n.js';

/**
 * The upstream German catalogue now translates the complete editor. Keep
 * every English key translated and its ICU placeholders intact on upgrades.
 */

function leaves(tree, prefix = '') {
  return Object.entries(tree).flatMap(([key, value]) =>
    value !== null && typeof value === 'object' ? leaves(value, `${prefix}${key}.`) : [[`${prefix}${key}`, value]],
  );
}

function lookup(tree, path) {
  return path.split('.').reduce((node, key) => (node === undefined || node === null ? undefined : node[key]), tree);
}

test('the German catalogue translates every English key', () => {
  const entries = leaves(en);
  assert.ok(entries.length > 100, 'the catalogue was read');
  for (const [path] of entries) {
    const value = lookup(de, path);
    assert.ok(typeof value === 'string' && value !== '', `${path} needs a German translation`);
    assert.equal(lookup(editorCatalog('de'), path), value, `${path} uses the upstream translation`);
  }
});

test('the German catalogue keeps the ICU placeholders of the English source', () => {
  for (const [path, value] of leaves(de)) {
    const placeholders = (text) => [...text.matchAll(/\{(\w+)/g)].map((match) => match[1]).sort();
    assert.deepEqual(placeholders(value), placeholders(lookup(en, path)), path);
  }
});
