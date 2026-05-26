<!--
  Bundled marketplace promo illustration (no external CDN).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'banner' | 'hero';
    ariaHidden?: boolean;
  }>(),
  { variant: 'banner', ariaHidden: true },
);
</script>

<template>
  <svg
    class="marketplace-promo-art"
    :class="variant === 'hero' ? 'marketplace-promo-art--hero' : 'marketplace-promo-art--banner'"
    viewBox="0 0 320 200"
    xmlns="http://www.w3.org/2000/svg"
    :aria-hidden="ariaHidden"
    role="img"
  >
    <defs>
      <linearGradient id="mp-gold" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stop-color="var(--knot-color-premium-gold-soft, currentColor)" stop-opacity="0.35" />
        <stop offset="55%" stop-color="var(--knot-color-premium-gold, currentColor)" stop-opacity="0.75" />
        <stop offset="100%" stop-color="var(--knot-color-premium-gold-strong, currentColor)" />
      </linearGradient>
      <linearGradient id="mp-bg" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%" stop-color="currentColor" stop-opacity="0.06" />
        <stop offset="100%" stop-color="currentColor" stop-opacity="0.02" />
      </linearGradient>
      <filter id="mp-soft" x="-20%" y="-20%" width="140%" height="140%">
        <feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="currentColor" flood-opacity="0.18" />
      </filter>
    </defs>

    <rect x="8" y="8" width="304" height="184" rx="16" fill="url(#mp-bg)" stroke="currentColor" stroke-opacity="0.08" />

    <g filter="url(#mp-soft)">
      <rect x="36" y="48" width="72" height="44" rx="10" fill="url(#mp-gold)" />
      <rect x="124" y="28" width="72" height="44" rx="10" fill="var(--knot-color-surface, #fff)" stroke="var(--knot-color-premium-gold-strong, currentColor)" stroke-width="2" />
      <rect x="212" y="48" width="72" height="44" rx="10" fill="var(--knot-color-surface, #fff)" stroke="currentColor" stroke-opacity="0.15" stroke-width="1.5" />
      <rect x="80" y="118" width="72" height="44" rx="10" fill="var(--knot-color-surface, #fff)" stroke="currentColor" stroke-opacity="0.15" stroke-width="1.5" />
      <rect x="168" y="118" width="72" height="44" rx="10" fill="url(#mp-gold)" />
    </g>

    <g stroke="var(--knot-color-premium-gold-strong, currentColor)" stroke-width="2.5" fill="none" stroke-linecap="round">
      <path d="M108 70 L124 50" />
      <path d="M196 50 L212 70" />
      <path d="M72 92 L116 118" />
      <path d="M152 72 L204 118" />
      <path d="M152 140 L168 140" />
    </g>

    <g fill="var(--knot-color-premium-text-on-gold, currentColor)" font-family="system-ui, sans-serif" font-size="11" font-weight="700">
      <text x="72" y="75" text-anchor="middle">Core</text>
      <text x="204" y="75" text-anchor="middle">Pack</text>
      <text x="204" y="145" text-anchor="middle">Flow</text>
    </g>
    <g fill="currentColor" fill-opacity="0.55" font-family="system-ui, sans-serif" font-size="10" font-weight="600">
      <text x="160" y="55" text-anchor="middle">Pro</text>
      <text x="116" y="145" text-anchor="middle">CRM</text>
    </g>
  </svg>
</template>

<style scoped>
.marketplace-promo-art {
  color: var(--knot-color-text-muted, #64748b);
  width: 100%;
  height: 100%;
  display: block;
}

.marketplace-promo-art--banner {
  min-height: 7rem;
  max-height: 9rem;
}

.marketplace-promo-art--hero {
  min-height: 10rem;
  max-height: 12rem;
}

@media (prefers-reduced-motion: no-preference) {
  .marketplace-promo-art :deep(rect:nth-child(odd)) {
    animation: mp-float 6s ease-in-out infinite;
  }
}

@keyframes mp-float {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-2px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .marketplace-promo-art :deep(*) {
    animation: none !important;
  }
}
</style>
