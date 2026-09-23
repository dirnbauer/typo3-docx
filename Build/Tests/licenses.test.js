/**
 * Licences of what the editor bundle ships.
 *
 * docx-editor.dev is Apache-2.0 except three packages under the EigenPal Pro
 * Evaluation License (no production use without a paid agreement):
 * @docx-editor.dev/pro (comments, tracked changes, collaboration, custom
 * nodes), @docx-editor.dev/editor-api and @docx-editor.dev/docx-to-pdf. None
 * of them may be installed, not even transitively or as an optional or peer
 * dependency. Everything that ends up in Resources/Public/Vite must be under
 * a licence from the allowlist, and its licence text must travel with it.
 *
 *   npm run test:build
 */
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import test from 'node:test';

const ROOT = resolve(import.meta.dirname, '../..');
const VITE = join(ROOT, 'Resources/Public/Vite');

const COMMERCIAL = ['@docx-editor.dev/pro', '@docx-editor.dev/editor-api', '@docx-editor.dev/docx-to-pdf'];

const ALLOWED = new Set([
  'Apache-2.0',
  'MIT',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'ISC',
  '0BSD',
  'Zlib',
  'CC0-1.0',
  'OFL-1.1',
  'LicenseRef-GUST-Font-License',
]);

const json = (file) => JSON.parse(readFileSync(join(ROOT, file), 'utf8'));

/** The licence identifiers of an SPDX expression ("(MIT AND Zlib)" → MIT, Zlib). */
function identifiers(expression) {
  return String(expression ?? '')
    .replace(/[()]/g, ' ')
    .split(/\s+(?:AND|OR|WITH)\s+|\s+/)
    .filter((part) => part !== '');
}

function assertAllowed(expression, what) {
  const ids = identifiers(expression);
  assert.ok(ids.length > 0, `${what} declares no licence`);
  for (const id of ids) {
    assert.ok(ALLOWED.has(id), `${what} is licensed ${expression}; ${id} is not on the allowlist`);
  }
}

/** Bundled packages as the build lists them: "## name - version (licence)". */
function bundledPackages() {
  const text = readFileSync(join(VITE, 'licenses/THIRD-PARTY-LICENSES.md'), 'utf8');
  return [...text.matchAll(/^## (\S+) - (\S+) \(([^\n]+)\)\n\n([^\n]*)/gm)].map(([, name, version, license, firstLine]) => ({
    name,
    version,
    license,
    firstLine,
  }));
}

test('no commercial docx-editor.dev package is installed or required', () => {
  const lock = json('package-lock.json');
  const manifest = json('package.json');
  for (const [path, entry] of [['package.json', manifest], ...Object.entries(lock.packages)]) {
    for (const name of COMMERCIAL) {
      assert.ok(!path.endsWith(`node_modules/${name}`), `${name} is installed (${path})`);
      for (const field of ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies']) {
        assert.ok(!(name in (entry[field] ?? {})), `${path || 'the root package'} lists ${name} in ${field}`);
      }
    }
  }
  for (const name of COMMERCIAL) {
    assert.ok(!existsSync(join(ROOT, 'node_modules', name)), `${name} is in node_modules`);
  }
});

test('every package the editor needs at runtime has an allowed licence', () => {
  const lock = json('package-lock.json');
  const runtime = Object.entries(lock.packages).filter(([path, entry]) => path !== '' && entry.dev !== true);
  assert.ok(runtime.length > 20, 'the lock lists the runtime tree');
  for (const [path, entry] of runtime) {
    assertAllowed(entry.license, path.replace(/^.*node_modules\//, ''));
  }
});

test('the bundle holds only the Apache-2.0 docx-editor.dev packages, and allowed licences with their text', () => {
  const bundled = bundledPackages();
  assert.ok(bundled.some((pkg) => pkg.name === 'vue'), 'the licence file lists the bundled packages');
  assert.deepEqual(
    bundled.filter((pkg) => pkg.name.startsWith('@docx-editor.dev/')).map((pkg) => pkg.name),
    ['@docx-editor.dev/core', '@docx-editor.dev/fonts', '@docx-editor.dev/i18n', '@docx-editor.dev/vue'],
  );
  for (const pkg of bundled) {
    assertAllowed(pkg.license, `${pkg.name} ${pkg.version}`);
    const manifest = json(`node_modules/${pkg.name}/package.json`);
    assert.equal(pkg.license, manifest.license, `${pkg.name}: the licence file matches its package.json`);
    assert.notEqual(pkg.firstLine.trim(), '', `${pkg.name}: its licence text (or a note) is shipped`);
  }
});

test('code the docx-editor.dev packages inline is under allowed licences, and its notices ship', () => {
  for (const name of ['core', 'vue', 'i18n', 'fonts']) {
    const notices = readFileSync(join(ROOT, `node_modules/@docx-editor.dev/${name}/THIRD_PARTY_NOTICES.md`), 'utf8');
    assert.equal(readFileSync(join(VITE, `licenses/docx-editor.dev-${name}-THIRD_PARTY_NOTICES.md`), 'utf8'), notices);
    for (const [, pkg, license] of notices.matchAll(/^- (\S+ \S+) — (.+?) \(/gm)) {
      assertAllowed(license, `${pkg} (inlined into @docx-editor.dev/${name})`);
    }
  }
});

test('the fonts and the text shaper ship with their licences', () => {
  const assets = readdirSync(join(VITE, 'assets'));
  const licenses = new Set(readdirSync(join(VITE, 'licenses/fonts')));
  const byFamily = [
    [/^Caladea-/, ['OFL-Caladea.txt']],
    [/^Carlito-/, ['OFL-Carlito.txt']],
    [/^Liberation/, ['LICENSE-Liberation.txt']],
    [/^TeXGyre/, ['GUST-FONT-LICENSE.txt', 'LPPL-1.3c.txt']],
  ];
  const fonts = assets.filter((file) => /\.(ttf|otf|woff2?)$/.test(file));
  assert.ok(fonts.length > 0, 'the bundle ships fonts');
  for (const font of fonts) {
    const family = byFamily.find(([pattern]) => pattern.test(font));
    assert.ok(family, `${font} belongs to no known family: add its licence`);
    for (const license of family[1]) {
      assert.ok(licenses.has(license), `${font} ships without licenses/fonts/${license}`);
    }
  }
  if (assets.some((file) => file.startsWith('harfbuzz') && file.endsWith('.wasm'))) {
    assert.ok(existsSync(join(VITE, 'licenses/HarfBuzz-COPYING.txt')), 'harfbuzz.wasm ships without its COPYING');
  }
});
