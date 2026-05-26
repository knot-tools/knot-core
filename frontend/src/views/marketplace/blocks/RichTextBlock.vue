<!--
  Rich text block — **plain text / line breaks only** (no HTML rendering).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { FileText } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
  title?: string;
  body?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.richTextTitle'));
</script>

<template>
  <article
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="article"
    :aria-label="props.ariaLabel ?? heading"
  >
    <h2 :id="headingId" class="k-mb-3 k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <FileText :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>
    <div
      class="k-text-sm k-text-knot-text-muted k-whitespace-pre-wrap"
      role="region"
      :aria-labelledby="headingId"
    >
      {{ body ?? '' }}
    </div>
  </article>
</template>
