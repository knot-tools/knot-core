<!--
  Outbound editorial resource links (docs, portals).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, useId } from 'vue';
import { Bookmark, ExternalLink } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

export interface ResourceLink {
  title?: string;
  description?: string;
  href?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    items?: ResourceLink[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.resourcesTitle'));
const list = computed(() => (Array.isArray(props.items) ? props.items : []));
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-5"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <header class="k-flex k-items-center k-gap-2 k-mb-4">
      <Bookmark :size="18" class="k-text-knot-primary" aria-hidden="true" />
      <h2 :id="headingId" class="k-text-sm k-font-bold k-text-knot-text">{{ heading }}</h2>
    </header>
    <p v-if="subtitle" class="k-mb-3 k-text-xs k-text-knot-text-muted">{{ subtitle }}</p>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.resourcesEmpty') }}</p>

    <ul v-else class="k-space-y-2">
      <li v-for="(it, idx) in list" :key="idx">
        <div class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-p-3">
          <div class="k-flex k-items-start k-justify-between k-gap-2">
            <div class="k-min-w-0">
              <p class="k-text-xs k-font-semibold k-text-knot-text">{{ it.title ?? t('marketplace.editorialUntitled') }}</p>
              <p v-if="it.description" class="k-mt-1 k-text-[11px] k-text-knot-text-muted">{{ it.description }}</p>
            </div>
            <a
              v-if="sanitize(it.href)"
              :href="sanitize(it.href)"
              target="_blank"
              rel="noopener noreferrer"
              class="k-shrink-0 k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
            >
              {{ t('marketplace.resourcesOpen') }}
              <ExternalLink :size="12" aria-hidden="true" />
            </a>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>
