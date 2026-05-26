<!--
  Editorial product highlight — title, body, optional CTA and image.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Sparkles } from 'lucide-vue-next';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

const props = defineProps<{
  title?: string;
  body?: string;
  kicker?: string;
  cta?: { label?: string; href?: string };
  image?: { src?: string; alt?: string };
  ariaLabel?: string;
}>();

const headingId = useId();
const { sanitize } = useSanitizedMarketplaceHref();
const heading = computed(() => props.title ?? '');
const hasContent = computed(() => !!(props.title || props.body || props.cta?.href));
</script>

<template>
  <article
    v-if="hasContent"
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm k-flex k-flex-col md:k-flex-row k-gap-5"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <div class="k-flex-1 k-space-y-3">
      <p v-if="props.kicker" class="k-text-[11px] k-font-bold k-uppercase k-tracking-wide k-text-knot-primary">
        {{ props.kicker }}
      </p>
      <h2 :id="headingId" class="k-text-lg k-font-bold k-text-knot-text k-display-2">
        {{ heading }}
      </h2>
      <p v-if="props.body" class="k-text-sm k-text-knot-text-muted k-whitespace-pre-wrap">
        {{ props.body }}
      </p>
      <a
        v-if="sanitize(props.cta?.href)"
        :href="sanitize(props.cta?.href)"
        target="_blank"
        rel="noopener"
        class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-opacity-90"
      >
        {{ props.cta?.label ?? 'En savoir plus' }}
      </a>
    </div>
    <div
      v-if="props.image?.src"
      class="k-shrink-0 k-w-full md:k-w-48 k-h-32 md:k-h-40 k-rounded-knot-sm k-overflow-hidden k-bg-knot-surface-soft k-border k-border-knot-border"
    >
      <img
        :src="props.image.src"
        :alt="props.image.alt ?? heading"
        class="k-w-full k-h-full k-object-cover"
        loading="lazy"
      />
    </div>
    <Sparkles v-else class="k-hidden" aria-hidden="true" />
  </article>
</template>
