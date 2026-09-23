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
  ...readdirSync(join(root, 'Build/Sources'), { recursive: true })
    .filter((name) => /\.(js|vue)$/.test(name))
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

/**
 * The page round trip's scripts ask the docx_editor.pagesync domain (`labels`, and
 * the review's `label()` helper) and borrow the heading names from
 * docx_editor.messages (`editorLabels`).
 */
test('every label the page round trip requests exists in English and German', () => {
  const directory = join(root, 'Resources/Public/JavaScript/page-sync');
  const pageSync = [unitIds('locallang_pagesync.xlf'), unitIds('de.locallang_pagesync.xlf')];
  const messages = [unitIds('locallang.xlf'), unitIds('de.locallang.xlf')];
  let found = 0;
  for (const name of readdirSync(directory).filter((file) => file.endsWith('.js'))) {
    const code = readFileSync(join(directory, name), 'utf8');
    for (const match of code.matchAll(/(?<![A-Za-z])(labels\.get|label|editorLabels\.get)\('([^']+)'/g)) {
      const [english, german] = match[1] === 'editorLabels.get' ? messages : pageSync;
      assert.ok(english.has(match[2]), `${name}: English lacks ${match[2]}`);
      assert.ok(german.has(match[2]), `${name}: German lacks ${match[2]}`);
      found += 1;
    }
  }
  assert.ok(found > 40, 'the scan found the label calls');
});
