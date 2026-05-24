<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed } from 'vue';
import { Repeat } from 'lucide-vue-next';

const props = defineProps<{ modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

const itemsPath = computed({
  get: () => String(props.modelValue?.itemsPath ?? ''),
  set: (v) => emit('update:modelValue', { ...props.modelValue, itemsPath: v }),
});
const realIteration = computed({
  get: () => Boolean(props.modelValue?.realIteration ?? true),
  set: (v) => emit('update:modelValue', { ...props.modelValue, realIteration: v }),
});
const maxIterations = computed({
  get: () => Number(props.modelValue?.maxIterations ?? 1000),
  set: (v) => emit('update:modelValue', { ...props.modelValue, maxIterations: v }),
});
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <Repeat :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">Loop / Iterate</span>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Items path</label>
      <input v-model="itemsPath" placeholder="$json.items" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Max iterations</label>
      <input type="number" v-model.number="maxIterations" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
    <label class="k-flex k-items-center k-gap-2">
      <input type="checkbox" v-model="realIteration" />
      <span class="k-text-xs k-text-knot-text-muted">Real iteration (re-run downstream nodes per item)</span>
    </label>
    <p class="k-text-[10px] k-text-knot-primary k-bg-knot-primary-soft k-px-2 k-py-1 k-rounded-knot-sm">
      Two outputs: <strong>iteration</strong> (one item/run) and <strong>done</strong> (after last).
    </p>
  </div>
</template>
