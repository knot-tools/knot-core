<!--
  Suite health page — products, licences, cron (ADR extension navigation).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import SuiteHealthPanel from '../components/dashboard/SuiteHealthPanel.vue';
import CronHealthBanner from '../components/dashboard/CronHealthBanner.vue';
import UpdatesBadge from '../components/shell/UpdatesBadge.vue';
import { computed, onMounted, ref } from 'vue';
import { knotApi, type HealthSnapshot } from '../lib/api';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t } = useI18n();
const health = ref<HealthSnapshot | null>(null);

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

onMounted(async () => {
  try {
    health.value = await knotApi.health();
  } catch {
    health.value = null;
  }
});
</script>

<template>
  <div class="k-flex k-flex-col k-gap-5 k-p-4 md:k-p-6" data-testid="suite-health-page">
    <header class="k-flex k-flex-col k-gap-2">
      <p class="k-text-xs k-font-bold k-uppercase k-tracking-wider k-text-knot-text-muted">
        {{ t('suiteHome.eyebrow') }}
      </p>
      <h1 class="k-text-2xl k-font-bold k-text-knot-text k-m-0">
        {{ t('suiteHealth.pageTitle') }}
      </h1>
      <p class="k-text-sm k-text-knot-text-muted k-max-w-2xl k-m-0">
        {{ t('suiteHealth.pageLead') }}
      </p>
      <a
        :href="`${baseUrl}/workflows/preview.php?mode=updates`"
        class="k-self-start k-text-sm k-font-semibold k-text-knot-primary k-no-underline hover:k-underline"
      >
        {{ t('suiteHealth.openUpdates') }}
      </a>
    </header>

    <UpdatesBadge />
    <CronHealthBanner :health="health" />
    <SuiteHealthPanel />
  </div>
</template>
