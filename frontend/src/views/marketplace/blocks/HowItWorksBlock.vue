<!--
  Numbered \"how it works\" steps for editorial templates.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { ListOrdered } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

export interface HowStep {
  title?: string;
  description?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    steps?: HowStep[];
    /** Alias */
    items?: HowStep[];
    ariaLabel?: string;
  }>(),
  { steps: () => [], items: () => [] },
);

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.howItWorksTitle'));

const list = computed(() => {
  const s = props.steps?.length ? props.steps : props.items;
  return Array.isArray(s) ? s : [];
});
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-mb-4 k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <ListOrdered :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.howItWorksEmpty') }}</p>

    <ol v-else class="k-space-y-3 k-list-decimal k-pl-5 k-text-knot-text">
      <li v-for="(step, idx) in list" :key="idx" class="k-pl-1">
        <span class="k-text-xs k-font-semibold">{{ step.title ?? t('marketplace.editorialUntitled') }}</span>
        <p v-if="step.description" class="k-mt-1 k-text-[11px] k-text-knot-text-muted">{{ step.description }}</p>
      </li>
    </ol>
  </section>
</template>
