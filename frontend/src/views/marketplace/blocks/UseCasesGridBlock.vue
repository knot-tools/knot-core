<!--
  Narrative use-case cards grid.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { LayoutGrid } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

export interface UseCaseItem {
  title?: string;
  description?: string;
  href?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    items?: UseCaseItem[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.useCasesTitle'));
const list = computed(() => (Array.isArray(props.items) ? props.items : []));
</script>

<template>
  <section
    class="k-space-y-4"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <LayoutGrid :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.useCasesEmpty') }}</p>

    <ul
      v-else
      class="k-grid k-gap-4 sm:k-grid-cols-2 k-list-none"
    >
      <li
        v-for="(uc, idx) in list"
        :key="idx"
        class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-shadow-knot-sm"
      >
        <h3 class="k-text-xs k-font-semibold k-text-knot-text">{{ uc.title ?? t('marketplace.editorialUntitled') }}</h3>
        <p v-if="uc.description" class="k-mt-2 k-text-[11px] k-text-knot-text-muted">{{ uc.description }}</p>
        <a
          v-if="sanitize(uc.href)"
          :href="sanitize(uc.href)"
          target="_blank"
          rel="noopener noreferrer"
          class="k-mt-3 k-inline-flex k-text-[11px] k-font-semibold k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
        >
          {{ t('marketplace.useCasesLearn') }}
        </a>
      </li>
    </ul>
  </section>
</template>
