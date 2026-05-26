<!--
  Renders Knot Marketplace editorial block list (`BlockSpec`).
  Unknown block types are ignored; runtime errors remain local per block host.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, provide } from 'vue';

import MarketplaceBlockHost from './MarketplaceBlockHost.vue';

import type { BlockContext, BlockSpec } from './types';
import { knotMarketplaceBlockContextKey } from './contextKey';
import { resolveMarketplaceBlock } from './blocks/registry';

const props = defineProps<{
  blocks: BlockSpec[];
  context: BlockContext;
}>();

provide(knotMarketplaceBlockContextKey, props.context);

const visibleBlocks = computed(() =>
  (props.blocks ?? []).filter((b) => !!(b?.type && resolveMarketplaceBlock(b.type))),
);
</script>

<template>
  <div class="knot-marketplace-block-renderer k-space-y-6">
    <MarketplaceBlockHost
      v-for="(b, idx) in visibleBlocks"
      :key="b.id ?? `block-${idx}`"
      :spec="b"
    />
  </div>
</template>
