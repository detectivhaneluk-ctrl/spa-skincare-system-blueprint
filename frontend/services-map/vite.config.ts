import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../../system/public/assets/services-map');

export default defineConfig(({ command }) => {
  if (command === 'serve') {
    return {
      plugins: [react()],
    };
  }
  return {
    plugins: [react()],
    // Library IIFE runs in the browser; Rollup must not leave `process.env.NODE_ENV` in the bundle
    // or the script throws ReferenceError before `window.OlliraServicesMap` is assigned.
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
      outDir,
      emptyOutDir: true,
      lib: {
        entry: path.resolve(__dirname, 'src/embed.tsx'),
        name: 'OlliraServicesMap',
        formats: ['iife'],
        fileName: () => 'ollira-services-map.js',
      },
      rollupOptions: {
        output: {
          assetFileNames: 'ollira-services-map[extname]',
        },
      },
    },
  };
});
