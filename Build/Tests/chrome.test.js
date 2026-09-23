/**
 * Only the Apache-2.0 packages of docx-editor.dev are used, and the editor
 * shows nothing of the commercial @docx-editor.dev/pro package — no
 * suggesting mode, comment or tracked-change review, markup views, reviewers
 * or "requires pro" placeholders — in the toolbar, the menus, the context
 * menu or on a shortcut, for editable and read-only documents alike.
 *
 * The editor is built from Build/Sources/editor/mount.js and mounted headless
 * (happy-dom), exactly as the backend renders it. What counts as commercial
 * comes from the engine's own chrome registry: every control of its "review"
 * group except paragraph marks and forms protection, which the open-source
 * engine drives.
 *
 *   npm run test:build
 */
import { GlobalRegistrator } from '@happy-dom/global-registrator';
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { strFromU8, unzipSync } from 'fflate';
import { buildMount } from './lib/mounted-editor.js';

const fixture = (name) => new Uint8Array(readFileSync(new URL(`./Fixtures/${name}`, import.meta.url)));

const FREE_REVIEW_SLOTS = new Set(['review.paragraphMarks', 'review.protectDocument']);

/** Catalogue keys of the commercial review, comment and collaboration chrome. */
const PRO_KEYS = [
  'editingMode.label',
  'editingMode.suggesting',
  'editingMode.suggestingHint',
  'formattingBar.commentsAndChanges',
  'formattingBar.unavailableInPreview',
  'reviewers.label',
  'reviewers.markupOptions',
  'comments.addComment',
  'comments.replyPlaceholder',
  'comments.resolve',
  'review.displayForReview',
  'review.simpleMarkup',
  'review.allMarkup',
  'review.noMarkup',
  'review.previousChange',
  'review.nextChange',
  'review.acceptAllChanges',
  'review.rejectAllChanges',
  'review.showPane',
  'review.deleteComment',
  'review.discardChange',
  'collaboration.participants',
];

const LABELS = {
  normal: 'Normal',
  heading1: 'Heading 1',
  heading2: 'Heading 2',
  heading3: 'Heading 3',
  heading4: 'Heading 4',
  heading1Short: 'H1',
  heading2Short: 'H2',
  heading3Short: 'H3',
  heading4Short: 'H4',
  headingsGroup: 'Headings',
};

let mountDocxEditor;
let cleanup;
let proSlots;
let proPhrases;
let warn;

before(async () => {
  GlobalRegistrator.register();
  // The packaged fonts are fetched over HTTP in a browser; headless there is none.
  warn = console.warn;
  console.warn = (...args) => {
    if (!String(args[0]).startsWith('[fonts]')) {
      warn(...args);
    }
  };
  const { CHROME_GROUPS, chromeSlotId } = await import('@docx-editor.dev/core/editor');
  proSlots = new Set(
    CHROME_GROUPS.filter((group) => group.id === 'review')
      .flatMap((group) => group.controls.map((control) => chromeSlotId(group, control)))
      .filter((slot) => !FREE_REVIEW_SLOTS.has(slot)),
  );
  const { en } = await import('@docx-editor.dev/i18n');
  proPhrases = [
    ...PRO_KEYS.map((key) => {
      const label = key.split('.').reduce((node, part) => node?.[part], en);
      assert.equal(typeof label, 'string', `the catalogue has ${key}`);
      return label;
    }),
    '@docx-editor.dev/pro',
    'pro review module',
  ];
  ({ mountDocxEditor, cleanup } = await buildMount());
});

after(async () => {
  cleanup?.();
  console.warn = warn;
  await GlobalRegistrator.unregister();
});

/**
 * Mounts the editor on a document and resolves once the document is shown. The engine
 * instance is rebuilt when the document arrives, so the last one reported ready counts.
 */
async function open(bytes, options = {}) {
  const host = document.createElement('div');
  document.body.append(host);
  let latest = null;
  const view = mountDocxEditor(host, { locale: 'en', labels: LABELS, ...options, onReady: (editor) => { latest = editor; } });
  view.setDocument(bytes);
  for (let attempt = 0; attempt < 200; attempt += 1) {
    await settle(25);
    const snapshot = latest?.snapshot();
    if (snapshot && !snapshot.isLoading && snapshot.isOpening !== true && host.querySelector('.docx-pages')?.textContent) {
      break;
    }
  }
  assert.ok(latest, 'the editor is ready');
  await settle();
  return {
    host,
    get editor() {
      return latest;
    },
    close() {
      view.unmount();
      host.remove();
    },
  };
}

const settle = (ms = 50) => new Promise((resolve) => setTimeout(resolve, ms));

const slotsIn = (root) => [...root.querySelectorAll('[data-slot]')].map((element) => element.getAttribute('data-slot'));

/** What the chrome says: text, accessible names and tooltips of every element. */
function wordsIn(root) {
  const words = [root.textContent ?? ''];
  for (const element of root.querySelectorAll('*')) {
    for (const attribute of ['aria-label', 'title', 'placeholder', 'aria-description']) {
      const value = element.getAttribute(attribute);
      if (value) {
        words.push(value);
      }
    }
  }
  return words.join('\n');
}

function assertNothingCommercial(root, where) {
  const pro = slotsIn(root).filter((slot) => proSlots.has(slot));
  assert.deepEqual(pro, [], `${where} holds commercial controls`);
  const words = wordsIn(root);
  for (const phrase of proPhrases) {
    assert.ok(!words.includes(phrase), `${where} says "${phrase}"`);
  }
}

const openMenus = () => [...document.querySelectorAll('[role="menu"]')];

async function closeMenus() {
  for (let attempt = 0; attempt < 3 && openMenus().length > 0; attempt += 1) {
    (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle(20);
  }
  assert.equal(openMenus().length, 0, 'the menus close again');
}

describe('the editor offers nothing of the commercial package', () => {
  for (const readOnly of [false, true]) {
    test(`toolbar${readOnly ? ' (read-only)' : ''}: no editing mode, comments, reviewers or markup views`, async () => {
      const { host, close } = await open(fixture('features.docx'), { readOnly });
      try {
        const toolbar = host.querySelector('[role="toolbar"]');
        assert.ok(toolbar, 'the toolbar is there');
        assert.ok(slotsIn(toolbar).includes('text.bold'), 'the toolbar is populated');
        assertNothingCommercial(toolbar, 'The toolbar');
      } finally {
        close();
      }
    });
  }

  test('menus: no suggesting, tracked-change review, comments or export', async () => {
    const { host, close } = await open(fixture('features.docx'));
    try {
      const menus = {};
      for (const trigger of host.querySelectorAll('[role="menubar"] .docx-menubar__trigger')) {
        trigger.click();
        await settle();
        const [menu] = openMenus();
        assert.ok(menu, `${trigger.textContent} opens`);
        menus[trigger.textContent.trim()] = slotsIn(menu);
        assertNothingCommercial(menu, `The ${trigger.textContent.trim()} menu`);
        await closeMenus();
      }

      assert.deepEqual(Object.keys(menus), ['File', 'Format', 'Insert', 'Review']);
      assert.deepEqual(menus.Review, ['review.paragraphMarks', 'review.protectDocument']);
      assert.deepEqual(menus.File, ['file.save', 'file.print', 'file.pageSetup'], 'Save, Print (the browser\'s) and Page setup; no open, no PDF export');
    } finally {
      close();
    }
  });

  test('context menu: no "Add a comment"', async () => {
    const { host, close } = await open(fixture('features.docx'));
    try {
      host.querySelector('.docx-pages').dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 120, clientY: 120 }));
      await settle(100);
      const [menu] = openMenus();
      assert.ok(menu, 'the context menu opens');
      assert.ok(slotsIn(menu).includes('edit.paste'), 'with the clipboard rows');
      assertNothingCommercial(menu, 'The context menu');
      await closeMenus();
    } finally {
      close();
    }
  });

  test('shortcuts: none opens a review feature or a placeholder', async () => {
    const opened = await open(fixture('features.docx'));
    const { host, close } = opened;
    try {
      const pages = host.querySelector('.docx-pages');
      pages.focus();
      // Word's comment and track-changes shortcuts, on both platforms.
      for (const key of [
        { key: 'm', ctrlKey: true, altKey: true },
        { key: 'm', metaKey: true, altKey: true },
        { key: 'e', ctrlKey: true, shiftKey: true },
        { key: 'e', metaKey: true, shiftKey: true },
      ]) {
        pages.dispatchEvent(new KeyboardEvent('keydown', { ...key, bubbles: true, cancelable: true }));
        await settle(20);
      }

      const snapshot = opened.editor.snapshot();
      assert.equal(snapshot.editingMode ?? 'editing', 'editing');
      assert.equal(snapshot.reviewPaneOpen, false);
      assert.equal(openMenus().length, 0);
      assert.equal(document.querySelectorAll('[role="dialog"], [role="alertdialog"]').length, 0);
      assertNothingCommercial(document.body, 'The page');
    } finally {
      close();
    }
  });
});

describe('review content in a document (open-source engine)', () => {
  test('tracked changes show in their final state and are kept on save', async () => {
    const opened = await open(fixture('structure.docx'));
    const { host, close } = opened;
    try {
      const text = host.querySelector('.docx-pages').textContent;
      assert.match(text, /inserted words/);
      assert.doesNotMatch(text, /deleted words/);

      const document = strFromU8(unzipSync(new Uint8Array(await opened.editor.save()))['word/document.xml']);
      assert.match(document, /<w:ins\b[^>]*w:author="Editor A"[\s\S]*?inserted words/);
      assert.match(document, /<w:del\b[^>]*w:author="Editor B"[\s\S]*?deleted words/);
    } finally {
      close();
    }
  });

  test('comments are not shown and are kept on save', async () => {
    const opened = await open(fixture('features.docx'));
    const { host, close } = opened;
    try {
      assert.match(host.querySelector('.docx-pages').textContent, /This sentence carries a comment\./);
      assert.doesNotMatch(host.textContent, /Please check this sentence\./);

      const saved = unzipSync(new Uint8Array(await opened.editor.save()));
      assert.match(strFromU8(saved['word/comments.xml']), /Please check this sentence\./);
      assert.match(strFromU8(saved['word/document.xml']), /<w:commentRangeStart\b/);
    } finally {
      close();
    }
  });
});
