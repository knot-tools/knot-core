<!--
  Wrapper with per-block Vue error capturing for Marketplace editorial blocks.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onErrorCaptured, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import type { BlockSpec } from './types';

import { resolveMarketplaceBlock } from './blocks/registry';

const props = defineProps<{
  spec: BlockSpec;
}>();

const { t } = useI18n();

const Cmp = computed(() => resolveMarketplaceBlock(props.spec.type));

const fatalMessage = ref<string | null>(null);

onErrorCaptured((err: unknown) => {
  fatalMessage.value = err instanceof Error ? err.message : t('marketplace.blockRenderFailed');
  return false;
});

/**
 * Merge top-level editorial fields (title, body, …) with nested `props`
 * so JSON layout blocks and programmatic `{ type, id, props }` specs both work.
 */
const forwarded = computed(() => {
  const raw = props.spec as unknown as Record<string, unknown>;
  const nested =
    raw.props !== null && raw.props !== undefined && typeof raw.props === 'object' && !Array.isArray(raw.props)
      ? (raw.props as Record<string, unknown>)
      : {};
  const rest: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(raw)) {
    if (key === 'type' || key === 'id' || key === 'props') {
      continue;
    }
    rest[key] = value;
  }
  return { ...rest, ...nested };
});
</script>

<template>
  <section
    class="marketplace-editorial-block"
    :data-editorial-type="props.spec.type"
  >
    <div
      v-if="fatalMessage"
      role="alert"
      class="k-mb-4 k-rounded-knot-sm k-border k-border-knot-danger k-bg-knot-danger-soft k-text-knot-danger k-px-3 k-py-2 k-text-xs"
    >
      {{ fatalMessage }}
    </div>
    <component
      :is="Cmp!"
      v-else-if="Cmp"
      v-bind="forwarded"
    />
  </section>
</template>
