<!--
  Dashboard view — live counters from /api/health.php
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Activity,
  CheckCircle2,
  AlertTriangle,
  Clock,
  Workflow,
  ServerCog,
  ShieldCheck,
  Loader2,
  RefreshCw,
} from 'lucide-vue-next';
import { knotApi, type HealthSnapshot } from '../lib/api';
import KHero from '../components/ui/KHero.vue';
import UpdatesBadge from '../components/shell/UpdatesBadge.vue';
import StarJourneyPanel from '../components/dashboard/StarJourneyPanel.vue';
import CronHealthBanner from '../components/dashboard/CronHealthBanner.vue';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t, tm } = useI18n();
const messageList = tm as (key: string) => unknown;

const health = ref<HealthSnapshot | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    health.value = await knotApi.health();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('dashboard.heatLoadError');
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

const marketplaceChromeEnabled = computed(() => isMarketplaceUiEnabled());

const heroPill = computed(() => {
  const v = health.value?.version;
  if (v) {
    return t('dashboard.betaPill', { version: v });
  }
  return t('dashboard.betaPillGeneric');
});

const weekdaysShort = computed((): string[] => {
  const raw = messageList('dashboard.weekdaysShort');
  return Array.isArray(raw) ? (raw as string[]) : [];
});

const heatmapMap = computed(() => {
  const map = new Map<string, number>();
  const cells = health.value?.failureHeatmap ?? [];
  for (const c of cells) {
    map.set(`${c.weekday}-${c.hour}`, c.count);
  }
  return map;
});

const heatmapMax = computed(() => {
  let m = 0;
  for (const c of health.value?.failureHeatmap ?? []) {
    m = Math.max(m, c.count);
  }
  return m || 1;
});

/** Rows follow MySQL DAYOFWEEK: 1=Sunday … 7=Saturday */
const heatmapRows = computed(() => {
  const labels = weekdaysShort.value;
  const out: { dow: number; label: string }[] = [];
  for (let dow = 1; dow <= 7; dow++) {
    out.push({
      dow,
      label: labels[dow - 1] ?? String(dow),
    });
  }
  return out;
});

function heatOpacity(dow: number, hour: number): string {
  const n = heatmapMap.value.get(`${dow}-${hour}`) ?? 0;
  if (n <= 0) {
    return '0.06';
  }
  const a = 0.25 + (n / heatmapMax.value) * 0.85;
  return String(Math.min(1, a));
}

const stats = computed(() => {
  const wf = health.value?.workflows ?? {};
  const exec = health.value?.executions ?? {};
  return [
    {
      icon: Workflow,
      label: t('dashboard.statActiveWorkflows'),
      value: wf.active ?? 0,
      accent: 'k-text-knot-primary',
    },
    {
      icon: CheckCircle2,
      label: t('dashboard.statSuccessfulRuns'),
      value: exec.success ?? 0,
      accent: 'k-text-knot-success',
    },
    {
      icon: AlertTriangle,
      label: t('dashboard.statFailedRuns'),
      value: exec.failed ?? 0,
      accent: 'k-text-knot-warning',
    },
    {
      icon: Clock,
      label: t('dashboard.statQueued'),
      value: exec.queued ?? 0,
      accent: 'k-text-knot-accent',
    },
  ];
});

const checks = computed(() => {
  const c = health.value?.checks ?? {};
  return Object.entries(c).map(([key, ok]) => ({ key, ok: !!ok }));
});
</script>

<template>
  <div class="k-p-8 k-max-w-[1200px] k-mx-auto k-space-y-8">
    <KHero
      variant="large"
      pill-emoji="🚀"
      :pill="heroPill"
      :title="t('dashboard.heroTitle')"
      :highlight="t('dashboard.heroHighlight')"
      :subtitle="t('dashboard.heroSubtitle')"
    >
      <template #actions>
        <a
          :href="baseUrl + '?mode=editor'"
          class="k-inline-flex k-items-center k-gap-2 k-px-5 k-py-2.5 k-rounded-knot-pill k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_24px_rgba(99,102,241,0.4)] hover:k-shadow-[0_12px_32px_rgba(99,102,241,0.55)] k-transition-shadow"
        >
          <Workflow :size="14" /> {{ t('dashboard.newWorkflow') }}
        </a>
        <a
          v-if="marketplaceChromeEnabled"
          :href="baseUrl + '?mode=marketplace'"
          class="k-inline-flex k-items-center k-gap-2 k-px-5 k-py-2.5 k-rounded-knot-pill k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary k-transition-colors"
        >
          <ServerCog :size="14" /> {{ t('dashboard.importTemplate') }}
        </a>
      </template>
      <template #right>
        <button
          type="button"
          @click="load"
          :disabled="loading"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-knot-text k-text-xs k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-60 k-transition-colors"
          :title="t('dashboard.refreshHealth')"
        >
          <Loader2 v-if="loading" :size="13" class="k-animate-spin" />
          <RefreshCw v-else :size="13" />
          {{ t('actions.refresh') }}
        </button>
      </template>
    </KHero>

    <UpdatesBadge />

    <CronHealthBanner :health="health" />

    <StarJourneyPanel />

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <section class="k-grid k-grid-cols-1 md:k-grid-cols-2 lg:k-grid-cols-4 k-gap-4">
      <article
        v-for="stat in stats"
        :key="stat.label"
        class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-shadow-knot-sm hover:k-shadow-knot-md k-transition-shadow k-duration-knot k-ease-knot"
      >
        <component :is="stat.icon" :size="22" :class="['k-mb-3', stat.accent]" />
        <div class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold">
          {{ stat.label }}
        </div>
        <div class="k-text-2xl k-font-bold k-text-knot-text k-mt-1">{{ stat.value }}</div>
      </article>
    </section>

    <section v-if="health" class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-overflow-x-auto">
      <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2">
        <AlertTriangle
          :size="16"
          :class="(health.failureHeatmap?.length ?? 0) > 0 ? 'k-text-knot-warning' : 'k-text-knot-text-muted'"
        />
        {{ t('dashboard.failureHeatmapTitle') }}
      </h2>
      <p class="k-mt-2 k-text-xs k-text-knot-text-muted">{{ t('dashboard.failureHeatmapSubtitle') }}</p>

      <p v-if="(health.failureHeatmap?.length ?? 0) === 0" class="k-mt-4 k-text-sm k-text-knot-text-muted">
        {{ t('dashboard.failureHeatmapEmpty') }}
      </p>

      <div v-else class="k-mt-4 k-inline-block">
        <div class="k-flex k-gap-0.5 k-mb-1">
          <div class="k-w-8 k-shrink-0" />
          <div class="k-flex k-gap-0.5">
            <div
              v-for="h in 24"
              :key="'h' + (h - 1)"
              class="k-w-2 k-text-[8px] k-text-knot-text-soft k-text-center k-leading-none"
            >
              <span v-if="(h - 1) % 4 === 0">{{ h - 1 }}</span>
            </div>
          </div>
        </div>
        <div v-for="row in heatmapRows" :key="row.dow" class="k-flex k-items-center k-gap-0.5 k-mb-0.5">
          <div class="k-w-8 k-shrink-0 k-text-[10px] k-text-knot-text-muted k-font-medium">{{ row.label }}</div>
          <div class="k-flex k-gap-0.5">
            <div
              v-for="h in 24"
              :key="row.dow + '-' + (h - 1)"
              class="k-w-2 k-h-4 k-rounded-[2px] k-transition-colors"
              :style="{
                backgroundColor: `rgba(239, 68, 68, ${heatOpacity(row.dow, h - 1)})`,
              }"
              :title="`${row.label} ${h - 1}:00 — ${heatmapMap.get(`${row.dow}-${h - 1}`) ?? 0}`"
            />
          </div>
        </div>
      </div>
    </section>

    <section class="k-grid k-grid-cols-1 lg:k-grid-cols-2 k-gap-4">
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5">
        <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2">
          <ShieldCheck :size="16" class="k-text-knot-success" />
          {{ t('dashboard.envChecks') }}
        </h2>
        <ul class="k-mt-3 k-space-y-2">
          <li
            v-for="check in checks"
            :key="check.key"
            class="k-flex k-items-center k-justify-between k-text-sm"
          >
            <span class="k-font-mono k-text-knot-text-muted">{{ check.key }}</span>
            <span
              :class="[
                'k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold',
                check.ok
                  ? 'k-bg-knot-success-soft k-text-knot-success'
                  : 'k-bg-knot-warning-soft k-text-knot-warning',
              ]"
            >
              {{ check.ok ? t('dashboard.checkOk') : t('dashboard.checkWarn') }}
            </span>
          </li>
        </ul>
      </article>

      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5">
        <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2">
          <ServerCog :size="16" class="k-text-knot-primary" />
          {{ t('dashboard.quickActions') }}
        </h2>
        <div class="k-mt-3 k-flex k-flex-col k-gap-2">
          <a
            :href="baseUrl + '?mode=workflows'"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-primary-soft hover:k-border-knot-primary hover:k-text-knot-primary k-border-2 k-border-knot-border-strong k-transition-all"
          >
            <Workflow :size="14" class="k-text-knot-primary" /> {{ t('dashboard.openWorkflows') }}
          </a>
          <a
            :href="baseUrl + '?mode=executions'"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-primary-soft hover:k-border-knot-primary hover:k-text-knot-primary k-border-2 k-border-knot-border-strong k-transition-all"
          >
            <Activity :size="14" class="k-text-knot-primary" /> {{ t('dashboard.browseExecutions') }}
          </a>
          <a
            :href="baseUrl + '?mode=connectors'"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-primary-soft hover:k-border-knot-primary hover:k-text-knot-primary k-border-2 k-border-knot-border-strong k-transition-all"
          >
            <ServerCog :size="14" class="k-text-knot-accent" /> {{ t('dashboard.connectorCatalog') }}
          </a>
          <a
            :href="baseUrl + '?mode=credentials'"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-primary-soft hover:k-border-knot-primary hover:k-text-knot-primary k-border-2 k-border-knot-border-strong k-transition-all"
          >
            <ShieldCheck :size="14" class="k-text-knot-success" /> {{ t('dashboard.credentials') }}
          </a>
          <a
            :href="baseUrl + '?mode=editor'"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] k-border k-border-transparent hover:k-shadow-[0_12px_24px_rgba(99,102,241,0.45)]"
          >
            {{ t('dashboard.newWorkflowShort') }}
          </a>
        </div>
      </article>
    </section>
  </div>
</template>
