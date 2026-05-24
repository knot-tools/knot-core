<script setup lang="ts">
/**
 * KGlassCard — Subtle glassmorphism v2 surface.
 *
 * Visual goal: a card that sits cleanly on top of busy backgrounds
 * (mesh gradients, dashboards, the editor canvas) without the heavy
 * 2020-era frosted-glass look. Backdrop blur is gentle (18px) and
 * the surface keeps a high opacity (72-86 %), so text contrast
 * stays readable for accessibility.
 *
 * Falls back gracefully on browsers without backdrop-filter — the
 * `backgroundColor` is set to the same surface variable so the
 * card stays visible (just less "glassy").
 *
 * Usage:
 *   <KGlassCard>...</KGlassCard>             default subtle blur
 *   <KGlassCard variant="strong">...</KGlassCard>  modal dialog
 */

withDefaults(
  defineProps<{
    /** "subtle" (panel overlay) or "strong" (modal). */
    variant?: 'subtle' | 'strong';
    /** Optional aria role (e.g. "dialog"). */
    role?: string;
  }>(),
  {
    variant: 'subtle',
  },
);
</script>

<template>
  <div
    class="k-rounded-knot-md k-shadow-knot-sm k-border"
    :class="{
      'k-backdrop-blur-knot': true,
    }"
    :style="{
      backgroundColor:
        variant === 'strong'
          ? 'var(--knot-glass-bg-strong)'
          : 'var(--knot-glass-bg)',
      borderColor: 'var(--knot-glass-border)',
    }"
    :role="role"
  >
    <slot />
  </div>
</template>
