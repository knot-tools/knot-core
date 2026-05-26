<!--
  Product emblem SVG keyed by pack/template slug (no remote assets).
  Covers a broad set of marketplace items: packs (pro, migration, enterprise),
  template categories (ai, dolibarr, communication, ecommerce, logic, security,
  reporting, crm, backup, accounting). Adding a new emblem? Add a slug rule
  in `variant` and the matching SVG path block below.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    slug: string;
    size?: number;
  }>(),
  { size: 40 },
);

type EmblemVariant =
  | 'pro-pack'
  | 'migration'
  | 'enterprise'
  | 'core'
  | 'ai'
  | 'dolibarr'
  | 'communication'
  | 'ecommerce'
  | 'logic'
  | 'security'
  | 'reporting'
  | 'crm'
  | 'backup'
  | 'accounting'
  | 'default';

const variant = computed<EmblemVariant>(() => {
  const s = props.slug.toLowerCase();
  if (s.includes('pro-pack') || s.includes('pro_pack')) return 'pro-pack';
  if (s.includes('migration')) return 'migration';
  if (s.includes('enterprise')) return 'enterprise';
  if (s.includes('core') || s.includes('knot-core')) return 'core';
  if (s.includes('ai') || s.includes('summari') || s.includes('gpt') || s.includes('openai')) return 'ai';
  if (s.includes('dolibarr') || s.includes('invoice') || s.includes('facture') || s.includes('propal')) return 'dolibarr';
  if (s.includes('mail') || s.includes('discord') || s.includes('slack') || s.includes('email') || s.includes('reminder')) return 'communication';
  if (s.includes('shop') || s.includes('order') || s.includes('stripe') || s.includes('prestashop') || s.includes('woocommerce')) return 'ecommerce';
  if (s.includes('cond') || s.includes('retry') || s.includes('logic') || s.includes('switch')) return 'logic';
  if (s.includes('security') || s.includes('audit') || s.includes('access')) return 'security';
  if (s.includes('report') || s.includes('analytic') || s.includes('chart') || s.includes('dashboard')) return 'reporting';
  if (s.includes('crm') || s.includes('lead') || s.includes('contact') || s.includes('tiers')) return 'crm';
  if (s.includes('backup') || s.includes('drive') || s.includes('export') || s.includes('archive')) return 'backup';
  if (s.includes('account') || s.includes('compta') || s.includes('ledger') || s.includes('tax')) return 'accounting';
  if (s.includes('stock') || s.includes('alert') || s.includes('low')) return 'reporting';
  return 'default';
});

interface Palette {
  bg: string;
  fg: string;
  accent: string;
}

const palette = computed<Palette>(() => {
  switch (variant.value) {
    case 'pro-pack':
      return { bg: '#eef2ff', fg: '#4338ca', accent: '#8b5cf6' };
    case 'migration':
      return { bg: '#ecfdf5', fg: '#047857', accent: '#10b981' };
    case 'enterprise':
      return { bg: 'var(--km-tier-enterprise-bg, #fdf6e3)', fg: 'var(--km-tier-enterprise-fg, #8a6a1d)', accent: 'var(--knot-color-gold, #d4a017)' };
    case 'core':
      return { bg: '#f0f9ff', fg: '#0369a1', accent: '#0ea5e9' };
    case 'ai':
      return { bg: '#fdf4ff', fg: '#7e22ce', accent: '#c026d3' };
    case 'dolibarr':
      return { bg: '#fff7ed', fg: '#9a3412', accent: '#fb923c' };
    case 'communication':
      return { bg: '#e0f2fe', fg: '#075985', accent: '#0284c7' };
    case 'ecommerce':
      return { bg: '#fefce8', fg: '#854d0e', accent: '#eab308' };
    case 'logic':
      return { bg: '#f1f5f9', fg: '#334155', accent: '#64748b' };
    case 'security':
      return { bg: '#fef2f2', fg: '#991b1b', accent: '#ef4444' };
    case 'reporting':
      return { bg: '#ecfeff', fg: '#155e75', accent: '#06b6d4' };
    case 'crm':
      return { bg: '#f0fdf4', fg: '#166534', accent: '#22c55e' };
    case 'backup':
      return { bg: '#f5f3ff', fg: '#5b21b6', accent: '#8b5cf6' };
    case 'accounting':
      return { bg: '#fff1f2', fg: '#9f1239', accent: '#f43f5e' };
    default:
      return { bg: '#f9fafc', fg: '#6366f1', accent: '#a5b4fc' };
  }
});
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 40 40"
    role="img"
    :aria-label="slug"
    class="k-shrink-0"
    style="border-radius: 12px"
  >
    <rect x="0" y="0" width="40" height="40" rx="12" :fill="palette.bg" />

    <!-- Pro Pack: layered cubes -->
    <g v-if="variant === 'pro-pack'">
      <path d="M20 9l9 5v6l-9 5-9-5v-6l9-5z" :fill="palette.accent" opacity="0.25" />
      <path d="M20 15l7 4-7 4-7-4 7-4z" :fill="palette.fg" />
      <path d="M20 23l7 4v3l-7 4-7-4v-3l7-4z" :fill="palette.fg" opacity="0.7" />
    </g>

    <!-- Migration: arrows up -->
    <g v-else-if="variant === 'migration'">
      <path d="M11 26l4-4v3h5v2h-5v3l-4-4zM23 14l4 4v-3h5v-2h-5v-3l-4 4z" :fill="palette.fg" />
      <circle cx="20" cy="20" r="2.5" :fill="palette.accent" />
    </g>

    <!-- Enterprise: building -->
    <g v-else-if="variant === 'enterprise'">
      <path d="M12 30V14h7v5h9v11H12z" :fill="palette.fg" />
      <rect x="14" y="17" width="2" height="2" :fill="palette.bg" />
      <rect x="14" y="22" width="2" height="2" :fill="palette.bg" />
      <rect x="21" y="22" width="2" height="2" :fill="palette.bg" />
      <rect x="25" y="22" width="2" height="2" :fill="palette.bg" />
      <rect x="21" y="26" width="2" height="2" :fill="palette.bg" />
      <rect x="25" y="26" width="2" height="2" :fill="palette.bg" />
    </g>

    <!-- Core: knot ring -->
    <g v-else-if="variant === 'core'">
      <circle cx="20" cy="20" r="9" fill="none" :stroke="palette.fg" stroke-width="2.5" />
      <circle cx="20" cy="20" r="3.5" :fill="palette.accent" />
    </g>

    <!-- AI: spark / star -->
    <g v-else-if="variant === 'ai'">
      <path d="M20 10l2.2 5.6 5.8 1.4-5.8 1.4L20 24l-2.2-5.6-5.8-1.4 5.8-1.4L20 10z" :fill="palette.fg" />
      <circle cx="29" cy="11" r="1.8" :fill="palette.accent" />
      <circle cx="12" cy="28" r="1.4" :fill="palette.accent" />
    </g>

    <!-- Dolibarr: invoice document -->
    <g v-else-if="variant === 'dolibarr'">
      <path d="M13 10h11l4 4v16H13V10z" :fill="palette.fg" opacity="0.9" />
      <path d="M24 10v4h4" :fill="palette.bg" />
      <rect x="16" y="19" width="9" height="1.5" :fill="palette.bg" />
      <rect x="16" y="22" width="9" height="1.5" :fill="palette.bg" />
      <rect x="16" y="25" width="6" height="1.5" :fill="palette.bg" />
    </g>

    <!-- Communication: chat bubble -->
    <g v-else-if="variant === 'communication'">
      <path d="M11 14h18a2 2 0 012 2v10a2 2 0 01-2 2h-10l-5 4v-4h-3a2 2 0 01-2-2V16a2 2 0 012-2z" :fill="palette.fg" />
      <circle cx="16" cy="21" r="1.3" :fill="palette.bg" />
      <circle cx="20" cy="21" r="1.3" :fill="palette.bg" />
      <circle cx="24" cy="21" r="1.3" :fill="palette.bg" />
    </g>

    <!-- E-commerce: cart -->
    <g v-else-if="variant === 'ecommerce'">
      <path d="M10 13h3l2 12h11l2-8H16" fill="none" :stroke="palette.fg" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      <circle cx="17" cy="29" r="2" :fill="palette.fg" />
      <circle cx="26" cy="29" r="2" :fill="palette.fg" />
    </g>

    <!-- Logic: branching arrows -->
    <g v-else-if="variant === 'logic'">
      <circle cx="14" cy="14" r="3" :fill="palette.accent" />
      <circle cx="14" cy="26" r="3" :fill="palette.fg" />
      <circle cx="26" cy="20" r="3" :fill="palette.fg" />
      <path d="M17 14h3a3 3 0 013 3v0M17 26h3a3 3 0 003-3v0" fill="none" :stroke="palette.fg" stroke-width="1.5" stroke-linecap="round" />
    </g>

    <!-- Security: shield -->
    <g v-else-if="variant === 'security'">
      <path d="M20 10l9 3v8c0 5-4 8-9 9-5-1-9-4-9-9v-8l9-3z" :fill="palette.fg" />
      <path d="M16 20l3 3 6-6" fill="none" :stroke="palette.bg" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
    </g>

    <!-- Reporting: bar chart -->
    <g v-else-if="variant === 'reporting'">
      <rect x="11" y="20" width="4" height="10" rx="1" :fill="palette.fg" />
      <rect x="18" y="14" width="4" height="16" rx="1" :fill="palette.fg" />
      <rect x="25" y="17" width="4" height="13" rx="1" :fill="palette.fg" opacity="0.7" />
      <circle cx="13" cy="13" r="2" :fill="palette.accent" />
    </g>

    <!-- CRM: people -->
    <g v-else-if="variant === 'crm'">
      <circle cx="14" cy="16" r="3" :fill="palette.fg" />
      <circle cx="26" cy="16" r="3" :fill="palette.fg" opacity="0.8" />
      <path d="M9 28c0-3 2.5-6 5-6s5 3 5 6M21 28c0-3 2.5-6 5-6s5 3 5 6" :fill="palette.fg" opacity="0.85" />
    </g>

    <!-- Backup: cloud + arrow up -->
    <g v-else-if="variant === 'backup'">
      <path d="M13 22a5 5 0 014.6-5 6 6 0 0111.4 2A4 4 0 0128 27H15a4 4 0 01-2-5z" :fill="palette.fg" />
      <path d="M20 21l-3 3h2v4h2v-4h2l-3-3z" :fill="palette.bg" />
    </g>

    <!-- Accounting: coin stack -->
    <g v-else-if="variant === 'accounting'">
      <ellipse cx="20" cy="14" rx="8" ry="2.5" :fill="palette.fg" />
      <path d="M12 14v4c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-4" :fill="palette.fg" opacity="0.85" />
      <path d="M12 19v4c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-4" :fill="palette.fg" opacity="0.7" />
      <path d="M12 24v4c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-4" :fill="palette.fg" opacity="0.55" />
    </g>

    <!-- Default: stylized knot -->
    <g v-else>
      <circle cx="20" cy="20" r="9" :fill="palette.accent" opacity="0.3" />
      <path d="M14 24l6-12 6 12h-4l-1-2h-2l-1 2h-4z" :fill="palette.fg" />
    </g>
  </svg>
</template>
