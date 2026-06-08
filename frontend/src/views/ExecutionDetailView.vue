<!--
  V2.5.0d — Execution detail view: header summary + waterfall timeline
  + raw node logs. Reachable through ?mode=execution&id=N to support
  deep-linking from emails, alerts and the executions list.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import {
  ArrowLeft,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  Clock,
  Loader2,
  Ban,
  RotateCcw,
} from 'lucide-vue-next';
import { knotApi, type ExecutionLog, type ExecutionSummary } from '../lib/api';
import { formatExecutionDuration, resolveExecutionDurationMs } from '../lib/executionFormat';
import ExecutionWaterfall from '../components/executions/ExecutionWaterfall.vue';
import ExecutionErrorPanel from '../components/risk/ExecutionErrorPanel.vue';
import { useI18n } from 'vue-i18n';

const { t, te } = useI18n();

const props = defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const execution = ref<ExecutionSummary | null>(null);
const logs = ref<ExecutionLog[]>([]);
const truncated = ref(false);
const totalLogs = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);

const targetId = computed(() => props.executionId ?? null);

async function load() {
  if (targetId.value === null) {
    error.value = t('executionDetail.noExecutionId');
    return;
  }
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.getExecution(targetId.value);
    execution.value = result.execution;
    logs.value = result.logs;
    truncated.value = result.truncated === true;
    totalLogs.value = result.totalLogs ?? result.logs.length;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionDetail.loadFailed');
  } finally {
    loading.value = false;
  }
}

function statusClass(status: string): string {
  switch (status) {
    case 'success':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'error':
    case 'failed':
    case 'timeout':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    case 'running':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'queued':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    default:
      return 'k-bg-knot-bg-elevated k-text-knot-text-soft';
  }
}

function statusIcon(status: string) {
  switch (status) {
    case 'success':
      return CheckCircle2;
    case 'error':
    case 'failed':
    case 'timeout':
      return AlertCircle;
    case 'running':
      return Loader2;
    case 'queued':
      return Clock;
    default:
      return Clock;
  }
}

const executionDurationMs = computed(() => {
  const row = execution.value;
  if (!row) {
    return null;
  }
  return resolveExecutionDurationMs(row.durationMs, row.startedAt, row.endedAt);
});

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

function backToList() {
  const url = new URL(window.location.href);
  url.searchParams.set('mode', 'executions');
  url.searchParams.delete('id');
  window.location.href = url.toString();
}

async function cancel() {
  if (!execution.value) return;
  try {
    await knotApi.cancelExecution(execution.value.id);
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.cancelFailed');
  }
}

async function retry() {
  if (!execution.value) return;
  try {
    const result = await knotApi.retryExecution(execution.value.id);
    const url = new URL(window.location.href);
    url.searchParams.set('id', String(result.executionId));
    window.location.href = url.toString();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.retryFailed');
  }
}

const isPending = computed(
  () => execution.value?.status === 'queued' || execution.value?.status === 'running',
);
const isRetryable = computed(() => {
  const s = execution.value?.status;
  return s === 'error' || s === 'cancelled' || s === 'timeout' || s === 'failed';
});

watch(targetId, () => {
  void load();
});

onMounted(() => {
  void load();
});

function executionStatusLabel(status: string): string {
  const key = `status.${status}`;
  return te(key) ? t(key) : status;
}
</script>

<template>
  <main class="k-min-h-full k-bg-knot-bg k-px-6 k-py-6">
    <header class="k-mb-6 k-flex k-items-center k-gap-3">
      <button
        type="button"
        class="k-inline-flex k-items-center k-gap-2 k-rounded-md k-border k-border-knot-border k-px-3 k-py-1.5 k-text-sm k-text-knot-text-soft hover:k-bg-knot-bg-elevated"
        @click="backToList"
      >
        <ArrowLeft :size="14" />
        {{ t('executionDetail.back') }}
      </button>
      <h1 class="k-text-xl k-font-semibold k-text-knot-text">
        {{ t('executionDetail.title', { id: targetId ?? '—' }) }}
      </h1>
      <button
        type="button"
        class="k-ml-auto k-inline-flex k-items-center k-gap-2 k-rounded-md k-border k-border-knot-border k-px-3 k-py-1.5 k-text-sm k-text-knot-text-soft hover:k-bg-knot-bg-elevated"
        :disabled="loading"
        @click="load"
      >
        <RefreshCw :size="14" :class="loading ? 'k-animate-spin' : ''" />
        {{ t('executionDetail.refresh') }}
      </button>
    </header>

    <div
      v-if="error"
      class="k-mb-4 k-rounded-md k-border k-border-knot-danger/40 k-bg-knot-danger-soft k-px-3 k-py-2 k-text-sm k-text-knot-danger"
    >
      {{ error }}
    </div>

    <div v-if="loading && !execution" class="k-text-sm k-text-knot-text-soft">
      {{ t('executionDetail.loading') }}
    </div>

    <template v-if="execution">
      <section
        class="k-mb-6 k-grid k-gap-4 k-rounded-xl k-border k-border-knot-border k-bg-knot-bg-soft k-p-4 md:k-grid-cols-4"
      >
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelStatus') }}</div>
          <span
            :class="[
              'k-mt-1 k-inline-flex k-items-center k-gap-2 k-rounded-full k-px-2.5 k-py-1 k-text-xs k-font-medium',
              statusClass(execution.status),
            ]"
          >
            <component :is="statusIcon(execution.status)" :size="12" />
            {{ executionStatusLabel(execution.status) }}
          </span>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelWorkflow') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text">
            #{{ execution.workflowId }}
          </div>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelDuration') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text k-tabular-nums">
            {{ formatExecutionDuration(executionDurationMs) }}
          </div>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelTrigger') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text">
            {{ execution.triggerType || '—' }}
          </div>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelStarted') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text">
            {{ formatDate(execution.startedAt) }}
          </div>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelEnded') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text">
            {{ formatDate(execution.endedAt) }}
          </div>
        </div>
        <div>
          <div class="k-text-[11px] k-uppercase k-text-knot-text-soft">{{ t('executionDetail.labelAttempts') }}</div>
          <div class="k-mt-1 k-text-sm k-text-knot-text k-tabular-nums">
            {{ execution.retryCount }}
          </div>
        </div>
        <div class="k-flex k-items-end k-justify-end k-gap-2">
          <button
            v-if="isPending"
            type="button"
            class="k-inline-flex k-items-center k-gap-2 k-rounded-md k-border k-border-knot-danger/40 k-bg-knot-danger-soft k-px-3 k-py-1.5 k-text-xs k-text-knot-danger hover:k-bg-knot-danger/10"
            @click="cancel"
          >
            <Ban :size="14" />
            {{ t('executionDetail.cancel') }}
          </button>
          <button
            v-if="isRetryable"
            type="button"
            class="k-inline-flex k-items-center k-gap-2 k-rounded-md k-border k-border-knot-border k-px-3 k-py-1.5 k-text-xs k-text-knot-text hover:k-bg-knot-bg-elevated"
            @click="retry"
          >
            <RotateCcw :size="14" />
            {{ t('executionDetail.retry') }}
          </button>
        </div>
      </section>

      <div
        v-if="execution?.errorMessage || execution?.errorPayload"
        class="k-mb-6"
      >
        <ExecutionErrorPanel
          v-if="execution.errorPayload && Object.keys(execution.errorPayload).length > 0"
          :payload="execution.errorPayload"
        />
        <div
          v-else-if="execution.errorMessage"
          class="k-rounded-md k-border k-border-knot-danger/40 k-bg-knot-danger-soft k-px-3 k-py-2 k-text-sm k-text-knot-danger"
        >
          <strong class="k-font-semibold">{{ t('executionDetail.errorPrefix') }}</strong>
          <span class="k-font-mono k-text-xs">{{ execution.errorMessage }}</span>
        </div>
      </div>

      <ExecutionWaterfall
        :logs="logs"
        :truncated="truncated"
        class="k-mb-6"
      />

      <section class="k-rounded-xl k-border k-border-knot-border k-bg-knot-bg-soft k-p-4">
        <header class="k-mb-3 k-flex k-items-baseline k-justify-between">
          <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ t('executionDetail.nodeDetailTitle') }}</h3>
          <span class="k-text-[11px] k-text-knot-text-soft">{{ t('executionDetail.eventsCount', { n: totalLogs }) }}</span>
        </header>
        <ul v-if="logs.length > 0" class="k-flex k-flex-col k-gap-2">
          <li
            v-for="log in logs"
            :key="log.id"
            class="k-rounded-md k-bg-knot-bg-elevated k-px-3 k-py-2 k-text-xs"
          >
            <div class="k-flex k-items-center k-gap-2">
              <span
                :class="[
                  'k-inline-flex k-items-center k-gap-1 k-rounded-full k-px-2 k-py-0.5 k-text-[10px] k-font-medium',
                  statusClass(log.status),
                ]"
              >
                {{ executionStatusLabel(log.status) }}
              </span>
              <span class="k-font-mono k-text-knot-text-soft">{{ log.nodeId }}</span>
              <span class="k-text-knot-text">{{ log.nodeType }}</span>
              <span class="k-ml-auto k-tabular-nums k-text-knot-text-soft">
                {{ formatExecutionDuration(log.durationMs) }}
              </span>
            </div>
            <div
              v-if="log.errorMessage"
              class="k-mt-1 k-font-mono k-text-[11px] k-text-knot-danger"
            >
              {{ log.errorMessage }}
            </div>
          </li>
        </ul>
        <p v-else class="k-text-xs k-text-knot-text-soft">{{ t('executionDetail.noLogs') }}</p>
      </section>
    </template>
  </main>
</template>
