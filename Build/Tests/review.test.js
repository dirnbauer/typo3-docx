import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import module from 'node:module';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { GlobalRegistrator } from '@happy-dom/global-registrator';

/**
 * The review of an import opens through TYPO3's Modal, which renders in the
 * backend's top document, not in the module frame whose page loads
 * PageSync.css; review() has to link the stylesheet into the modal's document.
 * The TYPO3 modules review.js imports are stubbed, and the stub modal opens in
 * `globalThis.modalHost`.
 */
const STUBS = {
  '@typo3/backend/modal.js': `export default {
    sizes: { large: 'large' },
    advanced({ content }) {
      const modal = globalThis.modalHost.createElement('typo3-backend-modal');
      modal.append(content);
      globalThis.modalHost.body.append(modal);
      return modal;
    },
  };`,
  '@typo3/backend/notification.js': 'export default {};',
  '@typo3/backend/enum/severity.js': 'export const SeverityEnum = { notice: -1 };',
  '~labels/docx_editor.pagesync': 'export default { get: (key) => key };',
  '@webconsulting/docx-editor/page-sync/api.js': 'export function applyImport() {} export function discardImport() {}',
};

const hooks = typeof module.registerHooks === 'function';
const skip = !hooks && 'module.registerHooks() needs Node 22.15 or later';
const preview = { id: 'preview', plans: [{ page: null, entries: [], counts: {}, messages: [] }] };
let review;

before(async () => {
  if (!hooks) {
    return;
  }
  module.registerHooks({
    resolve: (specifier, context, next) => (Object.hasOwn(STUBS, specifier) ? { url: `stub:${specifier}`, shortCircuit: true } : next(specifier, context)),
    load: (url, context, next) => (url.startsWith('stub:') ? { format: 'module', source: STUBS[url.slice(5)], shortCircuit: true } : next(url, context)),
  });
  GlobalRegistrator.register({ settings: { disableCSSFileLoading: true, handleDisabledFileLoadingAsSuccess: true } });
  ({ review } = await import('../../Resources/Public/JavaScript/page-sync/review.js'));
});

after(async () => {
  if (hooks) {
    await GlobalRegistrator.unregister();
  }
});

function stylesheets(host) {
  return [...host.querySelectorAll('link[rel="stylesheet"]')].map((link) => link.href);
}

test('the review links PageSync.css into the document that hosts the modal, once', { skip }, () => {
  globalThis.modalHost = document.implementation.createHTMLDocument('backend');
  review(preview);
  review(preview);

  const links = stylesheets(globalThis.modalHost);
  assert.equal(links.length, 1);
  assert.match(links[0], /\/Resources\/Public\/Css\/PageSync\.css$/);
  assert.ok(existsSync(fileURLToPath(links[0])), `${links[0]} exists`);
  assert.equal(stylesheets(document).length, 0, 'the module document is left alone');
});

test('a review in the module document adds nothing: the module page loads PageSync.css', { skip }, () => {
  globalThis.modalHost = document;
  review(preview);

  assert.equal(stylesheets(document).length, 0);
});
