import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Self-contained bundle: Lit (TYPO3 glue) + React + docx-editor.
 * TYPO3 loads the hashed entry via PageRenderer / ViteAssetResolver.
 */
/** Add Heading 4 to eigenpal's fallback paragraph-style list (toolbar dropdown). */
function typo3Heading4FallbackPlugin() {
  const needle =
    '{styleId:"Heading3",name:"Heading 3",nameKey:"styles.heading3",type:"paragraph",priority:5,qFormat:true,fontSize:28,bold:true}]';
  const replacement =
    '{styleId:"Heading3",name:"Heading 3",nameKey:"styles.heading3",type:"paragraph",priority:5,qFormat:true,fontSize:28,bold:true},{styleId:"Heading4",name:"Heading 4",nameKey:"styles.heading4",type:"paragraph",priority:6,qFormat:true,fontSize:24,bold:true}]';

  return {
    name: 'typo3-docx-heading4-fallback',
    transform(code, id) {
      if (!id.includes('@eigenpal/docx-editor-react') || !id.includes('chunk-SW2JOSQG')) {
        return null;
      }
      if (!code.includes(needle) || code.includes('styles.heading4')) {
        return null;
      }
      return { code: code.replace(needle, replacement), map: null };
    },
  };
}

export default defineConfig({
  base: '',
  publicDir: false,
  clearScreen: false,
  plugins: [typo3Heading4FallbackPlugin(), react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    cssCodeSplit: false,
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
