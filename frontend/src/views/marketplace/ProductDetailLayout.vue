<!--
  Full product detail page — hero, pricing, tabs, gallery, related row.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, onMounted, ref } from 'vue';
import { Package } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import type { MarketplaceProductPageSpec, MarketplaceProductTabSpec } from '../../lib/api';
import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';

import KProBadge from '../../components/ui/KProBadge.vue';
import BlockRenderer from './BlockRenderer.vue';
import MarketplaceRelatedRow from './MarketplaceRelatedRow.vue';
import ProductPricingTable from './ProductPricingTable.vue';
import ProductScreenshotGallery from './ProductScreenshotGallery.vue';
import ProductTabs from './ProductTabs.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('ProductDetailLayout requires Knot marketplace editorial context.');
}

const scrollRoot = ref<HTMLElement | null>(null);
const maxScrollDepth = ref(0);

const slug = computed(() => (ctx.route.value.kind === 'product' ? ctx.route.value.slug : null));

const pack = computed(() => {
  if (!slug.value) {
    return null;
  }
  return ctx.marketplace.value?.packs.find((p) => p.slug === slug.value) ?? null;
});

const pageSpec = computed<MarketplaceProductPageSpec | null>(() => {
  const s = slug.value;
  if (!s) {
    return null;
  }
  return ctx.marketplace.value?.editorial?.productPages?.[s] ?? null;
});

const heroTagline = computed(
  () => pageSpec.value?.hero?.tagline ?? pack.value?.description ?? '',
);

const heroVersion = computed(
  () => pageSpec.value?.hero?.version ?? pack.value?.version ?? null,
);

const heroAuthor = computed(() => pageSpec.value?.hero?.author ?? 'Knot Tools');

const tabs = computed((): MarketplaceProductTabSpec[] => {
  if (pageSpec.value?.tabs?.length) {
    return pageSpec.value.tabs;
  }
  return [
    {
      id: 'overview',
      kind: 'richtext',
      label: t('marketplace.tabOverview'),
      visible: true,
      body: pack.value?.description ?? '',
    },
    {
      id: 'screenshots',
      kind: 'screenshots',
      label: t('marketplace.galleryTitle'),
      visible: !!(pageSpec.value?.screenshots?.length),
      images: pageSpec.value?.screenshots ?? [],
    },
  ];
});

const pricingPlans = computed(() => {
  if (pageSpec.value?.pricing?.plans?.length) {
    return pageSpec.value.pricing.plans;
  }
  if (!pack.value) {
    return [];
  }
  if (pack.value.priceMonthlyCents == null && pack.value.priceYearlyCents == null) {
    return [];
  }
  return [
    {
      name: pack.value.label,
      price:
        pack.value.priceMonthlyCents != null
          ? t('marketplace.priceMonthly', { amount: (pack.value.priceMonthlyCents / 100).toFixed(0) })
          : t('marketplace.priceDash'),
      highlighted: true,
      features: [pack.value.description ?? t('marketplace.packDefaultDescription')],
    },
  ];
});

const showPricing = computed(() => {
  if (pageSpec.value?.pricing?.plans?.length) {
    return true;
  }
  return pricingPlans.value.length > 0;
});

const legacyLayout = computed(() => pageSpec.value?.layout ?? []);

const useLegacyBlocks = computed(
  () => legacyLayout.value.length > 0 && !pageSpec.value?.tabs?.length && !pageSpec.value?.hero,
);

function tierBadge(): string | null {
  const tier = pageSpec.value?.hero?.tier ?? pack.value?.tier;
  if (tier === 'pro') return t('marketplace.proBadgeLabel');
  if (tier === 'enterprise') return t('marketplace.enterpriseBadgeLabel');
  return null;
}

function onScroll(): void {
  const el = scrollRoot.value;
  if (!el) {
    return;
  }
  const depth = Math.round((el.scrollTop / Math.max(1, el.scrollHeight - el.clientHeight)) * 100);
  if (depth > maxScrollDepth.value) {
    maxScrollDepth.value = depth;
    if (depth >= 25 && depth % 25 === 0) {
      trackMarketplaceEvent('detail.scroll_depth', {
        slug: slug.value ?? '',
        depth,
        kind: 'product',
      });
    }
  }
}

onMounted(() => {
  if (slug.value) {
    trackMarketplaceEvent('product_page_visit', { slug: slug.value });
  }
});
</script>

<template>
  <div ref="scrollRoot" class="k-space-y-6" @scroll.passive="onScroll">
    <template v-if="!pack && !useLegacyBlocks">
      <p class="k-text-sm k-text-knot-text-muted">{{ t('marketplace.productBlockEmpty') }}</p>
    </template>

    <BlockRenderer
      v-else-if="useLegacyBlocks"
      :blocks="legacyLayout"
      :context="ctx"
    />

    <template v-else-if="pack">
      <header class="k-flex k-flex-col lg:k-flex-row k-gap-6">
        <div class="k-flex-1 k-space-y-3">
          <div class="k-flex k-items-start k-gap-4">
            <div class="k-h-14 k-w-14 k-rounded-knot-md k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
              <Package :size="24" />
            </div>
            <div class="k-min-w-0">
              <div class="k-flex k-flex-wrap k-items-center k-gap-2">
                <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ pack.label }}</h1>
                <KProBadge v-if="tierBadge()" :label="tierBadge() ?? 'Pro'" />
                <span
                  v-if="pack.popular"
                  class="k-text-[10px] k-font-bold k-uppercase k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-accent-soft k-text-knot-accent"
                >
                  {{ t('marketplace.signalPopular') }}
                </span>
              </div>
              <p v-if="heroTagline" class="k-mt-1 k-text-sm k-text-knot-text-muted">{{ heroTagline }}</p>
              <p class="k-mt-2 k-text-[11px] k-text-knot-text-soft">
                {{ heroAuthor }}
                <span v-if="heroVersion"> · v{{ heroVersion }}</span>
                <span v-if="pack.installCount != null"> · {{ t('marketplace.installCount', { count: pack.installCount }) }}</span>
              </p>
            </div>
          </div>
        </div>
        <div v-if="showPricing" class="lg:k-w-80 k-shrink-0">
          <ProductPricingTable
            :plans="pricingPlans"
            :highlight="pageSpec?.pricing?.highlight ?? pack.tier"
          />
        </div>
      </header>

      <ProductTabs :tabs="tabs" :product-slug="slug" />

      <ProductScreenshotGallery
        v-if="pageSpec?.screenshots?.length && !tabs.some((tab) => tab.kind === 'screenshots' && tab.visible !== false)"
        :items="pageSpec.screenshots"
      />

      <MarketplaceRelatedRow
        v-if="pageSpec?.related?.length"
        :title="t('marketplace.relatedTitle')"
        :slugs="pageSpec.related"
        kind="product"
        preview-mode
      />
    </template>
  </div>
</template>
