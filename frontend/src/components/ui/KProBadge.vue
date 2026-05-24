<script setup lang="ts">
/**
 * KProBadge — Marks a connector / template / feature as Pro Pack.
 *
 * Visual goal: a single source of truth for the "this is Pro" marker.
 * Two variants:
 *  - "badge"  : small pill rendered inline next to a name
 *  - "lock"   : full overlay locked-state for an entire card / option
 *
 * The badge uses the brand gradient (--knot-pro-badge-bg) so it
 * stays distinct from status colours (success/warning/danger).
 *
 * Usage:
 *   <KProBadge label="Pro" />
 *   <KProBadge variant="lock" label="Pro" tooltip="Active your Pro Pack license" />
 */

withDefaults(
  defineProps<{
    /** Badge or full-overlay lock. */
    variant?: 'badge' | 'lock';
    /** Visible label. */
    label?: string;
    /** Optional tooltip / aria-label. */
    tooltip?: string;
  }>(),
  {
    variant: 'badge',
    label: 'Pro',
  },
);
</script>

<template>
  <span
    v-if="variant === 'badge'"
    class="k-inline-flex k-items-center k-gap-1 k-px-2 k-py-0.5 k-text-xs k-font-semibold k-rounded-knot-pill k-shadow-knot-xs"
    :style="{
      background: 'var(--knot-pro-badge-bg)',
      color: 'var(--knot-pro-badge-fg)',
    }"
    :aria-label="tooltip ?? label"
    :title="tooltip"
  >
    <svg
      width="10"
      height="10"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        d="M3 6L8 1L13 6L11 13H5L3 6Z"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linejoin="round"
      />
    </svg>
    <span>{{ label }}</span>
  </span>

  <div
    v-else
    class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-text-sm k-rounded-knot-md k-border"
    :style="{
      backgroundColor: 'var(--knot-pro-lock-bg)',
      borderColor: 'var(--knot-pro-lock-border)',
      color: 'var(--knot-color-primary-strong)',
    }"
    :aria-label="tooltip ?? `Locked — ${label}`"
    :title="tooltip"
  >
    <svg
      width="14"
      height="14"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <rect
        x="3"
        y="7"
        width="10"
        height="7"
        rx="1.5"
        stroke="currentColor"
        stroke-width="1.5"
      />
      <path
        d="M5 7V5a3 3 0 016 0v2"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
      />
    </svg>
    <span class="k-font-medium">{{ label }}</span>
  </div>
</template>
