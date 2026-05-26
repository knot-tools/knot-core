<!--
  Persistent marketplace shell — top chrome, main content slot + detail drawer.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, ref } from 'vue';

import MarketplaceBreadcrumb from './MarketplaceBreadcrumb.vue';
import MarketplaceDetailDrawer from './MarketplaceDetailDrawer.vue';
import MarketplaceFilterChips from './MarketplaceFilterChips.vue';
import MarketplaceSearchBar from './MarketplaceSearchBar.vue';
import MarketplaceSkeleton from './MarketplaceSkeleton.vue';
import MarketplaceTopBar from './MarketplaceTopBar.vue';
import MarketplaceUnavailable from './MarketplaceUnavailable.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';
import type { MarketplaceDrawerTarget } from './types';

defineProps<{
  loading?: boolean;
  unavailable?: boolean;
  unavailableMessage?: string;
  unavailableCode?: string | null;
  killSwitch?: boolean;
}>();

const emit = defineEmits<{
  retry: [];
}>();

const ctx = inject(knotMarketplaceBlockContextKey);

const drawerOpen = ref(false);
const drawerTarget = ref<MarketplaceDrawerTarget | null>(null);

const routeKind = computed(() => ctx?.route.value.kind ?? 'home');

const showBreadcrumb = computed(() => routeKind.value !== 'home');

const showFilters = computed(() => {
  const kind = routeKind.value;
  return kind === 'packs'
    || kind === 'templates'
    || kind === 'search'
    || kind === 'category'
    || kind === 'collection';
});

function openDrawer(target: MarketplaceDrawerTarget): void {
  drawerTarget.value = target;
  drawerOpen.value = true;
}

function closeDrawer(): void {
  drawerOpen.value = false;
  drawerTarget.value = null;
}

defineExpose({ openDrawer, closeDrawer });
</script>

<template>
  <div class="km-shell k-min-h-[600px] k-flex k-flex-col">
    <MarketplaceSkeleton v-if="loading" />
    <MarketplaceUnavailable
      v-else-if="unavailable"
      :message="unavailableMessage ?? ''"
      :code="unavailableCode"
      :kill-switch="killSwitch"
      @retry="emit('retry')"
    />
    <div v-else class="km-shell__main k-flex-1 k-p-6 k-max-w-[1280px] k-mx-auto k-w-full marketplace-host">
      <MarketplaceTopBar />
      <div class="k-mt-4 k-space-y-3">
        <MarketplaceSearchBar />
        <MarketplaceBreadcrumb v-if="showBreadcrumb" />
        <MarketplaceFilterChips v-if="showFilters" />
      </div>
      <div class="k-mt-6">
        <slot :open-drawer="openDrawer" />
      </div>
    </div>

    <MarketplaceDetailDrawer
      :open="drawerOpen"
      :target="drawerTarget"
      @close="closeDrawer"
    />
  </div>
</template>
