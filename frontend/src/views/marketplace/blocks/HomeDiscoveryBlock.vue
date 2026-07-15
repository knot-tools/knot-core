<!--
  Home discovery orchestrator — spotlight, KPI strip, curated rows, "explore more" CTA.
  Copyright (C) 2026 Knot — GPL-3.0-or-later

  Intentionally lean: spotlight + KPI + curated collections + explore CTA.
  Browse all (packs/templates/bundled) lives on its own routes (/packs, /templates)
  and the ecosystem story lives on knot.tools — duplicating both here drowns the
  discovery surface (cf. UX feedback May 2026).
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Layers, Newspaper, Package } from 'lucide-vue-next';

import { useMarketplaceCollections } from '../../../composables/useMarketplaceCollections';
import BannerBlock from './BannerBlock.vue';
import MarketplaceCuratedRow from '../MarketplaceCuratedRow.vue';
import MarketplaceKpiStrip from '../MarketplaceKpiStrip.vue';
import MarketplaceSpotlight from '../MarketplaceSpotlight.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

const props = withDefaults(
  defineProps<{
    showBanner?: boolean;
  }>(),
  { showBanner: true },
);

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('HomeDiscoveryBlock requires Knot marketplace editorial context.');
}

const homeEditorial = computed(() => {
  const home = ctx.marketplace.value?.editorial?.home;
  if (home && typeof home === 'object' && !Array.isArray(home)) {
    return home;
  }
  return null;
});

const spotlightItems = computed(() => homeEditorial.value?.spotlight?.items ?? []);

const editorialRef = computed(() => ctx.marketplace.value?.editorial ?? null);
const { collections } = useMarketplaceCollections(editorialRef);

const showPromoBanner = computed(
  () => props.showBanner && spotlightItems.value.length === 0,
);

const exploreLinks = computed(() => [
  {
    id: 'packs',
    label: t('marketplace.exploreAllPacks'),
    href: '/packs',
    icon: Package,
  },
  {
    id: 'templates',
    label: t('marketplace.exploreAllTemplates'),
    href: '/templates',
    icon: Layers,
  },
  {
    id: 'news',
    label: t('marketplace.exploreNews'),
    href: '/news',
    icon: Newspaper,
  },
]);

function navigateExplore(href: string): void {
  ctx?.navigate(href);
}
</script>

<template>
  <div
    class="k-space-y-10"
    data-testid="marketplace-home-discovery"
    data-editorial-type="home_discovery"
  >
    <BannerBlock v-if="showPromoBanner" :show-stale="true" />

    <section
      v-if="spotlightItems.length"
      class="k-space-y-4"
      role="region"
      data-testid="marketplace-spotlight"
      :aria-label="homeEditorial?.spotlight?.title ?? t('marketplace.spotlightTitle')"
    >
      <header class="k-flex k-items-end k-justify-between k-gap-3">
        <div class="k-space-y-1">
          <p class="k-text-xs k-font-bold k-uppercase k-tracking-[0.18em] k-text-knot-text-soft">
            {{ t('marketplace.spotlightEyebrow') }}
          </p>
          <h2 class="k-text-2xl k-font-semibold k-text-knot-text">
            {{ homeEditorial?.spotlight?.title ?? t('marketplace.spotlightTitle') }}
          </h2>
        </div>
      </header>
      <div
        :class="[
          'k-grid k-gap-4',
          spotlightItems.length === 1
            ? 'k-grid-cols-1'
            : spotlightItems.length === 2
              ? 'md:k-grid-cols-2'
              : 'md:k-grid-cols-2 xl:k-grid-cols-3',
        ]"
      >
        <MarketplaceSpotlight
          v-for="(item, idx) in spotlightItems"
          :key="`${item.slug ?? 'item'}-${idx}`"
          :slug="item.slug"
          :kind="item.kind === 'template' ? 'template' : 'pack'"
          :subtitle="item.tagline"
          :badge="item.accent"
          :ctas="item.ctas"
        />
      </div>
    </section>

    <MarketplaceKpiStrip />

    <MarketplaceCuratedRow
      v-for="collection in collections"
      :key="collection.slug"
      :collection-slug="collection.slug"
      :title="collection.label ?? collection.title"
    />

    <section
      class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-6 k-space-y-4"
      role="region"
      :aria-label="t('marketplace.exploreTitle')"
    >
      <div class="k-space-y-1">
        <p class="k-text-xs k-font-bold k-uppercase k-tracking-[0.18em] k-text-knot-text-soft">
          {{ t('marketplace.exploreEyebrow') }}
        </p>
        <h2 class="k-text-lg k-font-semibold k-text-knot-text">
          {{ t('marketplace.exploreTitle') }}
        </h2>
        <p class="k-text-sm k-text-knot-text-muted">
          {{ t('marketplace.exploreBody') }}
        </p>
      </div>
      <div class="k-grid k-gap-3 sm:k-grid-cols-3">
        <button
          v-for="link in exploreLinks"
          :key="link.id"
          type="button"
          class="k-group k-flex k-items-center k-gap-3 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-text-left k-transition-shadow hover:k-shadow-knot-sm focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
          @click="navigateExplore(link.href)"
        >
          <span
            class="k-inline-flex k-h-10 k-w-10 k-items-center k-justify-center k-rounded-knot-md k-bg-knot-primary-soft k-text-knot-primary"
            aria-hidden="true"
          >
            <component :is="link.icon" :size="18" />
          </span>
          <span class="k-flex-1 k-text-sm k-font-semibold k-text-knot-text">{{ link.label }}</span>
          <ArrowRight
            :size="16"
            class="k-text-knot-text-muted group-hover:k-translate-x-0.5 k-transition-transform"
            aria-hidden="true"
          />
        </button>
      </div>
    </section>
  </div>
</template>
