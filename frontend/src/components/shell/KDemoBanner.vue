<!--
  Demo environment banner — ONLY when demoMode is explicitly true.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { knotApi } from '../../lib/api';

const { t } = useI18n();
const demoMode = ref<boolean | undefined>(undefined);

onMounted(async () => {
  try {
    const health = await knotApi.health();
    demoMode.value = health.demoMode === true;
  } catch {
    demoMode.value = false;
  }
});

const showBanner = computed(() => demoMode.value === true);
</script>

<template>
  <div
    v-if="showBanner"
    class="k-shrink-0 k-bg-knot-warning-soft k-border-b k-border-knot-warning/40 k-text-knot-text k-px-4 k-py-2 k-text-sm k-text-center"
    role="alert"
    data-testid="knot-demo-banner"
  >
    <span>{{ t('shell.demoBanner.message') }}</span>
    <a
      href="/try/"
      class="k-ml-2 k-text-knot-primary k-font-medium hover:k-underline"
    >
      {{ t('shell.demoBanner.learnMore') }}
    </a>
  </div>
</template>
