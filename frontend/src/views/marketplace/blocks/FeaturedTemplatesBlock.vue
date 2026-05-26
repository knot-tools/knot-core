<!--
  Curated templates slice — delegates to TemplateGridBlock with optional slug pins.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, useId } from 'vue';
import { Library } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { knotMarketplaceBlockContextKey } from '../contextKey';

import TemplateGridBlock from './TemplateGridBlock.vue';

const props = withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    limit?: number;
    /** Template slugs to pin (subset order preserved) */
    slugs?: string[];
    ariaLabel?: string;
  }>(),
  { limit: 4, slugs: () => [] },
);

const ctxCheck = inject(knotMarketplaceBlockContextKey);
if (!ctxCheck) {
  throw new Error('FeaturedTemplatesBlock requires Knot marketplace editorial context.');
}

const { t } = useI18n();
const headingId = useId();

const heading = computed(() => props.title ?? t('marketplace.featuredTemplatesTitle'));

const pins = computed(() => (Array.isArray(props.slugs) ? props.slugs : []));
</script>

<template>
  <section
    class="k-space-y-3"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <header class="k-flex k-items-center k-gap-2">
      <div class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-accent-soft k-text-knot-accent k-flex k-items-center k-justify-center">
        <Library :size="18" aria-hidden="true" />
      </div>
      <div>
        <h2 :id="headingId" class="k-text-sm k-font-bold k-text-knot-text">
          {{ heading }}
        </h2>
        <p v-if="subtitle" class="k-text-[11px] k-text-knot-text-muted">
          {{ subtitle }}
        </p>
      </div>
    </header>
    <TemplateGridBlock
      :limit="limit"
      mode="grid"
      :pins="pins.length ? pins : undefined"
      :show-header="false"
    />
  </section>
</template>
