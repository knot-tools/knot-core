<!--
  Removable filter chips synced with marketplace hash query params.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';

import { useMarketplaceFilters } from '../../composables/useMarketplaceFilters';
import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';

const props = withDefaults(
  defineProps<{
    routePath?: string;
  }>(),
  { routePath: '/packs' },
);

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const routePath = computed(() => {
  const kind = ctx?.route.value.kind;
  const slug = ctx?.route.value.slug;
  if (kind === 'category' && slug) {
    return `/category/${slug}`;
  }
  if (kind === 'collection' && slug) {
    return `/collection/${slug}`;
  }
  if (kind === 'templates') {
    return '/templates';
  }
  if (kind === 'search') {
    return '/search';
  }
  return props.routePath;
});

const queryRef = computed(() => ctx?.route.value.query ?? {});

const { filters, syncHash, clearFilters } = useMarketplaceFilters({
  query: queryRef,
  navigate: (path) => {
    ctx?.navigate(path);
  },
  routePath,
});

const chips = computed(() => {
  const out: Array<{ key: 'category' | 'tier' | 'integration'; label: string }> = [];
  if (filters.value.category) {
    out.push({
      key: 'category',
      label: t('marketplace.filterChipCategory', { value: filters.value.category }),
    });
  }
  if (filters.value.tier) {
    out.push({
      key: 'tier',
      label: t('marketplace.filterChipTier', { value: filters.value.tier }),
    });
  }
  if (filters.value.integration) {
    out.push({
      key: 'integration',
      label: t('marketplace.filterChipIntegration', { value: filters.value.integration }),
    });
  }
  return out;
});

function removeChip(key: 'category' | 'tier' | 'integration'): void {
  syncHash({ [key]: '' });
}
</script>

<template>
  <div
    v-if="chips.length"
    class="k-flex k-flex-wrap k-items-center k-gap-2"
    role="group"
    :aria-label="t('marketplace.filterChipsAria')"
  >
    <button
      v-for="chip in chips"
      :key="chip.key"
      type="button"
      class="k-inline-flex k-items-center k-gap-1 k-rounded-knot-pill k-border k-border-knot-border k-bg-knot-surface-soft k-px-3 k-py-1 k-text-xs k-font-medium k-text-knot-text-muted"
      @click="removeChip(chip.key)"
    >
      {{ chip.label }}
      <X :size="12" aria-hidden="true" />
      <span class="k-sr-only">{{ t('marketplace.filterChipRemove') }}</span>
    </button>
    <button
      type="button"
      class="k-text-xs k-font-medium k-text-knot-primary hover:k-underline"
      @click="clearFilters()"
    >
      {{ t('marketplace.filterClearAll') }}
    </button>
  </div>
</template>
