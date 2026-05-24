<!--
  TemplatesView — DEPRECATED in V2.5.0c.
  The standalone templates screen was merged into MarketplaceView
  (Marketplace v2: 3 unified tabs Packs / Templates / Migration).
  This file remains so the legacy ?mode=templates URL keeps redirecting
  bookmarks to the new location instead of 404'ing.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';

const baseUrl = computed(
  () =>
    (typeof window !== 'undefined'
      && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL)
    || '',
);

const redirectLabel = computed(() =>
  isMarketplaceUiEnabled()
    ? 'Redirection vers la Marketplace…'
    : 'Redirection vers le tableau de bord…',
);

onMounted(() => {
  const target = isMarketplaceUiEnabled()
    ? `${baseUrl.value}?mode=marketplace`
    : `${baseUrl.value}?mode=dashboard`;
  window.location.replace(target);
});
</script>

<template>
  <div class="k-p-12 k-flex k-items-center k-justify-center k-text-knot-text-muted k-text-sm k-gap-2">
    <Loader2 :size="14" class="k-animate-spin" /> {{ redirectLabel }}
  </div>
</template>
