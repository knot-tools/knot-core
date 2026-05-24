/**
 * Tailwind configuration for Knot frontend
 * Copyright (C) 2026 Knot
 * Licensed under GPL-3.0-or-later
 *
 * Mirrors css/knot-tokens.css. Single source of truth: Knot CSS tokens.
 */

import type { Config } from 'tailwindcss';
import animate from 'tailwindcss-animate';

const config: Config = {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  darkMode: 'media',
  prefix: 'k-',
  corePlugins: {
    preflight: false,
  },
  theme: {
    extend: {
      colors: {
        knot: {
          bg: 'var(--knot-color-bg)',
          surface: 'var(--knot-color-surface)',
          'surface-soft': 'var(--knot-color-surface-soft)',
          border: 'var(--knot-color-border)',
          'border-strong': 'var(--knot-color-border-strong)',
          text: 'var(--knot-color-text)',
          'text-muted': 'var(--knot-color-text-muted)',
          'text-soft': 'var(--knot-color-text-soft)',
          primary: 'var(--knot-color-primary)',
          'primary-soft': 'var(--knot-color-primary-soft)',
          'primary-strong': 'var(--knot-color-primary-strong)',
          accent: 'var(--knot-color-accent)',
          'accent-soft': 'var(--knot-color-accent-soft)',
          success: 'var(--knot-color-success)',
          'success-soft': 'var(--knot-color-success-soft)',
          warning: 'var(--knot-color-warning)',
          'warning-soft': 'var(--knot-color-warning-soft)',
          danger: 'var(--knot-color-danger)',
          'danger-soft': 'var(--knot-color-danger-soft)',
          info: 'var(--knot-color-info)',
          'info-soft': 'var(--knot-color-info-soft)',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['JetBrains Mono', 'SF Mono', 'Menlo', 'Consolas', 'monospace'],
      },
      borderRadius: {
        'knot-sm': 'var(--knot-radius-sm)',
        'knot-md': 'var(--knot-radius-md)',
        'knot-lg': 'var(--knot-radius-lg)',
        'knot-pill': 'var(--knot-radius-pill)',
      },
      boxShadow: {
        'knot-xs': 'var(--knot-shadow-xs)',
        'knot-sm': 'var(--knot-shadow-sm)',
        'knot-md': 'var(--knot-shadow-md)',
        'knot-lg': 'var(--knot-shadow-lg)',
      },
      backgroundImage: {
        'knot-hero': 'var(--knot-gradient-hero)',
        'knot-soft': 'var(--knot-gradient-soft)',
        'knot-success': 'var(--knot-gradient-success)',
        'knot-warning': 'var(--knot-gradient-warning)',
        'knot-danger': 'var(--knot-gradient-danger)',
        'knot-mesh': 'var(--knot-mesh-bg)',
        'knot-noise': 'var(--knot-noise)',
        'knot-pro': 'var(--knot-pro-badge-bg)',
      },
      backdropBlur: {
        knot: 'var(--knot-glass-blur)',
      },
      transitionTimingFunction: {
        knot: 'cubic-bezier(0.22, 1, 0.36, 1)',
      },
      transitionDuration: {
        knot: '240ms',
      },
      keyframes: {
        'knot-skeleton-pulse': {
          '0%, 100%': { opacity: '1' },
          '50%': { opacity: '0.55' },
        },
        'knot-ring-pulse': {
          '0%': { boxShadow: '0 0 0 0 rgba(99, 102, 241, 0.45)' },
          '100%': { boxShadow: '0 0 0 14px rgba(99, 102, 241, 0)' },
        },
      },
      animation: {
        'knot-skeleton': 'knot-skeleton-pulse 1.6s ease-in-out infinite',
        'knot-ring': 'knot-ring-pulse 1.8s var(--knot-ease) infinite',
      },
    },
  },
  plugins: [animate],
};

export default config;
