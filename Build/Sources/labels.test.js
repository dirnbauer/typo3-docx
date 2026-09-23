import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

/**
 * The label providers from ~labels/ throw on unknown keys, so every key the
 * scripts ask for must exist in the docx_editor.messages domain — in English
 * and in German.
 */
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const sources = [
  ...readdirSync(join(root, 'Build/Sources'))
    .filter((name) => /\.jsx?$/.test(name) && !name.endsWith('.test.js'))
    .map((name) => join(root, 'Build/Sources', name)),
  ...readdirSync(join(root, 'Resources/Public/JavaScript'))
    .filter((name) => name.endsWith('.js'))
    .map((name) => join(root, 'Resources/Public/JavaScript', name)),
];

function unitIds(file) {
  const xliff = readFileSync(join(root, 'Resources/Private/Language', file), 'utf8');
  return new Set([...xliff.matchAll(/<unit id="([^"]+)"/g)].map((match) => match[1]));
}

/** Static keys, plus the template keys expanded for the four heading levels. */
function requestedKeys() {
  const keys = new Set();
  for (const file of sources) {
    const code = readFileSync(file, 'utf8');
    for (const match of code.matchAll(/(?<!core)labels\.get\((['`])([^'`]+)\1/g)) {
      if (match[2].includes('${level}')) {
        [1, 2, 3, 4].forEach((level) => keys.add(match[2].replace('${level}', String(level))));
      } else {
        keys.add(match[2]);
      }
    }
  }
  return keys;
}

test('every label the scripts request exists in English and German', () => {
  const english = unitIds('locallang.xlf');
  const german = unitIds('de.locallang.xlf');
  const keys = requestedKeys();

  assert.ok(keys.size > 10, 'the scan found the label calls');
  for (const key of keys) {
    assert.ok(english.has(key), `locallang.xlf lacks ${key}`);
    assert.ok(german.has(key), `de.locallang.xlf lacks ${key}`);
  }
});
