<!--
  Pack detail header (catalog-driven on product route).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useId } from 'vue';
import { Package } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import KProBadge from '../../../components/ui/KProBadge.vue';

import { knotMarketplaceBlockContextKey } from '../contextKey';

const props = defineProps<{
  title?: string;
  subtitle?: string;
  /** Force badge label (Pro / Enterprise) */
  tierLabel?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('ProductHeaderBlock requires Knot marketplace editorial context.');
}

const headingId = useId();

const pack = computed(() => {
  const slug = ctx.route.value.kind === 'product' ? ctx.route.value.slug : null;
  if (!slug) {
    return null;
  }
  return ctx.marketplace.value?.packs.find((p) => p.slug === slug) ?? null;
});

const heading = computed(() => props.title ?? pack.value?.label ?? t('marketplace.productHeaderFallback'));
const sub = computed(() => props.subtitle ?? pack.value?.description ?? '');
const badge = computed(() => props.tierLabel ?? (pack.value?.tier === 'enterprise' ? t('marketplace.enterpriseBadgeLabel') : pack.value?.tier === 'pro' ? t('marketplace.proBadgeLabel') : null));
</script>

<template>
  <header
    class="k-flex k-items-start k-gap-4 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <div class="k-h-12 k-w-12 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center k-shrink-0">
      <Package :size="22" aria-hidden="true" />
    </div>
    <div class="k-min-w-0">
      <div class="k-flex k-flex-wrap k-items-center k-gap-2">
        <h1 :id="headingId" class="k-text-xl k-font-bold k-text-knot-text">{{ heading }}</h1>
        <KProBadge v-if="badge" :label="badge" />
      </div>
      <p v-if="sub" class="k-mt-1 k-text-sm k-text-knot-text-muted">{{ sub }}</p>
      <code v-if="pack" class="k-mt-2 k-inline-block k-text-[11px] k-text-knot-text-soft">{{ pack.slug }}</code>
    </div>
  </header>
</template>
