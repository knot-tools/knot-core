<!--
  Loading skeleton for Marketplace P1a.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{ message?: string }>();

const { t } = useI18n();
</script>

<template>
  <div
    class="k-max-w-[1280px] k-mx-auto k-p-6 k-animate-pulse"
    role="status"
    aria-live="polite"
  >
    <div class="k-flex k-items-center k-gap-3 k-mb-6">
      <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-surface-soft" />
      <div class="k-flex-1 k-space-y-2">
        <div class="k-h-4 k-w-1/3 k-rounded k-bg-knot-surface-soft" />
        <div class="k-h-3 k-w-1/2 k-rounded k-bg-knot-surface-soft/80" />
      </div>
    </div>
    <div class="k-rounded-knot-md k-border k-border-knot-border k-p-8 k-space-y-4">
      <div class="k-flex k-items-center k-gap-2 k-text-knot-text-soft k-text-sm">
        <Sparkles :size="14" class="k-animate-spin" />
        {{ message ?? t('marketplace.skeletonLoading') }}
      </div>
      <div class="k-grid k-gap-4 sm:k-grid-cols-3">
        <div
          v-for="n in 3"
          :key="n"
          class="k-h-32 k-rounded-knot-md k-bg-knot-surface-soft"
        />
      </div>
    </div>
  </div>
</template>
