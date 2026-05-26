<!--
  Shared empty-state surface for Marketplace blocks (catalog tabs, grids).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
defineProps<{
  title: string;
  body?: string;
  actionLabel?: string;
  actionHref?: string;
}>();
</script>

<template>
  <div
    class="k-flex k-flex-col k-items-center k-justify-center k-text-center k-py-12 k-px-4 k-rounded-knot-md k-border k-border-dashed k-border-knot-border k-bg-knot-surface-soft/60"
    role="status"
  >
    <div
      v-if="$slots.illustration"
      class="k-mb-4 k-text-knot-text-soft [&>svg]:k-h-16 [&>svg]:k-w-16"
      aria-hidden="true"
    >
      <slot name="illustration" />
    </div>
    <h3 class="k-text-sm k-font-semibold k-text-knot-text">
      {{ title }}
    </h3>
    <p v-if="body" class="k-mt-1 k-max-w-md k-text-xs k-text-knot-text-muted">
      {{ body }}
    </p>
    <a
      v-if="actionLabel && actionHref"
      :href="actionHref"
      class="k-mt-4 k-inline-flex k-items-center k-justify-center k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-primary k-text-white hover:k-opacity-90 focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
      target="_blank"
      rel="noopener noreferrer"
    >
      {{ actionLabel }}
    </a>
  </div>
</template>
