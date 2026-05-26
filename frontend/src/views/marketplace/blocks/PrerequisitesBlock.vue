<!--
  Prerequisites checklist (plain text — no HTML).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { ClipboardCheck } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
  defineProps<{
    title?: string;
    /** String rows or { title } objects */
    items?: Array<string | { title?: string }>;
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.prerequisitesTitle'));

const rows = computed(() =>
  (Array.isArray(props.items) ? props.items : []).map((row) =>
    typeof row === 'string' ? row : (row.title ?? ''),
  ),
);
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-5"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-mb-3 k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <ClipboardCheck :size="16" class="k-text-knot-success" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="rows.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.prerequisitesEmpty') }}</p>

    <ul v-else class="k-space-y-2">
      <li
        v-for="(line, idx) in rows"
        :key="idx"
        class="k-flex k-items-start k-gap-2 k-text-xs k-text-knot-text-muted"
      >
        <span class="k-mt-0.5 k-text-knot-success" aria-hidden="true">✓</span>
        <span>{{ line }}</span>
      </li>
    </ul>
  </section>
</template>
