<!--
  Ecosystem cards — Core + paid extensions (Pro Pack, Migration, …).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { ExternalLink, Puzzle, Package, Rocket, Building2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';
import { knotMarketplaceBlockContextKey } from '../contextKey';

interface EcosystemItem {
  title?: string;
  description?: string;
  href?: string;
  badge?: string;
  tier?: string;
}

const props = withDefaults(
  defineProps<{
    headline?: string;
    title?: string;
    body?: string;
    items?: EcosystemItem[];
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const injectedCtx = inject(knotMarketplaceBlockContextKey);

const titleResolved = computed(() => props.headline ?? props.title ?? t('marketplace.ecosystemTitle'));
const intro = computed(() => props.body ?? t('marketplace.ecosystemCore'));
const cards = computed(() => (props.items ?? []).filter((row) => row?.title));

function cardIcon(title: string) {
  const t = title.toLowerCase();
  if (t.includes('pro')) {
    return Package;
  }
  if (t.includes('migr')) {
    return Rocket;
  }
  if (t.includes('enterprise') || t.includes('entreprise')) {
    return Building2;
  }
  return Puzzle;
}

function prefetchEcoHref(raw?: string | null): void {
  injectedCtx?.prefetchRouteFromHref?.(raw ?? undefined);
  injectedCtx?.prefetchTemplateFromHref?.(raw ?? undefined);
}</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-5 k-space-y-4"
    role="region"
    :aria-label="titleResolved"
  >
    <div class="k-flex k-items-center k-gap-2">
      <Puzzle :size="18" class="k-text-knot-primary" aria-hidden="true" />
      <h2 class="k-text-sm k-font-bold k-text-knot-text">
        {{ titleResolved }}
      </h2>
    </div>
    <p v-if="intro" class="k-text-xs k-text-knot-text-muted">
      {{ intro }}
    </p>

    <div
      v-if="cards.length"
      class="k-flex k-gap-3 k-overflow-x-auto k-pb-1 k-snap-x k-snap-mandatory md:k-grid md:k-grid-cols-3 md:k-overflow-visible"
    >
      <a
        v-for="(item, idx) in cards"
        :key="item.title ?? idx"
        :href="sanitize(item.href) ?? 'https://knot.tools'"
        target="_blank"
        rel="noopener"
        class="k-min-w-[240px] k-snap-start k-flex-shrink-0 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-shadow-knot-sm hover:k-border-knot-primary hover:k-shadow-knot-premium k-transition-all k-block md:k-min-w-0"
        @mouseenter="prefetchEcoHref(item.href)"
      >
        <div class="k-flex k-items-start k-gap-3">
          <div
            class="k-h-10 k-w-10 k-rounded-knot-sm k-flex k-items-center k-justify-center k-shrink-0"
            :class="item.badge || item.tier ? 'k-bg-knot-color-premium-gold-soft k-text-knot-color-premium-gold-strong' : 'k-bg-knot-primary-soft k-text-knot-primary'"
          >
            <component :is="cardIcon(item.title ?? '')" :size="18" aria-hidden="true" />
          </div>
          <div class="k-min-w-0 k-flex-1">
            <div class="k-flex k-items-start k-justify-between k-gap-2">
              <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ item.title }}</h3>
          <span
            v-if="item.badge || item.tier"
            class="k-text-[10px] k-font-bold k-uppercase k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-color-premium-gold-soft k-text-knot-color-premium-text-on-gold"
          >
            {{ item.badge ?? item.tier }}
          </span>
            </div>
            <p class="k-mt-2 k-text-xs k-text-knot-text-muted k-line-clamp-3">
              {{ item.description }}
            </p>
            <span class="k-mt-3 k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-text-knot-primary">
              {{ t('marketplace.ecosystemSite') }}
              <ExternalLink :size="11" aria-hidden="true" />
            </span>
          </div>
        </div>
      </a>
    </div>

    <p v-else class="k-text-xs k-text-knot-text-muted">
      <a href="https://knot.tools" target="_blank" rel="noopener" class="k-text-knot-primary hover:k-underline">
        knot.tools — {{ t('marketplace.ecosystemSite') }}
      </a>
    </p>
  </section>
</template>
