<!--
  Accessible product detail tabs — filters `visible`, syncs `?tab=` query.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import type { MarketplaceProductTabSpec } from '../../lib/api';
import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';

import ProductChangelogList from './ProductChangelogList.vue';
import ProductScreenshotGallery from './ProductScreenshotGallery.vue';
import FeaturesBlock from './blocks/FeaturesBlock.vue';
import RichTextBlock from './blocks/RichTextBlock.vue';
import TemplateGridBlock from './blocks/TemplateGridBlock.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';

const props = withDefaults(
  defineProps<{
    tabs?: MarketplaceProductTabSpec[];
    productSlug?: string | null;
  }>(),
  { tabs: () => [], productSlug: null },
);

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);

/**
 * Editorial v2 from license.knot.tools nests payload under `props.*`
 * (body / items / entries / images) while the renderer historically reads
 * `tab.body` / `tab.items` etc. We flatten so a single tab spec works
 * regardless of which side wrote it. Top-level fields win (explicit edits
 * in fallback files override nested props).
 */
type NormalisedTab = MarketplaceProductTabSpec & {
  items?: MarketplaceProductTabSpec['items'];
  body?: string;
  images?: MarketplaceProductTabSpec['images'];
};

function normaliseTab(tab: MarketplaceProductTabSpec): NormalisedTab {
  const propsBag = (tab as unknown as { props?: Record<string, unknown> }).props ?? null;
  if (!propsBag) {
    return tab as NormalisedTab;
  }
  const merged: Record<string, unknown> = { ...tab };
  if (merged.body === undefined && typeof propsBag.body === 'string') {
    merged.body = propsBag.body;
  }
  if (merged.body === undefined && typeof propsBag.content === 'string') {
    merged.body = propsBag.content;
  }
  if (merged.body === undefined && typeof propsBag.html === 'string') {
    merged.body = propsBag.html;
  }
  if (merged.items === undefined && Array.isArray(propsBag.items)) {
    merged.items = propsBag.items as NormalisedTab['items'];
  }
  if (merged.items === undefined && Array.isArray(propsBag.entries)) {
    merged.items = propsBag.entries as NormalisedTab['items'];
  }
  if (merged.images === undefined && Array.isArray(propsBag.images)) {
    merged.images = propsBag.images as NormalisedTab['images'];
  }
  return merged as unknown as NormalisedTab;
}

const visibleTabs = computed(() =>
  (props.tabs ?? [])
    .filter((tab) => tab.visible !== false && tab.id && tab.label)
    .map((tab) => normaliseTab(tab)),
);

function readTabFromQuery(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }
  return new URLSearchParams(window.location.search).get('tab');
}

function syncTabQuery(tabId: string): void {
  if (typeof window === 'undefined' || !window.history?.replaceState) {
    return;
  }
  const url = new URL(window.location.href);
  url.searchParams.set('tab', tabId);
  window.history.replaceState({}, '', url.toString());
}

const activeId = ref('');

function ensureActiveTab(): void {
  const list = visibleTabs.value;
  if (list.length === 0) {
    activeId.value = '';
    return;
  }
  const fromQuery = readTabFromQuery();
  const hit = list.find((tab) => tab.id === fromQuery);
  activeId.value = hit?.id ?? list[0]!.id;
}

onMounted(() => {
  ensureActiveTab();
});

watch(visibleTabs, () => {
  ensureActiveTab();
});

const activeTab = computed(() => visibleTabs.value.find((tab) => tab.id === activeId.value) ?? null);

function selectTab(tabId: string): void {
  if (activeId.value === tabId) {
    return;
  }
  activeId.value = tabId;
  syncTabQuery(tabId);
  trackMarketplaceEvent('tab.change', {
    tab: tabId,
    slug: props.productSlug ?? '',
    route: ctx?.route.value.kind ?? 'product',
  });
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const list = visibleTabs.value;
  if (list.length === 0) {
    return;
  }
  let next = index;
  if (event.key === 'ArrowRight') {
    next = (index + 1) % list.length;
  } else if (event.key === 'ArrowLeft') {
    next = (index - 1 + list.length) % list.length;
  } else if (event.key === 'Home') {
    next = 0;
  } else if (event.key === 'End') {
    next = list.length - 1;
  } else {
    return;
  }
  event.preventDefault();
  selectTab(list[next]!.id);
  const btn = (event.currentTarget as HTMLElement)?.parentElement?.querySelectorAll<HTMLButtonElement>(
    '[role="tab"]',
  )[next];
  btn?.focus();
}
</script>

<template>
  <section v-if="visibleTabs.length" class="k-space-y-4">
    <div
      role="tablist"
      :aria-label="t('marketplace.productTabsAria')"
      class="k-sticky k-top-0 k-z-[2] k-flex k-gap-1 k-flex-wrap k-border-b k-border-knot-border k-bg-knot-surface/95 k-backdrop-blur k-py-1"
    >
      <button
        v-for="(tab, index) in visibleTabs"
        :id="`tab-${tab.id}`"
        :key="tab.id"
        role="tab"
        type="button"
        :aria-selected="activeId === tab.id"
        :aria-controls="`panel-${tab.id}`"
        :tabindex="activeId === tab.id ? 0 : -1"
        :class="[
          'k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-t-knot-sm k-border-b-2 k-transition-colors',
          activeId === tab.id
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text',
        ]"
        @click="selectTab(tab.id)"
        @keydown="onKeydown($event, index)"
      >
        {{ tab.label }}
      </button>
    </div>

    <div
      v-if="activeTab"
      :id="`panel-${activeTab.id}`"
      role="tabpanel"
      :aria-labelledby="`tab-${activeTab.id}`"
      class="k-min-h-[8rem]"
    >
      <RichTextBlock
        v-if="activeTab.kind === 'richtext'"
        :title="activeTab.label"
        :body="activeTab.body"
      />
      <FeaturesBlock
        v-else-if="activeTab.kind === 'features'"
        :title="activeTab.label"
        :items="activeTab.items"
      />
      <TemplateGridBlock
        v-else-if="activeTab.kind === 'templates'"
        :show-header="false"
        :limit="12"
        mode="grid"
      />
      <ProductChangelogList
        v-else-if="activeTab.kind === 'changelog'"
        :entries="activeTab.items"
      />
      <ProductScreenshotGallery
        v-else-if="activeTab.kind === 'screenshots'"
        :items="activeTab.images ?? []"
      />
      <FeaturesBlock
        v-else-if="activeTab.kind === 'list'"
        :title="activeTab.label"
        :items="activeTab.items"
      />
      <RichTextBlock v-else :title="activeTab.label" :body="activeTab.body" />
    </div>
  </section>
</template>
