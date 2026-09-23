import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Licences Vite's own licence file cannot see: the font licences (SIL OFL,
 * GUST, LPPL) that travel with the font files, HarfBuzz's for the shaper
 * (harfbuzz.wasm), and the notices for code the docx-editor.dev packages
 * inline into their own dist. Packages that ship no licence text get a line
 * in THIRD-PARTY-LICENSES.md naming the licence their package.json declares.
 *
 * Only the Apache-2.0 packages of docx-editor.dev are bundled — never
 * @docx-editor.dev/pro, /editor-api or /docx-to-pdf (EigenPal Pro Evaluation
 * License). Build/Tests/licenses.test.js enforces both.
 */
function packagedLicenses() {
  const packages = resolve(process.cwd(), 'node_modules/@docx-editor.dev');
  return {
    name: 'webcon-docx-editor-licenses',
    generateBundle() {
      for (const file of readdirSync(resolve(packages, 'fonts/licenses'))) {
        this.emitFile({ type: 'asset', fileName: `licenses/fonts/${file}`, source: readFileSync(resolve(packages, 'fonts/licenses', file)) });
      }
      for (const file of readdirSync(resolve(packages, 'core/licenses'))) {
        this.emitFile({ type: 'asset', fileName: `licenses/${file}`, source: readFileSync(resolve(packages, 'core/licenses', file)) });
      }
      for (const name of ['core', 'vue', 'i18n', 'fonts']) {
        this.emitFile({
          type: 'asset',
          fileName: `licenses/docx-editor.dev-${name}-THIRD_PARTY_NOTICES.md`,
          source: readFileSync(resolve(packages, name, 'THIRD_PARTY_NOTICES.md')),
        });
      }
    },
    writeBundle(options) {
      const file = resolve(options.dir ?? '', 'licenses/THIRD-PARTY-LICENSES.md');
      const text = readFileSync(file, 'utf8').replace(
        /^## (\S+) - (\S+) \(([^\n]+)\)\n\n(?=## |$)/gm,
        (section, name, version, license) => {
          const manifest = JSON.parse(readFileSync(resolve(process.cwd(), 'node_modules', name, 'package.json'), 'utf8'));
          const repository = typeof manifest.repository === 'string' ? manifest.repository : manifest.repository?.url;
          return `## ${name} - ${version} (${license})\n\nThe package ships no licence text; its package.json declares ${license}`
            + `${repository ? ` (source: ${repository})` : ''}.\n\n`;
        },
      );
      writeFileSync(file, text);
    },
  };
}

/**
 * One ES module plus its assets, with a stable entry name so the PHP side
 * needs no manifest:
 *   Resources/Public/Vite/docx-editor.js    import map: @webconsulting/docx-editor/editor.js
 *   Resources/Public/Vite/docx-editor.css
 *   Resources/Public/Vite/chunks/…          code the engine loads on demand (EMF/WMF, TIFF, shaper)
 *   Resources/Public/Vite/assets/…          harfbuzz.wasm and the @docx-editor.dev/fonts faces,
 *                                           fetched same-origin, relative to the module
 *   Resources/Public/Vite/licenses/…        licences of everything bundled, and of the fonts
 *
 * Vue templates are compiled here, and the bundle carries Vue's runtime-only
 * build: nothing is compiled in the browser, so the backend needs no
 * 'unsafe-eval' (only 'wasm-unsafe-eval' for the text shaper, and only on
 * the editor route). The TYPO3 modules under @webconsulting/docx-editor/ and
 * the label domains under ~labels/ come from the backend import map.
 */
export default defineConfig({
  base: '',
  publicDir: false,
  clearScreen: false,
  plugins: [vue(), packagedLicenses()],
  resolve: {
    alias: [{ find: /^vue$/, replacement: 'vue/dist/vue.runtime.esm-bundler.js' }],
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    cssCodeSplit: false,
    chunkSizeWarningLimit: 4000,
    outDir: resolve(process.cwd(), 'Resources/Public/Vite'),
    emptyOutDir: true,
    target: 'es2022',
    assetsInlineLimit: 0,
    modulePreload: false,
    license: { fileName: 'licenses/THIRD-PARTY-LICENSES.md' },
    rolldownOptions: {
      input: resolve(process.cwd(), 'Build/Sources/docx-editor.js'),
      external: [/^@webconsulting\/docx-editor\//, /^~labels\//],
      preserveEntrySignatures: 'exports-only',
      output: {
        entryFileNames: 'docx-editor.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (asset) => {
          const name = asset.names?.[0] ?? asset.name ?? '';
          return name.endsWith('.css') ? 'docx-editor.css' : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
});
