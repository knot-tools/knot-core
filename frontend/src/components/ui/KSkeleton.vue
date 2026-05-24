<script setup lang="ts">
/**
 * KSkeleton — Loading placeholder for content not yet available.
 *
 * Visual goal: a calm, two-tone block that pulses opacity gently.
 * We pulse opacity rather than translating a gradient so long
 * lists (50+ skeletons in a workflow listing) stay cheap on
 * low-end devices and respect prefers-reduced-motion (the
 * animation duration is controlled by --knot-duration via tokens).
 *
 * Usage:
 *   <KSkeleton />                                 default 16px line
 *   <KSkeleton width="60%" height="20px" />       custom dims
 *   <KSkeleton :rounded="false" />                square corners
 *   <KSkeleton variant="circle" size="40px" />    avatar
 */

withDefaults(
  defineProps<{
    /** Tailwind / CSS width (e.g. "60%", "180px"). Default: 100%. */
    width?: string;
    /** Tailwind / CSS height (e.g. "16px"). Default: 16px. */
    height?: string;
    /** Use the default rounded corners (radius-md token). */
    rounded?: boolean;
    /** Layout variant. */
    variant?: 'block' | 'circle' | 'text';
    /** Square edge for circle variant (overrides width/height). */
    size?: string;
    /** Aria label for screen readers. */
    label?: string;
  }>(),
  {
    width: '100%',
    height: '16px',
    rounded: true,
    variant: 'block',
    size: '32px',
    label: 'Loading',
  },
);
</script>

<template>
  <span
    class="k-block k-overflow-hidden"
    :class="{
      'k-rounded-knot-md': rounded && variant === 'block',
      'k-rounded-knot-sm': rounded && variant === 'text',
      'k-rounded-knot-pill': variant === 'circle',
      'k-animate-knot-skeleton': true,
    }"
    :style="{
      width: variant === 'circle' ? size : width,
      height: variant === 'circle' ? size : height,
      backgroundColor: 'var(--knot-skeleton-base)',
    }"
    role="status"
    :aria-label="label"
    aria-live="polite"
  />
</template>
