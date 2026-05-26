<!--
  Plan comparison (plain-text feature matrix).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Scale } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

export interface ComparePlan {
  name?: string;
  price?: string;
  href?: string;
  highlighted?: boolean;
  features?: string[];
}

const props = withDefaults(
  defineProps<{
    title?: string;
    plans?: ComparePlan[];
    ariaLabel?: string;
  }>(),
  { plans: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.comparePlansTitle'));

const list = computed(() => (Array.isArray(props.plans) ? props.plans : []));

const featureUnion = computed(() => {
  const rows = new Map<string, true>();
  for (const p of list.value) {
    for (const f of p.features ?? []) {
      if (f) {
        rows.set(f, true);
      }
    }
  }
  return [...rows.keys()];
});

function planIncludes(plan: ComparePlan, feat: string): boolean {
  return (plan.features ?? []).includes(feat);
}
</script>

<template>
  <section
    class="k-space-y-4"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <Scale :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.comparePlansEmpty') }}</p>

    <!-- Narrow: accordion per plan -->
    <div v-else class="k-block md:k-hidden k-space-y-2">
      <details
        v-for="(plan, idx) in list"
        :key="'mobile-plan-' + idx"
        class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-overflow-hidden"
      >
        <summary
          class="k-cursor-pointer k-list-none k-px-3 k-py-3 k-flex k-flex-col k-gap-1 k-select-none [&::-webkit-details-marker]:k-hidden"
          :class="plan.highlighted ? 'k-bg-knot-primary-soft' : 'k-bg-knot-surface-soft'"
        >
          <span class="k-font-semibold k-text-knot-text">{{ plan.name ?? t('marketplace.editorialUntitled') }}</span>
          <span v-if="plan.price" class="k-text-[11px] k-font-normal k-text-knot-text-muted">{{ plan.price }}</span>
          <span class="k-text-[10px] k-font-medium k-text-knot-text-soft">{{ t('marketplace.compareAccordionHint') }}</span>
        </summary>
        <div
          class="k-border-t k-border-knot-border k-px-3 k-py-3 k-space-y-2"
          :class="plan.highlighted ? 'k-bg-knot-primary-soft/30' : 'k-bg-knot-surface'"
        >
          <a
            v-if="sanitize(plan.href)"
            :href="sanitize(plan.href)"
            target="_blank"
            rel="noopener noreferrer"
            class="k-inline-block k-text-[11px] k-font-semibold k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
          >
            {{ t('marketplace.compareChoose') }}
          </a>
          <ul class="k-space-y-1.5 k-text-[11px]">
            <li
              v-for="feat in featureUnion"
              :key="'m-' + idx + '-' + feat"
              class="k-flex k-items-center k-justify-between k-gap-2 k-text-knot-text"
            >
              <span class="k-min-w-0">{{ feat }}</span>
              <span
                v-if="planIncludes(plan, feat)"
                class="k-text-knot-success k-font-semibold k-shrink-0"
                :aria-label="t('marketplace.compareFeatureIncluded')"
              >✓</span>
              <span v-else class="k-text-knot-text-soft k-shrink-0">—</span>
            </li>
          </ul>
        </div>
      </details>
    </div>

    <!-- md+: matrix table -->
    <div v-if="list.length > 0" class="k-hidden md:k-block k-overflow-x-auto">
      <table class="k-min-w-full k-border-collapse k-text-xs">
        <thead>
          <tr>
            <th scope="col" class="k-border k-border-knot-border k-bg-knot-surface-soft k-p-2 k-text-left k-font-semibold k-text-knot-text-muted">
              {{ t('marketplace.compareFeatureColumn') }}
            </th>
            <th
              v-for="(plan, idx) in list"
              :key="idx"
              scope="col"
              class="k-border k-border-knot-border k-p-2 k-text-left k-font-semibold"
              :class="plan.highlighted ? 'k-bg-knot-primary-soft k-text-knot-primary' : 'k-bg-knot-surface k-text-knot-text'"
            >
              <span class="k-block">{{ plan.name ?? t('marketplace.editorialUntitled') }}</span>
              <span v-if="plan.price" class="k-block k-mt-1 k-text-[11px] k-font-normal k-text-knot-text-muted">{{ plan.price }}</span>
              <a
                v-if="sanitize(plan.href)"
                :href="sanitize(plan.href)"
                target="_blank"
                rel="noopener noreferrer"
                class="k-mt-2 k-inline-block k-text-[11px] k-font-semibold k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
              >
                {{ t('marketplace.compareChoose') }}
              </a>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="feat in featureUnion" :key="feat">
            <th scope="row" class="k-border k-border-knot-border k-bg-knot-surface-soft k-p-2 k-text-left k-font-medium k-text-knot-text">
              {{ feat }}
            </th>
            <td
              v-for="(plan, idx) in list"
              :key="idx"
              class="k-border k-border-knot-border k-p-2 k-text-center"
              :class="plan.highlighted ? 'k-bg-knot-primary-soft/40' : 'k-bg-knot-surface'"
            >
              <span v-if="planIncludes(plan, feat)" class="k-text-knot-success k-font-semibold" :aria-label="t('marketplace.compareFeatureIncluded')">✓</span>
              <span v-else class="k-text-knot-text-soft">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
