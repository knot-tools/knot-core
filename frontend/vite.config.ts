/**
 * Vite configuration for Knot frontend bundle
 * Copyright (C) 2026 Knot
 * Licensed under GPL-3.0-or-later
 */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env': '{}',
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: path.resolve(__dirname, 'src/main.ts'),
      name: 'KnotApp',
      formats: ['iife'],
      fileName: () => 'knot-app.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'knot-app.css';
          }
          return 'assets/[name][extname]';
        },
        // Vite 8 / Rollup 5 already inlines dynamic imports in IIFE lib mode;
        // setting `inlineDynamicImports` here triggers a deprecation warning.
      },
    },
  },
  server: {
    port: 5173,
    strictPort: false,
    proxy: {
      '/api/knot': {
        target: process.env.KNOT_DEV_DOLIBARR_URL ?? 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
