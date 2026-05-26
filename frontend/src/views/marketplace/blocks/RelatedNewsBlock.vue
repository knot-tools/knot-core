<!--
  Related news links (compact).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Share2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

interface RelatedNewsLink {
  title?: string;
  date?: string;
  href?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    items?: RelatedNewsLink[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.relatedNewsTitle'));
const list = computed(() => (Array.isArray(props.items) ? props.items : []));
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-4"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-mb-3 k-flex k-items-center k-gap-2 k-text-xs k-font-bold k-text-knot-text">
      <Share2 :size="14" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-[11px] k-text-knot-text-soft">{{ t('marketplace.relatedNewsEmpty') }}</p>

    <ul v-else class="k-space-y-1.5">
      <li v-for="(row, idx) in list" :key="idx">
        <a
          v-if="sanitize(row.href)"
          :href="sanitize(row.href)"
          target="_blank"
          rel="noopener noreferrer"
          class="k-text-[11px] k-font-medium k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
        >
          {{ row.title ?? t('marketplace.editorialUntitled') }}
        </a>
        <span v-else class="k-text-[11px] k-text-knot-text-muted">{{ row.title }}</span>
      </li>
    </ul>
  </section>
</template>
