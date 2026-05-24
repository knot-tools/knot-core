/**
 * Vitest configuration for Knot frontend
 * Copyright (C) 2026 Knot
 * Licensed under GPL-3.0-or-later
 */

import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: [
      'src/**/__tests__/**/*.test.ts',
      'src/**/__tests__/**/*.spec.ts',
      'src/**/*.test.ts',
    ],
    setupFiles: ['./src/test/setup.ts'],
  },
});
