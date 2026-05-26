<!--
  Marketplace breadcrumb trail for nested listing routes.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronRight } from 'lucide-vue-next';

import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';

const props = defineProps<{
  trail?: Array<{ label: string; path?: string }>;
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const crumbs = computed(() => {
  if (props.trail?.length) {
    return props.trail;
  }

  const route = ctx?.route.value;
  if (!route) {
    return [{ label: t('marketplace.title') }];
  }

  const out: Array<{ label: string; path?: string }> = [
    { label: t('marketplace.title'), path: '/' },
  ];

  switch (route.kind) {
    case 'packs':
      out.push({ label: t('marketplace.navPacks') });
      break;
    case 'templates':
      out.push({ label: t('marketplace.navTemplates') });
      break;
    case 'news':
      out.push({
        label: route.slug ? t('marketplace.newsArticleCrumb') : t('marketplace.navNews'),
        path: route.slug ? '/news' : undefined,
      });
      if (route.slug) {
        out.push({ label: route.slug });
      }
      break;
    case 'category':
      out.push({ label: t('marketplace.navPacks'), path: '/packs' });
      if (route.slug) {
        out.push({ label: route.slug });
      }
      break;
    case 'collection':
      out.push({ label: t('marketplace.collectionCrumb'), path: '/' });
      if (route.slug) {
        out.push({ label: route.slug });
      }
      break;
    case 'search':
      out.push({ label: t('marketplace.navSearch') });
      break;
    case 'product':
      out.push({ label: t('marketplace.navPacks'), path: '/packs' });
      if (route.slug) {
        out.push({ label: route.slug });
      }
      break;
    case 'template':
      out.push({ label: t('marketplace.navTemplates'), path: '/templates' });
      if (route.slug) {
        out.push({ label: route.slug });
      }
      break;
    default:
      break;
  }

  return out;
});

function go(path: string | undefined): void {
  if (!path || !ctx) {
    return;
  }
  ctx.navigate(path);
}
</script>

<template>
  <nav class="k-flex k-flex-wrap k-items-center k-gap-1 k-text-sm k-text-knot-text-muted" :aria-label="t('marketplace.breadcrumbAria')">
    <template v-for="(crumb, idx) in crumbs" :key="`${crumb.label}-${idx}`">
      <ChevronRight v-if="idx > 0" :size="14" class="k-shrink-0 k-opacity-60" aria-hidden="true" />
      <button
        v-if="crumb.path && idx < crumbs.length - 1"
        type="button"
        class="k-border-0 k-bg-transparent k-p-0 hover:k-text-knot-primary k-font-medium focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        @click="go(crumb.path)"
      >
        {{ crumb.label }}
      </button>
      <span v-else class="k-font-medium" :class="idx === crumbs.length - 1 ? 'k-text-knot-text' : ''">
        {{ crumb.label }}
      </span>
    </template>
  </nav>
</template>
