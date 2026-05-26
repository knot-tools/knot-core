<!--
  Product (pack) spotlight — grid or single detail card from catalog.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { ChevronRight, ShoppingBag } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import type { MarketplacePack, MarketplaceTier } from '../../../lib/api';
import KProBadge from '../../../components/ui/KProBadge.vue';

import { knotMarketplaceBlockContextKey } from '../contextKey';
import type { BlockContext } from '../types';

const props = withDefaults(
  defineProps<{
    /** Max cards on home grid; ignored in `detail` mode. */
    limit?: number;
    mode?: 'grid' | 'detail';
  }>(),
  { limit: 9, mode: 'grid' },
);

const { t } = useI18n();

const injectedProductCtx = inject(knotMarketplaceBlockContextKey);
if (!injectedProductCtx) {
  throw new Error('ProductCardBlock requires Knot marketplace editorial context.');
}
const ctx: BlockContext = injectedProductCtx;

const packTotal = computed(() => ctx.marketplace.value?.packs.length ?? 0);

const packs = computed<MarketplacePack[]>(() => {
  const origin = ctx.marketplace.value?.packs ?? [];
  if (props.mode === 'detail' && ctx.route.value.kind === 'product' && ctx.route.value.slug) {
    const match = origin.find((p) => p.slug === ctx.route.value.slug);
    return match ? [match] : [];
  }
  return origin.slice(0, Math.max(0, props.limit));
});

function tierLabel(tier: MarketplaceTier): string | null {
  if (tier === 'pro') return t('marketplace.proBadgeLabel');
  if (tier === 'enterprise') return t('marketplace.enterpriseBadgeLabel');
  return null;
}

function openDetail(pack: MarketplacePack): void {
  ctx.navigate(`/product/${pack.slug}`);
}

function tierTone(tier: MarketplaceTier): string {
  switch (tier) {
    case 'free':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'beta':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'pro':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'enterprise':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}
</script>

<template>
  <section class="k-space-y-4" role="region" :aria-label="t('marketplace.productBlockTitle')">
    <header class="k-flex k-items-center k-justify-between">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('marketplace.productBlockTitle') }}</h2>
      <span class="k-text-[11px] k-text-knot-text-muted">{{ packs.length }} / {{ packTotal }}</span>
    </header>

    <p v-if="packs.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.productBlockEmpty') }}</p>

    <div v-else class="k-grid k-gap-4 sm:k-grid-cols-2 lg:k-grid-cols-3">
      <article
        v-for="pack in packs"
        :key="pack.slug"
        class="km-card km-card--tier-default k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-space-y-2 k-shadow-knot-sm hover:k-border-knot-primary k-transition-colors k-cursor-pointer"
        role="link"
        tabindex="0"
        @click="openDetail(pack)"
        @keydown.enter.prevent="openDetail(pack)"
        @mouseenter="ctx.prefetchProductDetail?.(pack.slug)"
      >
        <div class="k-flex k-items-start k-justify-between k-gap-3">
          <div class="k-flex k-items-center k-gap-2">
            <div class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
              <ShoppingBag :size="16" />
            </div>
            <div>
              <div class="k-flex k-flex-wrap k-items-center k-gap-2">
                <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ pack.label }}</h3>
                <KProBadge v-if="tierLabel(pack.tier)" :label="tierLabel(pack.tier) ?? 'Pro'" />
              </div>
              <code class="k-text-[10px] k-text-knot-text-soft k-font-mono">{{ pack.slug }}</code>
            </div>
          </div>
          <span
            :class="['k-shrink-0 k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase', tierTone(pack.tier)]"
          >
            {{ pack.tier }}
          </span>
        </div>
        <p class="k-text-xs k-text-knot-text-muted k-line-clamp-3">{{ pack.description ?? t('marketplace.packDefaultDescription') }}</p>

        <button
          v-if="props.mode !== 'detail'"
          type="button"
          class="k-mt-1 k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
          @click.stop="openDetail(pack)"
        >
          {{ t('marketplace.productBlockOpenDetail') }}
          <ChevronRight :size="12" />
        </button>
      </article>
    </div>
  </section>
</template>
