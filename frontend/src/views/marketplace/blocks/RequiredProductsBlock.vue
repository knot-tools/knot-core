<!--
  Highlights packs required by an offer (filtered by slug list).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useId } from 'vue';
import { Link2, ShoppingBag } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import type { MarketplacePack } from '../../../lib/api';

import type { BlockContext } from '../types';

import { knotMarketplaceBlockContextKey } from '../contextKey';

const props = withDefaults(
  defineProps<{
    title?: string;
    slugs?: string[];
    ariaLabel?: string;
  }>(),
  { slugs: () => [] },
);

const { t } = useI18n();
const ctxInjected = inject(knotMarketplaceBlockContextKey);
if (ctxInjected === undefined) {
  throw new Error('RequiredProductsBlock requires Knot marketplace editorial context.');
}
const ctx: BlockContext = ctxInjected;

const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.requiredProductsTitle'));

const packs = computed<MarketplacePack[]>(() => {
  const want = (props.slugs ?? []).filter(Boolean);
  const all = ctx.marketplace.value?.packs ?? [];
  if (want.length === 0) {
    return [];
  }
  const out: MarketplacePack[] = [];
  for (const s of want) {
    const hit = all.find((p) => p.slug === s);
    if (hit) {
      out.push(hit);
    }
  }
  return out;
});

function open(p: MarketplacePack): void {
  ctx.navigate(`/product/${p.slug}`);
}
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-mb-3 k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <Link2 :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="packs.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.requiredProductsEmpty') }}</p>

    <ul v-else class="k-space-y-2">
      <li v-for="p in packs" :key="p.slug">
        <button
          type="button"
          class="k-w-full k-flex k-items-center k-gap-3 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-text-left hover:k-border-knot-primary k-transition-colors focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
          @click="open(p)"
          @keydown.enter="open(p)"
        >
          <span class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center k-shrink-0">
            <ShoppingBag :size="16" aria-hidden="true" />
          </span>
          <span class="k-min-w-0">
            <span class="k-block k-text-xs k-font-semibold k-text-knot-text">{{ p.label }}</span>
            <span class="k-block k-text-[11px] k-text-knot-text-soft">{{ p.slug }}</span>
          </span>
        </button>
      </li>
    </ul>
  </section>
</template>
