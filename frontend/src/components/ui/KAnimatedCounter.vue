<script setup lang="ts">
/**
 * KAnimatedCounter — Counts up to a target number on mount / when
 * the `value` prop changes.
 *
 * Visual goal: provides the small "this dashboard is alive" effect
 * for the bento grid metrics (active workflows, executions today,
 * success rate). Accessibility: the screen reader announces the
 * final value once, the intermediate frames are visual only.
 *
 * Performance: a single requestAnimationFrame loop per instance.
 * Easing matches the global --knot-ease cubic-bezier so multiple
 * counters animate in sync.
 *
 * Usage:
 *   <KAnimatedCounter :value="42" />
 *   <KAnimatedCounter :value="0.97" :decimals="2" suffix=" %" />
 */

import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    /** Target number. */
    value: number;
    /** Decimals to display (e.g. 2 for percentages). */
    decimals?: number;
    /** Animation duration in ms (default 800). */
    duration?: number;
    /** Optional prefix (e.g. "€", "$"). */
    prefix?: string;
    /** Optional suffix (e.g. " %", " ms"). */
    suffix?: string;
  }>(),
  {
    decimals: 0,
    duration: 800,
    prefix: '',
    suffix: '',
  },
);

const displayed = ref(0);
let rafId: number | null = null;

function easeOutCubic(t: number): number {
  return 1 - Math.pow(1 - t, 3);
}

function animateTo(target: number): void {
  const start = displayed.value;
  const delta = target - start;
  const startedAt = performance.now();
  // Honour reduced-motion: skip the animation entirely.
  const reduced =
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced || props.duration === 0) {
    displayed.value = target;
    return;
  }
  if (rafId !== null) cancelAnimationFrame(rafId);
  const tick = (now: number): void => {
    const elapsed = now - startedAt;
    const t = Math.min(1, elapsed / props.duration);
    displayed.value = start + delta * easeOutCubic(t);
    if (t < 1) {
      rafId = requestAnimationFrame(tick);
    } else {
      rafId = null;
    }
  };
  rafId = requestAnimationFrame(tick);
}

watch(
  () => props.value,
  (next) => animateTo(next),
  { immediate: true },
);

onBeforeUnmount(() => {
  if (rafId !== null) cancelAnimationFrame(rafId);
});

const formatted = computed(() => {
  const fixed = displayed.value.toFixed(props.decimals);
  // Use Intl.NumberFormat for thousand separators, but keep the
  // user's decimals exactly (toFixed already did the work).
  const [integerPart, fractional] = fixed.split('.');
  const grouped = Number(integerPart).toLocaleString();
  return fractional ? `${grouped}.${fractional}` : grouped;
});
</script>

<template>
  <span :aria-label="`${prefix}${value}${suffix}`">
    <span aria-hidden="true">{{ prefix }}{{ formatted }}{{ suffix }}</span>
  </span>
</template>
