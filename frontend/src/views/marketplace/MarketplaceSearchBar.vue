<!--
  Marketplace search input with local autocomplete and recent queries.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search } from 'lucide-vue-next';

import { useMarketplaceSearch } from '../../composables/useMarketplaceSearch';
import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';
import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';

const RECENT_KEY = 'knot.marketplace.recentSearches';
const RECENT_LIMIT = 5;

const {
  placeholderKey = 'marketplace.searchPlaceholder',
  autofocus = false,
} = defineProps<{
  placeholderKey?: string;
  autofocus?: boolean;
}>();

const emit = defineEmits<{
  submit: [query: string];
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const localQuery = ref(ctx?.route.value.query.q ?? '');
const panelOpen = ref(false);
const recentSearches = ref<string[]>([]);

const marketplaceRef = computed(() => ctx?.marketplace.value ?? null);
const queryRef = computed(() => localQuery.value);
const { hits } = useMarketplaceSearch(marketplaceRef, queryRef, 8);

watch(
  () => ctx?.route.value.query.q,
  (next) => {
    localQuery.value = next ?? '';
  },
);

const routePath = computed(() => {
  const kind = ctx?.route.value.kind;
  if (kind === 'search') {
    return '/search';
  }
  if (kind === 'templates') {
    return '/templates';
  }
  if (kind === 'packs') {
    return '/packs';
  }
  return '/search';
});

const showPanel = computed(
  () => panelOpen.value && (hits.value.length > 0 || (localQuery.value.trim().length < 2 && recentSearches.value.length > 0)),
);

function loadRecent(): void {
  try {
    const raw = localStorage.getItem(RECENT_KEY);
    if (!raw) {
      recentSearches.value = [];
      return;
    }
    const parsed = JSON.parse(raw);
    recentSearches.value = Array.isArray(parsed)
      ? parsed.filter((entry): entry is string => typeof entry === 'string').slice(0, RECENT_LIMIT)
      : [];
  } catch {
    recentSearches.value = [];
  }
}

function persistRecent(query: string): void {
  const trimmed = query.trim();
  if (trimmed.length < 2) {
    return;
  }
  const next = [trimmed, ...recentSearches.value.filter((entry) => entry !== trimmed)].slice(0, RECENT_LIMIT);
  recentSearches.value = next;
  localStorage.setItem(RECENT_KEY, JSON.stringify(next));
}

function buildNavigatePath(q: string): string {
  const base = routePath.value;
  const query = { ...(ctx?.route.value.query ?? {}), q: q.trim() };
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value?.trim()) {
      params.set(key, value.trim());
    }
  }
  const qs = params.toString();
  return qs ? `${base}?${qs}` : base;
}

function submitQuery(q: string): void {
  const trimmed = q.trim();
  if (!ctx || !trimmed) {
    return;
  }
  persistRecent(trimmed);
  panelOpen.value = false;
  ctx.navigate(buildNavigatePath(trimmed));
  trackMarketplaceEvent('search.query', { queryHash: trimmed.length, resultsCount: hits.value.length });
  emit('submit', trimmed);
}

function onSubmit(): void {
  submitQuery(localQuery.value);
}

function openHit(path: string, label: string): void {
  persistRecent(label);
  panelOpen.value = false;
  ctx?.navigate(path);
}

function onBlur(): void {
  window.setTimeout(() => {
    panelOpen.value = false;
  }, 150);
}

onMounted(() => {
  loadRecent();
});
</script>

<template>
  <form class="k-relative k-w-full" role="search" @submit.prevent="onSubmit">
    <label class="k-sr-only" for="km-search-input">{{ t('marketplace.searchLabel') }}</label>
    <Search
      class="k-pointer-events-none k-absolute k-left-3 k-top-1/2 -k-translate-y-1/2 k-text-knot-text-soft"
      :size="16"
      aria-hidden="true"
    />
    <input
      id="km-search-input"
      v-model="localQuery"
      type="search"
      class="k-w-full k-rounded-knot-pill k-border k-border-knot-border k-bg-knot-surface k-py-2.5 k-pl-10 k-pr-4 k-text-sm focus:k-outline-none focus:k-ring-2 focus:k-ring-knot-primary/30"
      :placeholder="t(placeholderKey)"
      :autofocus="autofocus"
      autocomplete="off"
      enterkeyhint="search"
      role="combobox"
      aria-autocomplete="list"
      :aria-expanded="showPanel"
      aria-controls="km-search-suggestions"
      @focus="panelOpen = true"
      @blur="onBlur"
    />

    <ul
      v-if="showPanel"
      id="km-search-suggestions"
      role="listbox"
      class="k-absolute k-z-20 k-mt-2 k-w-full k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-shadow-knot-md k-max-h-72 k-overflow-y-auto"
    >
      <template v-if="localQuery.trim().length >= 2 && hits.length">
        <li
          v-for="hit in hits"
          :key="`${hit.kind}-${hit.slug}`"
          role="option"
        >
          <button
            type="button"
            class="k-flex k-w-full k-flex-col k-items-start k-gap-0.5 k-px-3 k-py-2 k-text-left hover:k-bg-knot-surface-soft"
            @mousedown.prevent="openHit(hit.kind === 'template' ? `/template/${hit.slug}` : `/product/${hit.slug}`, hit.label)"
          >
            <span class="k-text-sm k-font-medium k-text-knot-text">{{ hit.label }}</span>
            <span class="k-text-xs k-text-knot-text-muted">{{ hit.kind === 'template' ? t('marketplace.navTemplates') : t('marketplace.navPacks') }}</span>
          </button>
        </li>
      </template>
      <template v-else-if="recentSearches.length">
        <li class="k-px-3 k-py-2 k-text-[11px] k-font-semibold k-uppercase k-tracking-wide k-text-knot-text-muted">
          {{ t('marketplace.searchRecent') }}
        </li>
        <li v-for="recent in recentSearches" :key="recent" role="option">
          <button
            type="button"
            class="k-block k-w-full k-px-3 k-py-2 k-text-left k-text-sm hover:k-bg-knot-surface-soft"
            @mousedown.prevent="submitQuery(recent)"
          >
            {{ recent }}
          </button>
        </li>
      </template>
    </ul>
  </form>
</template>
