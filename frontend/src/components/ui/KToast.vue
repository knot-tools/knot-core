<script setup lang="ts">
/**
 * KToast — Single toast notification card (level-aware).
 *
 * Visual goal: a card with a coloured left rail and a tinted bg.
 * Each level (success / warning / danger / info) reads at a glance
 * thanks to the rail + matching icon, and stays accessible for
 * users who can't easily distinguish hue.
 *
 * Self-dismissable: emits `dismiss` after `duration` ms unless
 * `sticky` is set. The parent owns the toast list; this component
 * only renders one toast.
 *
 * Usage:
 *   <KToast level="success" title="Workflow activated" />
 *   <KToast level="danger" title="Validation failed"
 *           body="Node 'set_input' is missing a value." />
 */

import { onMounted, onBeforeUnmount } from 'vue';

const props = withDefaults(
  defineProps<{
    level?: 'success' | 'warning' | 'danger' | 'info';
    title: string;
    body?: string;
    /** Auto-dismiss timer (ms). 0 disables. */
    duration?: number;
    /** Disable auto-dismiss entirely. */
    sticky?: boolean;
  }>(),
  {
    level: 'info',
    duration: 4500,
  },
);

const emit = defineEmits<{ (e: 'dismiss'): void }>();

let timerId: ReturnType<typeof setTimeout> | null = null;
onMounted(() => {
  if (!props.sticky && props.duration > 0) {
    timerId = setTimeout(() => emit('dismiss'), props.duration);
  }
});
onBeforeUnmount(() => {
  if (timerId !== null) clearTimeout(timerId);
});

const railColor = {
  success: 'var(--knot-color-success)',
  warning: 'var(--knot-color-warning)',
  danger: 'var(--knot-color-danger)',
  info: 'var(--knot-color-info)',
}[props.level];

const bgVar = `var(--knot-toast-${props.level}-bg)`;
const borderVar = `var(--knot-toast-${props.level}-border)`;
</script>

<template>
  <div
    class="k-flex k-items-start k-gap-3 k-px-4 k-py-3 k-rounded-knot-md k-border k-shadow-knot-sm k-relative k-overflow-hidden"
    :style="{
      backgroundColor: bgVar,
      borderColor: borderVar,
      paddingLeft: '14px',
    }"
    role="status"
    aria-live="polite"
  >
    <div
      aria-hidden="true"
      class="k-absolute k-left-0 k-top-0 k-bottom-0 k-w-1"
      :style="{ backgroundColor: railColor }"
    />
    <div class="k-flex-1 k-min-w-0">
      <p class="k-m-0 k-text-sm k-font-semibold k-text-knot">
        {{ title }}
      </p>
      <p
        v-if="body"
        class="k-m-0 k-mt-1 k-text-sm k-text-knot-text-muted k-leading-relaxed"
      >
        {{ body }}
      </p>
    </div>
    <button
      type="button"
      class="k-text-knot-text-muted hover:k-text-knot k-text-xl k-leading-none k-px-1"
      :aria-label="'Dismiss notification'"
      @click="$emit('dismiss')"
    >
      &times;
    </button>
  </div>
</template>
