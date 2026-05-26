<!--
  Compact signal badge (tier, editorial badge, freshness).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    label: string;
    tone?: 'neutral' | 'info' | 'success' | 'warning' | 'premium';
  }>(),
  { tone: 'neutral' },
);

// Foreground uses the `-strong` variant of each color token to keep WCAG AA
// contrast ≥ 4.5:1 on the matching `-soft` background in both light and dark
// modes (`info-strong` foreground used to be `info`, which produced only
// 2.60:1 in light mode — see docs/design-system.md tier badge contrast table).
const toneClass = computed(() => {
  switch (props.tone) {
    case 'info':
      return 'k-bg-knot-info-soft k-text-knot-info-strong';
    case 'success':
      return 'k-bg-knot-success-soft k-text-knot-success-strong';
    case 'warning':
      return 'k-bg-knot-warning-soft k-text-knot-warning-strong';
    case 'premium':
      return 'k-bg-knot-premium-gold-soft k-text-knot-premium-gold-strong';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
});
</script>

<template>
  <span
    class="k-inline-flex k-items-center k-rounded-[var(--km-signal-radius)] k-px-2 k-py-0.5 k-text-[11px] k-font-semibold k-uppercase k-tracking-wide"
    :class="toneClass"
  >
    {{ label }}
  </span>
</template>
