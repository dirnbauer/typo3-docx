import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Self-contained bundle: Lit (TYPO3 glue) + React + docx-editor.
 * TYPO3 loads the hashed entry via PageRenderer / ViteAssetResolver.
 */
export default defineConfig({
  base: '',
  publicDir: false,
  clearScreen: false,
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    chunkSizeWarningLimit: 2500,
    manifest: 'manifest.json',
    outDir: resolve(process.cwd(), 'Resources/Public/Vite'),
    emptyOutDir: true,
    target: 'es2020',
    rollupOptions: {
      input: {
        'Build/Sources/docx-editor.js': resolve(process.cwd(), 'Build/Sources/docx-editor.js'),
      },
      output: {
        inlineDynamicImports: true,
        entryFileNames: 'docx-editor.js',
      },
    },
  },
});
