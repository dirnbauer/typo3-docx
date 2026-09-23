import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { heading4FallbackPlugin } from './Build/vite/plugins/heading4-fallback.js';
import { styleDropdownHeadingsPlugin } from './Build/vite/plugins/style-dropdown-headings.js';
import { popoverAlignPlugin } from './Build/vite/plugins/popover-align.js';

/**
 * One self-contained bundle (React + eigenpal editor + the TYPO3 custom
 * element) with stable file names, so the PHP side needs no manifest:
 *   Resources/Public/Vite/docx-editor.js  (import map: @webconsulting/docx-editor/editor.js)
 *   Resources/Public/Vite/docx-editor.css
 * The TYPO3 modules under @webconsulting/docx-editor/ and the label domains
 * under ~labels/ are resolved by the backend import map at runtime and
 * therefore stay external.
 */
export default defineConfig({
  base: '',
  publicDir: false,
  clearScreen: false,
  plugins: [heading4FallbackPlugin(), styleDropdownHeadingsPlugin(), popoverAlignPlugin(), react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    cssCodeSplit: false,
    chunkSizeWarningLimit: 2500,
    outDir: resolve(process.cwd(), 'Resources/Public/Vite'),
    emptyOutDir: true,
    target: 'es2022',
    rolldownOptions: {
      input: resolve(process.cwd(), 'Build/Sources/docx-editor.js'),
      external: [/^@webconsulting\/docx-editor\//, /^~labels\//],
      output: {
        codeSplitting: false,
        entryFileNames: 'docx-editor.js',
        assetFileNames: 'docx-editor[extname]',
      },
    },
  },
});
