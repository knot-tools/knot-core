<!--
  Editorial news / announcement link list.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, useId } from 'vue';
import { ChevronRight, Newspaper } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

export interface NewsListItem {
  title?: string;
  date?: string;
  href?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    items?: NewsListItem[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();

const heading = computed(() => props.title ?? t('marketplace.editorialNewsTitle'));
const list = computed(() => (Array.isArray(props.items) ? props.items : []));
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <header class="k-flex k-items-start k-gap-3 k-mb-4">
      <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center k-shrink-0">
        <Newspaper :size="20" aria-hidden="true" />
      </div>
      <div class="k-min-w-0">
        <h2 :id="headingId" class="k-text-sm k-font-bold k-text-knot-text">
          {{ heading }}
        </h2>
        <p v-if="subtitle" class="k-mt-1 k-text-xs k-text-knot-text-muted">
          {{ subtitle }}
        </p>
      </div>
    </header>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">
      {{ t('marketplace.editorialNewsEmpty') }}
    </p>

    <ul v-else class="k-divide-y k-divide-knot-border k-border k-border-knot-border k-rounded-knot-sm k-overflow-hidden">
      <li v-for="(row, idx) in list" :key="idx">
        <component
          :is="sanitize(row.href) ? 'a' : 'div'"
          :href="sanitize(row.href) || undefined"
          :target="sanitize(row.href) ? '_blank' : undefined"
          :rel="sanitize(row.href) ? 'noopener noreferrer' : undefined"
          class="k-flex k-items-center k-justify-between k-gap-3 k-px-3 k-py-2.5 k-text-xs k-text-knot-text hover:k-bg-knot-surface-soft k-transition-colors focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        >
          <span class="k-min-w-0">
            <span class="k-font-semibold k-block k-truncate">{{ row.title ?? t('marketplace.editorialUntitled') }}</span>
            <span v-if="row.date" class="k-text-[11px] k-text-knot-text-soft">{{ row.date }}</span>
          </span>
          <ChevronRight v-if="sanitize(row.href)" :size="14" class="k-text-knot-text-muted k-shrink-0" aria-hidden="true" />
        </component>
      </li>
    </ul>
  </section>
</template>
