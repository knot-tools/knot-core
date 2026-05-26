<!--
  Tiny workflow schematic for marketplace cards / editorial previews.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { Workflow as WorkflowIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
  defineProps<{
    nodes?: number;
    edges?: number;
  }>(),
  { nodes: 0, edges: 0 },
);

const { t } = useI18n();

const chipCount = computed(() => Math.min(11, Math.max(props.nodes || 3, 3)));

const placeholders = computed(() => Array.from({ length: chipCount.value }, (_, i) => i + 1));
</script>

<template>
  <section
    class="k-bg-knot-surface-soft k-p-3 k-relative k-overflow-hidden"
    role="region"
    :aria-label="t('marketplace.miniPreviewLegend', { nodes, edges })"
  >
    <div class="k-flex k-items-center k-gap-2 k-text-[11px] k-font-semibold k-text-knot-text-muted">
      <WorkflowIcon :size="12" /> {{ t('marketplace.miniPreviewLegend', { nodes, edges }) }}
    </div>
    <div class="k-mt-3 k-grid k-gap-1" style="grid-template-columns: repeat(6, minmax(0, 1fr));">
      <span
        v-for="idx in placeholders"
        :key="idx"
        class="k-rounded-knot-pill k-h-7 k-bg-knot-primary-soft k-border k-border-knot-primary/30"
      />
    </div>
    <span class="k-pointer-events-none k-absolute k-inset-x-10 k-bottom-6 k-border-t k-border-knot-border k-border-dashed" />
    <span class="k-pointer-events-none k-text-[10px] k-text-knot-text-soft">{{ t('marketplace.miniPreviewHint') }}</span>
  </section>
</template>
