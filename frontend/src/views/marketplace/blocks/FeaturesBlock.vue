<!--
  Feature bullet grid (icon + title + body).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Sparkles } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

export interface FeatureRow {
  title?: string;
  description?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    /** Alias for checklist-style payloads */
    features?: FeatureRow[];
    items?: FeatureRow[];
    ariaLabel?: string;
  }>(),
  { features: () => [], items: () => [] },
);

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.featuresTitle'));

const rows = computed(() => {
  const f = props.features?.length ? props.features : props.items;
  return Array.isArray(f) ? f : [];
});
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <header class="k-mb-4">
      <h2 :id="headingId" class="k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
        <Sparkles :size="16" class="k-text-knot-accent" aria-hidden="true" />
        {{ heading }}
      </h2>
      <p v-if="subtitle" class="k-mt-1 k-text-xs k-text-knot-text-muted">{{ subtitle }}</p>
    </header>

    <p v-if="rows.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.featuresEmpty') }}</p>

    <ul v-else class="k-grid k-gap-3 sm:k-grid-cols-2 k-list-none">
      <li
        v-for="(row, idx) in rows"
        :key="idx"
        class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-3"
      >
        <p class="k-text-xs k-font-semibold k-text-knot-text">{{ row.title ?? t('marketplace.editorialUntitled') }}</p>
        <p v-if="row.description" class="k-mt-1 k-text-[11px] k-text-knot-text-muted">{{ row.description }}</p>
      </li>
    </ul>
  </section>
</template>
