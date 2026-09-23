/**
 * Builds the editor's Vue mount (Build/Sources/editor/mount.js) with Vite into
 * a temporary folder and mounts it headless in happy-dom — the chrome exactly
 * as the backend renders it, for tests that inspect toolbars, menus and
 * context menus.
 */
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const ROOT = resolve(import.meta.dirname, '../../..');

/** @returns {Promise<{mountDocxEditor: Function, cleanup: () => void}>} */
export async function buildMount() {
  const { build } = await import('vite');
  const { default: vue } = await import('@vitejs/plugin-vue');
  const outDir = mkdtempSync(join(tmpdir(), 'webcon-docx-editor-test-'));
  await build({
    configFile: false,
    root: ROOT,
    logLevel: 'silent',
    publicDir: false,
    plugins: [vue()],
    resolve: { alias: [{ find: /^vue$/, replacement: 'vue/dist/vue.runtime.esm-bundler.js' }] },
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      __VUE_OPTIONS_API__: 'false',
      __VUE_PROD_DEVTOOLS__: 'false',
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    build: {
      outDir,
      emptyOutDir: true,
      minify: false,
      target: 'es2022',
      assetsInlineLimit: 0,
      modulePreload: false,
      rolldownOptions: {
        input: resolve(ROOT, 'Build/Sources/editor/mount.js'),
        preserveEntrySignatures: 'exports-only',
        output: {
          entryFileNames: 'mount.js',
          chunkFileNames: 'chunks/[name]-[hash].js',
          assetFileNames: 'assets/[name]-[hash][extname]',
        },
      },
    },
  });
  const { mountDocxEditor } = await import(pathToFileURL(join(outDir, 'mount.js')).href);

  return { mountDocxEditor, cleanup: () => rmSync(outDir, { recursive: true, force: true }) };
}
