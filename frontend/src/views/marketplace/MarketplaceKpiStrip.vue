<!--
  Compact KPI strip for marketplace discovery (packs, templates, news counts).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { Layers, Newspaper, Package } from 'lucide-vue-next';

import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';

const props = defineProps<{
  items?: Array<{ id: string; label: string; value: string | number; hint?: string }>;
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const metrics = computed(() => {
  if (props.items?.length) {
    return props.items;
  }
  const catalog = ctx?.marketplace.value;
  return [
    {
      id: 'packs',
      label: t('marketplace.kpiPacks'),
      value: catalog?.packs.length ?? 0,
      hint: t('marketplace.navPacks'),
    },
    {
      id: 'templates',
      label: t('marketplace.kpiTemplates'),
      value: catalog?.templates.length ?? 0,
      hint: t('marketplace.navTemplates'),
    },
    {
      id: 'news',
      label: t('marketplace.kpiNews'),
      value: t('marketplace.kpiNewsValue'),
      hint: t('marketplace.navNews'),
    },
  ];
});

const icons: Record<string, typeof Package> = {
  packs: Package,
  templates: Layers,
  news: Newspaper,
};
</script>

<template>
  <ul
    class="k-grid k-gap-3 sm:k-grid-cols-3"
    role="list"
    :aria-label="t('marketplace.kpiStripAria')"
  >
    <li
      v-for="metric in metrics"
      :key="metric.id"
      class="km-card k-flex k-items-start k-gap-3"
    >
      <span
        class="k-inline-flex k-h-9 k-w-9 k-items-center k-justify-center k-rounded-knot-md k-bg-knot-primary-soft k-text-knot-primary"
        aria-hidden="true"
      >
        <component :is="icons[metric.id] ?? Package" :size="16" />
      </span>
      <div>
        <p class="k-text-xs k-font-medium k-uppercase k-tracking-wide k-text-knot-text-soft">
          {{ metric.label }}
        </p>
        <p class="k-text-xl k-font-semibold k-text-knot-text">{{ metric.value }}</p>
        <p v-if="metric.hint" class="k-text-xs k-text-knot-text-muted">{{ metric.hint }}</p>
      </div>
    </li>
  </ul>
</template>
