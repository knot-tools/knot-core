<!--
  V2.5.0d — Execution waterfall: vertical list of nodes ordered by
  sequence with a horizontal duration bar and a colour-coded status.
  Capped at 500 logs; if more arrive, we surface a banner pointing the
  operator at the runtime JSON log for the full trace.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, AlertCircle, Loader2, MinusCircle } from 'lucide-vue-next';
import type { ExecutionLog } from '../../lib/api';

const { t } = useI18n();

const props = defineProps<{
  logs: ExecutionLog[];
  truncated?: boolean;
}>();

const totalDuration = computed(() => {
  return props.logs.reduce((sum, log) => sum + (log.durationMs ?? 0), 0);
});

const maxDuration = computed(() => {
  let max = 0;
  for (const log of props.logs) {
    const d = log.durationMs ?? 0;
    if (d > max) max = d;
  }
  // Avoid zero-width bars when every duration is 0 (eg synthetic logs).
  return max === 0 ? 1 : max;
});

function widthPct(durationMs: number | null): string {
  const d = durationMs ?? 0;
  return `${Math.max(2, (d / maxDuration.value) * 100)}%`;
}

function statusColor(status: string): string {
  switch (status) {
    case 'success':
      return 'k-bg-knot-success';
    case 'error':
      return 'k-bg-knot-danger';
    case 'skipped':
      return 'k-bg-knot-text-soft';
    default:
      return 'k-bg-knot-primary';
  }
}

function statusIcon(status: string) {
  switch (status) {
    case 'success':
      return CheckCircle2;
    case 'error':
      return AlertCircle;
    case 'skipped':
      return MinusCircle;
    default:
      return Loader2;
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'success':
      return t('executionWaterfall.ok');
    case 'error':
      return t('executionWaterfall.err');
    case 'skipped':
      return t('executionWaterfall.skipped');
    case 'running':
      return t('executionWaterfall.running');
    default:
      return status;
  }
}

function formatDuration(ms: number | null): string {
  if (ms === null) return t('editor.commonEmDash');
  if (ms < 1000) return `${ms} ms`;
  return `${(ms / 1000).toFixed(2)} s`;
}
</script>

<template>
  <section class="k-rounded-xl k-border k-border-knot-border k-bg-knot-bg-soft k-p-4">
    <header class="k-flex k-items-baseline k-justify-between k-mb-3">
      <h3 class="k-text-sm k-font-semibold k-text-knot-text">
        {{ t('executionWaterfall.title') }}
      </h3>
      <span class="k-text-[11px] k-text-knot-text-soft">
        {{ t('executionWaterfall.lineSummary', { count: logs.length, total: formatDuration(totalDuration) }) }}
      </span>
    </header>

    <div
      v-if="truncated"
      class="k-mb-3 k-rounded-lg k-border k-border-amber-300/60 k-bg-amber-50 k-px-3 k-py-2 k-text-[11px] k-text-amber-900 dark:k-bg-amber-900/30 dark:k-text-amber-200"
    >
      {{ t('executionWaterfall.truncated') }}
    </div>

    <p v-if="logs.length === 0" class="k-text-xs k-text-knot-text-soft">
      {{ t('executionWaterfall.empty') }}
    </p>

    <ol v-else class="k-flex k-flex-col k-gap-1.5">
      <li
        v-for="log in logs"
        :key="log.id"
        class="k-flex k-items-center k-gap-3 k-rounded-md k-bg-knot-bg-elevated k-px-3 k-py-2 k-text-xs"
      >
        <component
          :is="statusIcon(log.status)"
          :size="14"
          :class="
            log.status === 'success'
              ? 'k-text-knot-success'
              : log.status === 'error'
                ? 'k-text-knot-danger'
                : log.status === 'skipped'
                  ? 'k-text-knot-text-soft'
                  : 'k-text-knot-primary'
          "
        />
        <div class="k-flex-shrink-0 k-w-32 k-truncate k-font-mono k-text-[11px] k-text-knot-text-soft">
          {{ log.nodeId }}
        </div>
        <div class="k-flex-shrink-0 k-w-40 k-truncate k-text-knot-text">
          {{ log.nodeType }}
        </div>
        <div class="k-flex-1 k-h-3 k-bg-knot-bg-base k-rounded">
          <div
            :class="['k-h-3 k-rounded', statusColor(log.status)]"
            :style="{ width: widthPct(log.durationMs) }"
          />
        </div>
        <div class="k-flex-shrink-0 k-w-20 k-text-right k-tabular-nums k-text-knot-text-soft">
          {{ formatDuration(log.durationMs) }}
        </div>
        <div class="k-flex-shrink-0 k-w-16 k-text-[10px] k-uppercase k-text-knot-text-soft">
          {{ statusLabel(log.status) }}
        </div>
      </li>
    </ol>
  </section>
</template>
