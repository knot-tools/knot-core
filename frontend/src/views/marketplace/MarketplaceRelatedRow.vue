<!--
  Horizontal related / curated product or template row.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { ChevronRight, Library, ShoppingBag } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import type { MarketplacePack, MarketplaceTemplate } from '../../lib/api';

import { knotMarketplaceBlockContextKey } from './contextKey';

const props = defineProps<{
  title?: string;
  slugs?: string[];
  kind?: 'product' | 'template';
  /** Curated/related rows open drawer preview; browse grids navigate directly. */
  previewMode?: boolean;
}>();

const emit = defineEmits<{
  preview: [payload: { kind: 'product' | 'template'; slug: string }];
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('MarketplaceRelatedRow requires Knot marketplace editorial context.');
}
const blockCtx = ctx;

const items = computed<(MarketplacePack | MarketplaceTemplate)[]>(() => {
  const wanted = props.slugs ?? [];
  if (wanted.length === 0) {
    return [];
  }
  const source =
    props.kind === 'template'
      ? blockCtx.marketplace.value?.templates ?? []
      : blockCtx.marketplace.value?.packs ?? [];
  const out: (MarketplacePack | MarketplaceTemplate)[] = [];
  for (const slug of wanted) {
    const hit = source.find((row) => row.slug === slug);
    if (hit) {
      out.push(hit);
    }
  }
  return out;
});

function labelFor(row: MarketplacePack | MarketplaceTemplate): string {
  return row.label || row.slug;
}

function onCardClick(slug: string): void {
  const kind = props.kind ?? 'product';
  const previewMode = props.previewMode !== false;
  if (previewMode) {
    emit('preview', { kind, slug });
    blockCtx.openDrawerPreview?.({ kind, slug });
    return;
  }
  blockCtx.navigate(kind === 'template' ? `/template/${slug}` : `/product/${slug}`);
}
</script>

<template>
  <section class="k-space-y-3" role="region" :aria-label="title ?? t('marketplace.relatedTitle')">
    <header class="k-flex k-items-center k-justify-between">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ title ?? t('marketplace.relatedTitle') }}</h2>
    </header>
    <p v-if="items.length === 0" class="k-text-xs k-text-knot-text-muted">{{ t('marketplace.relatedEmpty') }}</p>
    <div
      v-else
      class="k-flex k-gap-3 k-overflow-x-auto k-pb-1 k-snap-x k-snap-mandatory"
    >
      <button
        v-for="row in items"
        :key="row.slug"
        type="button"
        class="k-min-w-[220px] k-snap-start k-shrink-0 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-text-left hover:k-border-knot-primary k-transition-colors k-shadow-knot-sm"
        @click="onCardClick(row.slug)"
        @mouseenter="props.kind === 'product' ? ctx.prefetchProductDetail?.(row.slug) : ctx.prefetchTemplateDetail?.(row.slug)"
      >
        <div class="k-flex k-items-start k-gap-3">
          <div class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
            <Library v-if="props.kind === 'template'" :size="16" />
            <ShoppingBag v-else :size="16" />
          </div>
          <div class="k-min-w-0">
            <h3 class="k-text-sm k-font-semibold k-text-knot-text k-truncate">{{ labelFor(row) }}</h3>
            <p class="k-mt-1 k-text-[11px] k-text-knot-text-muted k-line-clamp-2">
              {{ 'description' in row ? (row.description ?? '') : '' }}
            </p>
            <span class="k-mt-2 k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-text-knot-primary">
              {{ previewMode ? t('marketplace.relatedPreview') : t('marketplace.productBlockOpenDetail') }}
              <ChevronRight :size="12" />
            </span>
          </div>
        </div>
      </button>
    </div>
  </section>
</template>
