<!--
  Observability — workflow + node aggregates from api/observability.php
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Activity, Layers, Loader2, RefreshCw } from 'lucide-vue-next';
import { knotApi, type ObservabilitySnapshot } from '../lib/api';
import KHero from '../components/ui/KHero.vue';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t, tm } = useI18n();
const messageList = tm as (key: string) => unknown;

const loading = ref(true);
const error = ref<string | null>(null);
const snapshot = ref<ObservabilitySnapshot | null>(null);
const windowDays = ref(7);

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    snapshot.value = await knotApi.observability({ days: windowDays.value });
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('observability.loadError');
  } finally {
    loading.value = false;
  }
}

onMounted(load);

watch(windowDays, () => {
  void load();
});

function errorRatePct(row: { runs: number; errors: number }): string {
  if (row.runs <= 0) {
    return '—';
  }
  return `${((row.errors / row.runs) * 100).toFixed(1)}%`;
}

function formatMs(ms: number | null): string {
  if (ms === null || Number.isNaN(ms)) {
    return '—';
  }
  if (ms < 1000) {
    return `${Math.round(ms)} ms`;
  }
  return `${(ms / 1000).toFixed(2)} s`;
}

const heatmapMax = computed(() => {
  let m = 0;
  for (const c of snapshot.value?.failure_heatmap ?? []) {
    m = Math.max(m, c.count);
  }
  return m || 1;
});

const heatmapMap = computed(() => {
  const map = new Map<string, number>();
  for (const c of snapshot.value?.failure_heatmap ?? []) {
    map.set(`${c.weekday}-${c.hour}`, c.count);
  }
  return map;
});

function heatOpacity(dow: number, hour: number): string {
  const n = heatmapMap.value.get(`${dow}-${hour}`) ?? 0;
  if (n <= 0) {
    return '0.06';
  }
  const a = 0.25 + (n / heatmapMax.value) * 0.85;
  return String(Math.min(1, a));
}

/** Rows follow MySQL DAYOFWEEK: 1=Sunday … 7=Saturday */
const heatmapRows = computed(() => {
  const raw = messageList('dashboard.weekdaysShort');
  const labels = Array.isArray(raw) ? (raw as string[]) : [];
  const out: { dow: number; label: string }[] = [];
  for (let dow = 1; dow <= 7; dow++) {
    out.push({
      dow,
      label: labels[dow - 1] ?? String(dow),
    });
  }
  return out;
});
</script>

<template>
  <div class="k-p-8 k-max-w-[1200px] k-mx-auto k-space-y-8">
    <KHero
      variant="compact"
      pill-emoji="📊"
      :pill="t('observability.heroPill')"
      :title="t('observability.heroTitle')"
      :subtitle="t('observability.heroSubtitle')"
    >
      <template #actions>
        <div class="k-flex k-flex-wrap k-items-center k-gap-2">
          <a
            :href="baseUrl + '?mode=executions'"
            class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-pill k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary k-transition-colors"
          >
            <Activity :size="14" /> {{ t('observability.openExecutions') }}
          </a>
          <a
            :href="baseUrl + '?mode=executions&status=failed'"
            class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-pill k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-danger hover:k-text-knot-danger k-transition-colors"
          >
            <Activity :size="14" /> {{ t('observability.openFailedExecutions') }}
          </a>
        </div>
      </template>
      <template #right>
        <div class="k-flex k-flex-wrap k-items-center k-gap-2">
          <label class="k-flex k-items-center k-gap-2 k-text-xs k-text-knot-text-muted">
            <span>{{ t('observability.window') }}</span>
            <select
              v-model.number="windowDays"
              class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-px-2 k-py-1 k-text-sm k-text-knot-text"
            >
              <option :value="7">7 {{ t('observability.days') }}</option>
              <option :value="14">14 {{ t('observability.days') }}</option>
              <option :value="30">30 {{ t('observability.days') }}</option>
            </select>
          </label>
          <button
            type="button"
            class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-glass-bg k-border k-border-knot-border-strong k-text-knot-text k-text-xs k-font-semibold hover:k-border-knot-primary disabled:k-opacity-60"
            :disabled="loading"
            @click="load"
          >
            <Loader2 v-if="loading" :size="13" class="k-animate-spin" />
            <RefreshCw v-else :size="13" />
            {{ t('actions.refresh') }}
          </button>
        </div>
      </template>
    </KHero>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <section v-if="snapshot" class="k-grid k-grid-cols-1 md:k-grid-cols-2 k-gap-4">
      <article
        class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-shadow-knot-sm"
      >
        <div class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold">
          {{ t('observability.queueWaiting') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-text k-mt-1">{{ snapshot.queue.waiting }}</div>
      </article>
      <article
        class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-shadow-knot-sm"
      >
        <div class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold">
          {{ t('observability.queueRunning') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-text k-mt-1">{{ snapshot.queue.running }}</div>
      </article>
    </section>

    <section v-if="snapshot" class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-overflow-x-auto">
      <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2">
        <Layers :size="16" class="k-text-knot-primary" />
        {{ t('observability.nodesByType') }}
      </h2>
      <p class="k-mt-1 k-text-xs k-text-knot-text-muted">{{ t('observability.nodesHint') }}</p>

      <table
        v-if="snapshot.nodes_by_type.length > 0"
        class="k-mt-4 k-w-full k-text-sm k-border-collapse"
      >
        <thead>
          <tr class="k-text-left k-text-knot-text-muted k-text-xs k-uppercase">
            <th class="k-pb-2 k-pr-4">{{ t('observability.colNodeType') }}</th>
            <th class="k-pb-2 k-pr-4">{{ t('observability.colRuns') }}</th>
            <th class="k-pb-2 k-pr-4">{{ t('observability.colErrors') }}</th>
            <th class="k-pb-2 k-pr-4">{{ t('observability.colErrorRate') }}</th>
            <th class="k-pb-2">{{ t('observability.colAvgDuration') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in snapshot.nodes_by_type"
            :key="row.node_type"
            class="k-border-t k-border-knot-border"
          >
            <td class="k-py-2 k-pr-4 k-font-mono k-text-xs">{{ row.node_type }}</td>
            <td class="k-py-2 k-pr-4">{{ row.runs }}</td>
            <td class="k-py-2 k-pr-4">{{ row.errors }}</td>
            <td class="k-py-2 k-pr-4">{{ errorRatePct(row) }}</td>
            <td class="k-py-2">{{ formatMs(row.avg_duration_ms) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="k-mt-4 k-text-sm k-text-knot-text-muted">{{ t('observability.nodesEmpty') }}</p>
    </section>

    <section v-if="snapshot" class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-overflow-x-auto">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('dashboard.failureHeatmapTitle') }}</h2>
      <p class="k-mt-2 k-text-xs k-text-knot-text-muted">{{ t('dashboard.failureHeatmapSubtitle') }}</p>

      <p v-if="snapshot.failure_heatmap.length === 0" class="k-mt-4 k-text-sm k-text-knot-text-muted">
        {{ t('dashboard.failureHeatmapEmpty') }}
      </p>

      <div v-else class="k-mt-4 k-inline-block">
        <div class="k-flex k-gap-0.5 k-mb-1">
          <div class="k-w-8 k-shrink-0" />
          <div class="k-flex k-gap-0.5">
            <div
              v-for="h in 24"
              :key="'oh' + (h - 1)"
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
  </div>
</template>
