<!--
  Primary editorial CTA band.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

const props = defineProps<{
  title?: string;
  body?: string;
  ctaLabel?: string;
  ctaHref?: string;
  secondaryLabel?: string;
  secondaryHref?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.ctaBlockTitle'));
const label = computed(() => props.ctaLabel ?? t('marketplace.ctaBlockButton'));
const href = computed(() => sanitize(props.ctaHref) ?? sanitize('https://knot.tools/pricing'));
const secondaryHrefResolved = computed(() => sanitize(props.secondaryHref));
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-primary-soft k-p-6 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <div class="k-flex k-flex-col k-gap-4 md:k-flex-row md:k-items-center md:k-justify-between">
      <div class="k-min-w-0">
        <h2 :id="headingId" class="k-text-lg k-font-bold k-text-knot-text">{{ heading }}</h2>
        <p v-if="body" class="k-mt-2 k-text-sm k-text-knot-text-muted">{{ body }}</p>
      </div>
      <div class="k-flex k-flex-wrap k-items-center k-gap-2">
        <a
          :href="href"
          target="_blank"
          rel="noopener noreferrer"
          class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-opacity-95 focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        >
          {{ label }}
          <ArrowRight :size="16" aria-hidden="true" />
        </a>
        <a
          v-if="secondaryHrefResolved && secondaryLabel"
          :href="secondaryHrefResolved"
          target="_blank"
          rel="noopener noreferrer"
          class="k-inline-flex k-items-center k-px-4 k-py-2.5 k-text-sm k-font-semibold k-text-knot-primary hover:k-underline focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        >
          {{ secondaryLabel }}
        </a>
      </div>
    </div>
  </section>
</template>
