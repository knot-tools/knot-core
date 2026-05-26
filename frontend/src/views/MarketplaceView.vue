<!--
  Marketplace host — hash router + editorial blocks / detail layouts.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, nextTick, onMounted, provide, ref, watch, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KnotMarketplaceBlockSpecDTO, KnotMarketplaceEditorialDTO, MarketplaceResponse } from '../lib/api';
import { knotApi } from '../lib/api';
import { trackMarketplaceEvent } from '../lib/marketplaceTrack';
import BlockRenderer from './marketplace/BlockRenderer.vue';
import MarketplaceShell from './marketplace/MarketplaceShell.vue';
import ProductDetailLayout from './marketplace/ProductDetailLayout.vue';
import TemplateDetailLayout from './marketplace/TemplateDetailLayout.vue';
import type { BlockContext, MarketplaceDrawerTarget, MarketplaceRouteSnapshot } from './marketplace/types';
import { useMarketplaceRoute } from '../composables/useMarketplaceRoute';
import { useMarketplacePrefetch } from '../composables/useMarketplacePrefetch';
import {
  applyMarketplaceUnreadDocumentFlag,
  markMarketplaceEditorialSeen,
  persistEditorialUpdatedAtCache,
  readMarketplaceLastSeen,
} from '../lib/marketplaceEditorialUnread';
import { knotMarketplaceBlockContextKey } from './marketplace/contextKey';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t } = useI18n();

const marketplace = ref<MarketplaceResponse | null>(null);
const shellRef = ref<InstanceType<typeof MarketplaceShell> | null>(null);

const editorialMirror = computed(() => marketplace.value?.editorial ?? null);

const killSwitchActive = computed(
  () => editorialMirror.value?.meta?.killSwitch === true,
);

const { warmProductDetail, warmRouteFromHref, warmTemplateDetail, warmTemplateFromHref } =
  useMarketplacePrefetch(editorialMirror);

const {
  route: marketplaceRouteReadonly,
  navigate,
  syncFromWindow,
} = useMarketplaceRoute({
  editorial: editorialMirror,
});

const marketplaceLoading = ref(true);
const marketplaceRefreshing = ref(false);
const marketplaceError = ref<string | null>(null);
const marketplaceLoadBlockedCode = ref<string | null>(null);

async function reloadMarketplace(opts?: { refresh?: boolean }): Promise<void> {
  marketplaceError.value = null;
  marketplaceLoadBlockedCode.value = null;

  const lastSeenBeforeFetch = readMarketplaceLastSeen();
  const isRefresh = opts?.refresh ?? false;

  try {
    if (isRefresh) {
      marketplaceRefreshing.value = true;
    } else {
      marketplaceLoading.value = true;
    }
    marketplace.value = await knotApi.marketplace(opts);
    syncFromWindow();

    const updatedAtRaw = marketplace.value?.editorial?.meta?.updatedAt;
    const updatedAt =
      typeof updatedAtRaw === 'string' && updatedAtRaw !== '' ? updatedAtRaw : undefined;
    if (updatedAt) {
      persistEditorialUpdatedAtCache(updatedAt);
      applyMarketplaceUnreadDocumentFlag(updatedAt, lastSeenBeforeFetch);
      markMarketplaceEditorialSeen(updatedAt);
    }
  } catch (err: unknown) {
    const typed = err as Error & { code?: string };
    marketplaceLoadBlockedCode.value = typed.code ?? null;
    marketplaceError.value =
      err instanceof Error ? err.message : t('marketplace.loadFailed');
    marketplace.value = null;
  } finally {
    marketplaceLoading.value = false;
    marketplaceRefreshing.value = false;
  }
}

function defaultBlocks(snapshot: MarketplaceRouteSnapshot): KnotMarketplaceBlockSpecDTO[] {
  if (snapshot.kind === 'home') {
    return [{ type: 'home_discovery', id: 'home-discovery' }];
  }
  if (snapshot.kind === 'news') {
    return [
      { type: 'hero', id: 'n-hero' },
      { type: 'banner', id: 'n-banner', props: { showStale: false } },
      { type: 'workflow_mini', id: 'n-preview', props: { nodes: 5, edges: 6 } },
      { type: 'ecosystem', id: 'n-ecosystem' },
    ];
  }
  if (
    snapshot.kind === 'packs'
    || snapshot.kind === 'templates'
    || snapshot.kind === 'search'
    || snapshot.kind === 'category'
    || snapshot.kind === 'collection'
  ) {
    return [{ type: 'storefront_tabs', id: 'marketplace-browse' }];
  }
  return [{ type: 'home_discovery', id: 'home-discovery-fallback' }];
}

function editorialLayoutForRoute(
  ed: KnotMarketplaceEditorialDTO | null | undefined,
  snapshot: MarketplaceRouteSnapshot,
): KnotMarketplaceBlockSpecDTO[] | null {
  if (!ed) {
    return null;
  }

  if (snapshot.kind === 'home') {
    const home = ed.home;
    if (Array.isArray(home)) {
      return home;
    }
    if (home && typeof home === 'object') {
      const spotlightItems = home.spotlight?.items;
      const hasV2Discovery =
        (Array.isArray(spotlightItems) && spotlightItems.length > 0)
        || (Array.isArray(home.collections) && home.collections.length > 0);
      if (hasV2Discovery) {
        return [{ type: 'home_discovery', id: 'home-discovery' }];
      }
      if (Array.isArray(home.layout) && home.layout.length > 0) {
        return home.layout;
      }
    }
    return null;
  }

  if (snapshot.kind === 'news' && snapshot.slug) {
    const page = ed.newsPages?.[snapshot.slug];
    if (page?.layout?.length) {
      return page.layout;
    }
    if (ed.news?.length) {
      return ed.news;
    }
    return null;
  }

  return null;
}

const activeBlocks = computed<KnotMarketplaceBlockSpecDTO[]>(() => {
  const snapshot = marketplaceRouteReadonly.value;
  if (snapshot.kind === 'product' || snapshot.kind === 'template') {
    return [];
  }
  const ed = editorialMirror.value ?? null;
  return editorialLayoutForRoute(ed, snapshot) ?? defaultBlocks(snapshot);
});

const marketplaceRouteReactive = marketplaceRouteReadonly as unknown as Ref<MarketplaceRouteSnapshot>;

function openDrawerPreview(target: MarketplaceDrawerTarget): void {
  shellRef.value?.openDrawer(target);
}

function trackEvent(event: string, context?: Record<string, string | number | boolean>): void {
  trackMarketplaceEvent(event as Parameters<typeof trackMarketplaceEvent>[0], context ?? {});
}

const blockContext: BlockContext = {
  route: marketplaceRouteReactive,
  marketplace,
  loading: marketplaceLoading,
  error: marketplaceError,
  marketplaceLoadBlockedCode,
  refreshing: marketplaceRefreshing,
  navigate,
  reloadMarketplace,
  prefetchProductDetail: warmProductDetail,
  prefetchRouteFromHref: warmRouteFromHref,
  prefetchTemplateDetail: warmTemplateDetail,
  prefetchTemplateFromHref: warmTemplateFromHref,
  openDrawerPreview,
  trackEvent,
};

provide(knotMarketplaceBlockContextKey, blockContext);

const showUnavailable = computed(
  () => !marketplaceLoading.value && marketplaceError.value != null && marketplace.value == null,
);

const contentRoot = ref<HTMLElement | null>(null);
const routeAnnouncement = ref('');

/** Screen-reader route title only — do not `.focus()` headings (Chrome selects visible text). */
function announceRouteTitle(): void {
  const root = contentRoot.value;
  if (!root) {
    return;
  }
  const heading = root.querySelector<HTMLElement>('h1, [role="banner"] h2, h2');
  routeAnnouncement.value = heading?.textContent?.trim() ?? '';
}

watch(
  () => marketplaceRouteReadonly.value,
  () => {
    void nextTick(() => announceRouteTitle());
  },
  { deep: true },
);

onMounted(() => {
  void reloadMarketplace().then(() => {
    void nextTick(() => announceRouteTitle());
  });
});
</script>

<template>
  <MarketplaceShell
    ref="shellRef"
    :loading="marketplaceLoading && !marketplace"
    :unavailable="showUnavailable"
    :unavailable-message="marketplaceError ?? ''"
    :unavailable-code="marketplaceLoadBlockedCode"
    :kill-switch="killSwitchActive"
    @retry="reloadMarketplace()"
  >
    <div
      ref="contentRoot"
      class="k-space-y-6"
    >
      <p class="k-sr-only" aria-live="polite" aria-atomic="true">{{ routeAnnouncement }}</p>

      <ProductDetailLayout v-if="marketplaceRouteReadonly.kind === 'product'" />
      <TemplateDetailLayout v-else-if="marketplaceRouteReadonly.kind === 'template'" />
      <BlockRenderer
        v-else
        :blocks="activeBlocks"
        :context="blockContext"
      />
    </div>
  </MarketplaceShell>
</template>
