<!--
  Cron health banner — warn when KnotCronWorker is off or never ran.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, CheckCircle2, ExternalLink } from 'lucide-vue-next';
import type { HealthSnapshot } from '../../lib/api';

const props = defineProps<{
  health: HealthSnapshot | null;
}>();

const { t } = useI18n();

const cron = computed(() => props.health?.doctor?.cron ?? null);

const severity = computed((): 'ok' | 'warn' | 'danger' | null => {
  if (!cron.value) return null;
  if (!cron.value.registered) return 'danger';
  if (!cron.value.enabled || cron.value.globalEnabled === false) return 'warn';
  if (!cron.value.lastRun) return 'warn';
  return 'ok';
});

const message = computed(() => {
  if (!cron.value || !severity.value) return '';
  if (severity.value === 'danger') return t('cronBanner.notRegistered');
  if (!cron.value.enabled || cron.value.globalEnabled === false) return t('cronBanner.disabled');
  if (!cron.value.lastRun) return t('cronBanner.neverRan');
  return t('cronBanner.ok', { lastRun: cron.value.lastRun });
});

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);
</script>

<template>
  <aside
    v-if="severity && severity !== 'ok'"
    class="k-rounded-knot-md k-border k-px-4 k-py-3 k-flex k-flex-col sm:k-flex-row sm:k-items-center k-gap-3"
    :class="
      severity === 'danger'
        ? 'k-bg-knot-danger-soft k-border-knot-danger/30 k-text-knot-danger'
        : 'k-bg-knot-warning-soft k-border-knot-warning/30 k-text-knot-warning'
    "
    data-testid="cron-health-banner"
    role="status"
  >
    <AlertTriangle :size="18" class="k-shrink-0" />
    <div class="k-flex-1 k-min-w-0">
      <p class="k-text-sm k-font-semibold">{{ t('cronBanner.title') }}</p>
      <p class="k-text-xs k-opacity-90 k-mt-0.5">{{ message }}</p>
    </div>
    <a
      :href="baseUrl + '?mode=doctor'"
      class="k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-underline k-shrink-0"
    >
      {{ t('cronBanner.openDoctor') }}
      <ExternalLink :size="12" />
    </a>
  </aside>
  <aside
    v-else-if="severity === 'ok'"
    class="k-rounded-knot-md k-border k-border-knot-success/20 k-bg-knot-success-soft k-text-knot-success k-px-4 k-py-2 k-flex k-items-center k-gap-2"
    data-testid="cron-health-banner-ok"
  >
    <CheckCircle2 :size="14" />
    <p class="k-text-xs k-font-medium">{{ message }}</p>
  </aside>
</template>
