/**
 * Round-trip fidelity: open → save → re-open representative documents with
 * the editor engine the bundle ships (@docx-editor.dev/core), headless in
 * happy-dom, and prove nothing is lost.
 *
 *   npm run test:build
 *
 * The fixtures and how they were made: Build/Tests/Fixtures/generate-fixtures.py.
 */
import { GlobalRegistrator } from '@happy-dom/global-registrator';
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { strFromU8, strToU8, unzipSync, zipSync } from 'fflate';
import { canonicalPackage, packageDifferences } from './lib/ooxml-canonical.js';
import {
  curatedStyleOptions,
  dropUnusedMaterializedStyles,
  materializeCuratedStyles,
} from '../Sources/editor/curated-styles.js';

const fixture = (name) => new Uint8Array(readFileSync(new URL(`./Fixtures/${name}`, import.meta.url)));
const FIXTURES = {
  'features.docx': () => fixture('features.docx'),
  'structure.docx': () => fixture('structure.docx'),
  'localized-styles.docx': () => fixture('localized-styles.docx'),
  'example.docx (no styles part)': () =>
    new Uint8Array(readFileSync(new URL('../../Tests/Functional/Fixtures/Files/example.docx', import.meta.url))),
};

let createDocxEditor;

before(async () => {
  GlobalRegistrator.register();
  ({ createDocxEditor } = await import('@docx-editor.dev/core/editor'));
});

after(async () => {
  await GlobalRegistrator.unregister();
});

/** Opens bytes the way the editor does and hands the instance to `use`. */
async function withEditor(bytes, use) {
  const container = document.createElement('div');
  document.body.append(container);
  const editor = createDocxEditor({ container, document: bytes });
  try {
    for (let attempt = 0; attempt < 100 && (editor.snapshot().isLoading || editor.snapshot().isOpening); attempt += 1) {
      await new Promise((resolve) => setTimeout(resolve, 10));
    }
    assert.equal(editor.snapshot().parseError, null);
    return await use(editor);
  } finally {
    editor.destroy?.();
    container.remove();
  }
}

async function save(bytes) {
  return withEditor(bytes, async (editor) => new Uint8Array(await editor.save()));
}

function part(bytes, name) {
  return strFromU8(unzipSync(bytes)[name]);
}

/** paraId of the first body paragraph that contains `text`. */
function paragraphIdOf(bytes, text) {
  for (const [paragraph] of part(bytes, 'word/document.xml').matchAll(/<w:p\b[^>]*>[\s\S]*?<\/w:p>/g)) {
    if (paragraph.includes(text)) {
      return /w14:paraId="([0-9A-F]+)"/.exec(paragraph)?.[1];
    }
  }
  return undefined;
}

describe('untouched save', () => {
  for (const [name, load] of Object.entries(FIXTURES)) {
    test(`keeps every part of ${name}`, async () => {
      const original = load();
      const saved = await save(original);
      assert.deepEqual(packageDifferences(canonicalPackage(original), canonicalPackage(saved)), []);
    });

    test(`re-opens ${name} and saves it identically`, async () => {
      const saved = await save(load());
      const again = await save(saved);
      assert.deepEqual(packageDifferences(canonicalPackage(saved), canonicalPackage(again)), []);
    });
  }
});

describe('edited save', () => {
  const edits = {
    'features.docx': 'Round-trip features',
    'structure.docx': 'Structure that must survive',
  };
  for (const [name, anchorText] of Object.entries(edits)) {
    test(`changes only the edited paragraph of ${name}`, async () => {
      const opened = await save(FIXTURES[name]());
      const paraId = paragraphIdOf(opened, anchorText);
      assert.ok(paraId, 'the engine assigns paragraph IDs on save');

      const edited = await withEditor(opened, async (editor) => {
        assert.equal(editor.exec({ type: 'setSelection', anchor: { paraId } }).ok, true);
        assert.equal(editor.exec({ type: 'insertText', text: 'Edited: ' }).ok, true);
        return new Uint8Array(await editor.save());
      });

      const before = canonicalPackage(opened);
      const after = canonicalPackage(edited);
      assert.deepEqual(packageDifferences(before, after, { ignoreParts: /^word\/document\.xml$/ }), []);
      assert.match(after.get('word/document.xml'), /Edited: /);
      assert.equal(after.get('word/document.xml').replace('Edited: ', ''), before.get('word/document.xml'));

      const reopened = await save(edited);
      assert.deepEqual(packageDifferences(after, canonicalPackage(reopened)), []);
    });
  }

  test('keeps content controls, bookmarks, tracked changes and custom XML through an edit', async () => {
    const opened = await save(FIXTURES['structure.docx']());
    const paraId = paragraphIdOf(opened, 'Structure that must survive');
    const edited = await withEditor(opened, async (editor) => {
      editor.exec({ type: 'setSelection', anchor: { paraId } });
      editor.exec({ type: 'insertText', text: 'Edited: ' });
      return new Uint8Array(await editor.save());
    });
    const document = part(edited, 'word/document.xml');
    const tags = [...document.matchAll(/<w:tag w:val="([^"]+)"/g)].map((match) => match[1]);
    assert.deepEqual(tags, [
      'typo3:content:7',
      'typo3:container',
      'typo3:field:header',
      'typo3:choice',
      'typo3:date',
      'typo3:done',
      'typo3:bound:title',
    ]);
    assert.match(document, /<w:lock w:val="sdtLocked"\/>/);
    assert.match(document, /<w:lock w:val="contentLocked"\/>/);
    assert.match(document, /w:storeItemID="\{8B7C4A1E-2F3D-4C5B-9A6E-1D2C3B4A5F60\}"/);
    const bookmarks = [...document.matchAll(/<w:bookmarkStart\b[^>]*w:name="([^"]+)"/g)].map((match) => match[1]);
    assert.deepEqual(bookmarks.sort(), ['_GoBack', 'intro_range']);
    assert.match(document, /<w:ins\b[^>]*w:author="Editor A"/);
    assert.match(document, /<w:del\b[^>]*w:author="Editor B"/);
    assert.match(part(edited, 'word/footnotes.xml'), /A footnote that has to survive\./);
    assert.equal(canonicalPackage(edited).get('customXml/item2.xml'), canonicalPackage(opened).get('customXml/item2.xml'));
    assert.match(part(edited, 'docProps/custom.xml'), /name="typo3PageUid"[^>]*><vt:i4>42<\/vt:i4>/);
  });
});

describe('exported pictures', () => {
  // The page export names every picture after its file reference and records the embedded
  // bytes in its manifest; the import recognises an unchanged picture by both. Editing the page
  // in the backend must keep them.
  test('keeps the name and the bytes of a picture through an edit', async () => {
    const original = unzipSync(FIXTURES['features.docx']());
    const document = strFromU8(original['word/document.xml']);
    const named = document.replace(/(<wp:docPr\b[^>]*\bname=")[^"]*"/, '$1typo3:sys_file_reference:31"');
    assert.notEqual(named, document, 'the fixture has a picture');
    const opened = await save(zipSync({ ...original, 'word/document.xml': strToU8(named) }));

    const paraId = paragraphIdOf(opened, 'Round-trip features');
    const edited = await withEditor(opened, async (editor) => {
      assert.equal(editor.exec({ type: 'setSelection', anchor: { paraId } }).ok, true);
      assert.equal(editor.exec({ type: 'insertText', text: 'Edited: ' }).ok, true);
      return new Uint8Array(await editor.save());
    });

    assert.match(part(edited, 'word/document.xml'), /<wp:docPr\b[^>]*name="typo3:sys_file_reference:31"/);
    const media = (bytes) => Object.entries(unzipSync(bytes))
      .filter(([name]) => name.startsWith('word/media/'))
      .map(([, data]) => Buffer.from(data).toString('base64'))
      .sort();
    assert.deepEqual(media(edited), media(FIXTURES['features.docx']()));
  });
});

describe('curated styles', () => {
  test('uses the headings a document defines', async () => {
    const original = FIXTURES['features.docx']();
    const materialized = materializeCuratedStyles(original);
    assert.equal(materialized.bytes, original);
    assert.deepEqual(materialized.injected, []);
    const options = await withEditor(original, async (editor) => curatedStyleOptions(editor.getDocumentStyles()));
    assert.deepEqual(options, [
      { role: 'normal', styleId: 'Normal' },
      { role: 'heading1', styleId: 'Heading1' },
      { role: 'heading2', styleId: 'Heading2' },
      { role: 'heading3', styleId: 'Heading3' },
      { role: 'heading4', styleId: 'Heading4' },
    ]);
  });

  test('maps localized style IDs by name and materializes latent headings', async () => {
    const original = FIXTURES['localized-styles.docx']();
    const { bytes, injected } = materializeCuratedStyles(original);
    assert.deepEqual(injected, ['Heading3', 'Heading4']);
    const options = await withEditor(bytes, async (editor) => curatedStyleOptions(editor.getDocumentStyles()));
    assert.deepEqual(options, [
      { role: 'normal', styleId: 'Standard' },
      { role: 'heading1', styleId: 'berschrift1' },
      { role: 'heading2', styleId: 'berschrift2' },
      { role: 'heading3', styleId: 'Heading3' },
      { role: 'heading4', styleId: 'Heading4' },
    ]);
  });

  test('takes materialized headings nobody applied out again', async () => {
    const original = FIXTURES['localized-styles.docx']();
    const { bytes, injected } = materializeCuratedStyles(original);
    const saved = dropUnusedMaterializedStyles(await save(bytes), injected);
    assert.deepEqual(packageDifferences(canonicalPackage(original), canonicalPackage(saved)), []);
  });

  test('keeps a materialized heading once it is applied', async () => {
    const { bytes, injected } = materializeCuratedStyles(FIXTURES['localized-styles.docx']());
    const opened = await save(bytes);
    const paraId = paragraphIdOf(opened, 'Noch ein Absatz.');
    const styled = await withEditor(opened, async (editor) => {
      editor.exec({ type: 'setSelection', anchor: { paraId } });
      assert.equal(editor.exec({ type: 'setParagraphStyle', styleId: 'Heading3' }).ok, true);
      return new Uint8Array(await editor.save());
    });
    const saved = dropUnusedMaterializedStyles(styled, injected);
    const styles = part(saved, 'word/styles.xml');
    assert.match(styles, /w:styleId="Heading3"/);
    assert.doesNotMatch(styles, /w:styleId="Heading4"/);
    assert.match(part(saved, 'word/document.xml'), /<w:pStyle w:val="Heading3"\/>/);
  });

  test('leaves documents without a styles part alone', () => {
    const original = FIXTURES['example.docx (no styles part)']();
    assert.equal(materializeCuratedStyles(original).bytes, original);
  });
});
