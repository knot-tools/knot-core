<!--
  Marketplace top navigation bar with primary route tabs.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { Home, Layers, Newspaper, Package, Search } from 'lucide-vue-next';

import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';
import type { MarketplaceRouteKind } from './types';

const { t } = useI18n();

const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const routeKind = computed(() => ctx?.route.value.kind ?? 'home');

interface NavItem {
  kind: MarketplaceRouteKind | 'home';
  path: string;
  labelKey: string;
  icon: typeof Home;
}

const items: NavItem[] = [
  { kind: 'home', path: '/', labelKey: 'marketplace.navHome', icon: Home },
  { kind: 'packs', path: '/packs', labelKey: 'marketplace.navPacks', icon: Package },
  { kind: 'templates', path: '/templates', labelKey: 'marketplace.navTemplates', icon: Layers },
  { kind: 'news', path: '/news', labelKey: 'marketplace.navNews', icon: Newspaper },
  { kind: 'search', path: '/search', labelKey: 'marketplace.navSearch', icon: Search },
];

function isActive(item: NavItem): boolean {
  if (item.kind === 'home') {
    return routeKind.value === 'home';
  }
  if (item.kind === 'news') {
    return routeKind.value === 'news';
  }
  return routeKind.value === item.kind;
}

function go(path: string): void {
  ctx?.navigate(path);
}
</script>

<template>
  <header class="km-topbar" role="navigation" :aria-label="t('marketplace.topbarAria')">
    <nav class="k-flex k-flex-wrap k-items-center k-gap-2 k-w-full">
      <button
        v-for="item in items"
        :key="item.path"
        type="button"
        class="k-inline-flex k-items-center k-gap-1.5 k-rounded-knot-pill k-px-3 k-py-1.5 k-text-sm k-font-medium k-transition-colors"
        :class="
          isActive(item)
            ? 'k-bg-knot-primary-soft k-text-knot-primary-strong'
            : 'k-text-knot-text-muted hover:k-bg-knot-surface-soft'
        "
        :aria-current="isActive(item) ? 'page' : undefined"
        @click="go(item.path)"
      >
        <component :is="item.icon" :size="15" aria-hidden="true" />
        {{ t(item.labelKey) }}
      </button>
    </nav>
  </header>
</template>
