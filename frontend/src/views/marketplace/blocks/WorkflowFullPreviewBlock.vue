<!--
  Larger workflow schematic for template/news editorial pages.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useId } from 'vue';
import { Workflow as WorkflowIcon } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { knotMarketplaceBlockContextKey } from '../contextKey';

import WorkflowMiniPreview from './WorkflowMiniPreview.vue';

const props = defineProps<{
  title?: string;
  /** Override node count when not on a template route */
  nodes?: number;
  edges?: number;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('WorkflowFullPreviewBlock requires Knot marketplace editorial context.');
}

const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.workflowPreviewTitle'));

function countFromDefinition(def: Record<string, unknown> | null | undefined): { nodes: number; edges: number } {
  const nodes = (def?.nodes as unknown[] | undefined)?.length ?? 0;
  const edges = (def?.edges as unknown[] | undefined)?.length ?? 0;
  return { nodes, edges };
}

const counts = computed(() => {
  if (typeof props.nodes === 'number' || typeof props.edges === 'number') {
    return { nodes: props.nodes ?? 0, edges: props.edges ?? 0 };
  }
  const slug = ctx.route.value.kind === 'template' ? ctx.route.value.slug : null;
  if (!slug) {
    return { nodes: 6, edges: 7 };
  }
  const tmpl = ctx.marketplace.value?.templates.find((r) => r.slug === slug);
  return countFromDefinition(tmpl?.definition as Record<string, unknown> | null | undefined);
});
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-overflow-hidden k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <div class="k-flex k-items-center k-gap-2 k-border-b k-border-knot-border k-bg-knot-surface-soft k-px-4 k-py-3">
      <WorkflowIcon :size="16" class="k-text-knot-primary" aria-hidden="true" />
      <h2 :id="headingId" class="k-text-sm k-font-bold k-text-knot-text">{{ heading }}</h2>
    </div>
    <div class="k-p-4">
      <WorkflowMiniPreview class="k-min-h-[140px]" :nodes="counts.nodes" :edges="counts.edges" />
    </div>
  </section>
</template>
