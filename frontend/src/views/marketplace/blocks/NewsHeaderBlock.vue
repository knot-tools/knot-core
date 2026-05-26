<!--
  Hero-style header for editorial news routes.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Bell } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
  kicker?: string;
  title?: string;
  date?: string;
  subtitle?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const headingId = useId();

const heading = computed(() => props.title ?? t('marketplace.newsHeaderFallback'));
const eyebrow = computed(() => props.kicker ?? t('marketplace.newsHeaderKicker'));
</script>

<template>
  <header
    class="k-flex k-items-start k-gap-4 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="banner"
    :aria-label="props.ariaLabel ?? heading"
  >
    <div class="k-h-12 k-w-12 k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-flex k-items-center k-justify-center k-shrink-0">
      <Bell :size="22" aria-hidden="true" />
    </div>
    <div class="k-min-w-0">
      <p class="k-text-[11px] k-font-semibold k-uppercase k-tracking-wide k-text-knot-primary">
        {{ eyebrow }}
      </p>
      <h1 :id="headingId" class="k-mt-1 k-text-2xl k-font-bold k-text-knot-text">
        {{ heading }}
      </h1>
      <p v-if="date" class="k-mt-2 k-text-xs k-text-knot-text-soft">
        <time :datetime="date">{{ date }}</time>
      </p>
      <p v-if="subtitle" class="k-mt-2 k-text-sm k-text-knot-text-muted">
        {{ subtitle }}
      </p>
    </div>
  </header>
</template>
