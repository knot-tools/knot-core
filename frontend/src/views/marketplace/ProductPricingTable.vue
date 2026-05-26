<!--
  Free / Pro / Enterprise pricing comparator for product detail pages.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { ExternalLink } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../composables/useSanitizedMarketplaceHref';

export interface PricingPlan {
  name: string;
  price?: string;
  href?: string;
  highlighted?: boolean;
  features?: string[];
}

const props = withDefaults(
  defineProps<{
    plans?: PricingPlan[];
    highlight?: string;
    ariaLabel?: string;
  }>(),
  { plans: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();

const visiblePlans = computed(() => props.plans ?? []);

function isHighlighted(plan: PricingPlan): boolean {
  if (plan.highlighted) {
    return true;
  }
  const needle = (props.highlight ?? '').toLowerCase();
  return needle !== '' && plan.name.toLowerCase().includes(needle);
}
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-space-y-3 k-shadow-knot-sm lg:k-sticky lg:k-top-4"
    role="region"
    :aria-label="ariaLabel ?? t('marketplace.pricingTableTitle')"
  >
    <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('marketplace.pricingTableTitle') }}</h2>
    <p v-if="visiblePlans.length === 0" class="k-text-xs k-text-knot-text-muted">{{ t('marketplace.comparePlansEmpty') }}</p>
    <div v-else class="k-grid k-gap-3">
      <article
        v-for="plan in visiblePlans"
        :key="plan.name"
        :class="[
          'k-rounded-knot-sm k-border k-p-3 k-space-y-2',
          isHighlighted(plan)
            ? 'k-border-knot-primary k-bg-knot-primary-soft/30 k-shadow-knot-sm'
            : 'k-border-knot-border k-bg-knot-surface-soft',
        ]"
      >
        <header class="k-flex k-items-start k-justify-between k-gap-2">
          <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ plan.name }}</h3>
          <span v-if="isHighlighted(plan)" class="k-text-[10px] k-font-bold k-uppercase k-text-knot-primary">
            {{ t('marketplace.pricingHighlighted') }}
          </span>
        </header>
        <p v-if="plan.price" class="k-text-xs k-font-semibold k-text-knot-text">{{ plan.price }}</p>
        <ul v-if="plan.features?.length" class="k-space-y-1 k-text-[11px] k-text-knot-text-muted">
          <li v-for="(feat, i) in plan.features" :key="i" class="k-flex k-gap-1">
            <span aria-hidden="true">✓</span>
            <span>{{ feat }}</span>
          </li>
        </ul>
        <a
          v-if="sanitize(plan.href)"
          :href="sanitize(plan.href)"
          target="_blank"
          rel="noopener"
          class="k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
        >
          {{ t('marketplace.compareChoose') }}
          <ExternalLink :size="11" aria-hidden="true" />
        </a>
      </article>
    </div>
  </section>
</template>
