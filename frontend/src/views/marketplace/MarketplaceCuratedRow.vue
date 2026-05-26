<!--
  Horizontal curated collection row (editorial.home.collections or props).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

import { useMarketplaceCollections } from '../../composables/useMarketplaceCollections';
import { resolveCollectionCards } from '../../lib/marketplaceCollections';
import ProductEmblem from './ProductEmblem.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';
import type { MarketplaceCollectionSpec } from './types';

const props = defineProps<{
  collectionSlug?: string;
  title?: string;
  subtitle?: string;
  collections?: MarketplaceCollectionSpec[];
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const editorialRef = computed(() => ctx?.marketplace.value?.editorial ?? null);
const { collections: editorialCollections, collectionBySlug } =
  useMarketplaceCollections(editorialRef);

const activeCollection = computed(() => {
  if (props.collections?.length === 1) {
    return props.collections[0] ?? null;
  }
  if (props.collectionSlug) {
    return collectionBySlug(props.collectionSlug);
  }
  return editorialCollections.value[0] ?? null;
});

const cards = computed(() => {
  const collection = activeCollection.value;
  const catalog = ctx?.marketplace.value;
  if (!collection || !catalog) {
    return [];
  }
  return resolveCollectionCards(collection, catalog);
});

const rowLabel = computed(
  () => props.title ?? activeCollection.value?.title ?? activeCollection.value?.label ?? t('marketplace.curatedTitle'),
);

function openCollection(): void {
  const slug = activeCollection.value?.slug;
  if (slug) {
    ctx?.navigate(`/collection/${slug}`);
  }
}

function openCard(path: string): void {
  ctx?.navigate(path);
}

function scrollRow(direction: -1 | 1): void {
  const row = document.getElementById(`km-curated-scroll-${activeCollection.value?.slug ?? 'row'}`);
  if (!row) {
    return;
  }
  row.scrollBy({ left: direction * 280, behavior: 'smooth' });
}

function tierBadgeClass(tier: string): string {
  switch (tier) {
    case 'pro':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'enterprise':
      return 'k-bg-knot-color-premium-gold-soft k-text-knot-color-premium-text-on-gold';
    case 'beta':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'free':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'migration':
      return 'k-bg-knot-success-soft k-text-knot-success';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}
</script>

<style scoped>
.km-curated-card {
  transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease;
}
.km-curated-card:hover {
  transform: translateY(-2px);
  border-color: var(--knot-color-primary, #6366f1);
  box-shadow: var(--knot-shadow-md, 0 8px 20px rgba(15, 23, 42, 0.08));
}
@media (prefers-reduced-motion: reduce) {
  .km-curated-card {
    transition: none;
  }
  .km-curated-card:hover {
    transform: none;
  }
}
</style>

<template>
  <section
    v-if="activeCollection && cards.length"
    class="k-space-y-3"
    :aria-labelledby="`km-curated-${activeCollection.slug}`"
  >
    <div class="k-flex k-items-end k-justify-between k-gap-3">
      <div>
        <h2 :id="`km-curated-${activeCollection.slug}`" class="k-text-lg k-font-semibold">
          {{ rowLabel }}
        </h2>
        <p v-if="subtitle ?? activeCollection.subtitle" class="k-text-sm k-text-knot-text-muted">
          {{ subtitle ?? activeCollection.subtitle }}
        </p>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          type="button"
          class="k-hidden md:k-inline-flex k-items-center k-justify-center k-rounded-knot-sm k-border k-border-knot-border k-p-1.5 hover:k-bg-knot-surface-soft"
          :aria-label="t('marketplace.curatedScrollPrev')"
          @click="scrollRow(-1)"
        >
          <ChevronLeft :size="16" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="k-hidden md:k-inline-flex k-items-center k-justify-center k-rounded-knot-sm k-border k-border-knot-border k-p-1.5 hover:k-bg-knot-surface-soft"
          :aria-label="t('marketplace.curatedScrollNext')"
          @click="scrollRow(1)"
        >
          <ChevronRight :size="16" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-1 k-text-sm k-font-medium k-text-knot-primary hover:k-underline"
          @click="openCollection()"
        >
          {{ t('marketplace.curatedViewAll') }}
          <ChevronRight :size="14" aria-hidden="true" />
        </button>
      </div>
    </div>

    <div :id="`km-curated-scroll-${activeCollection.slug}`" class="km-curated-row">
      <button
        v-for="card in cards"
        :key="card.slug"
        type="button"
        class="km-card km-curated-card k-w-[260px] k-text-left focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        @click="openCard(card.path)"
      >
        <header class="k-flex k-items-start k-justify-between k-gap-2">
          <ProductEmblem :slug="card.slug" :size="36" />
          <span
            v-if="card.tier"
            class="k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider"
            :class="tierBadgeClass(card.tier)"
          >{{ card.tier }}</span>
        </header>
        <p class="k-mt-3 k-text-sm k-font-semibold k-text-knot-text k-line-clamp-1">{{ card.label }}</p>
        <p class="k-mt-1 k-line-clamp-2 k-text-xs k-text-knot-text-muted">{{ card.description }}</p>
        <footer
          v-if="card.kind === 'template' && card.nodes"
          class="k-mt-3 k-text-[11px] k-text-knot-text-soft"
        >
          {{ t('marketplace.nodesEdges', { nodes: card.nodes ?? 0, edges: card.edges ?? 0 }) }}
        </footer>
        <footer
          v-else-if="card.kind === 'pack' && card.category"
          class="k-mt-3 k-text-[11px] k-text-knot-text-soft k-uppercase k-tracking-wider"
        >
          {{ card.category }}
        </footer>
      </button>
    </div>
  </section>
</template>
