<!--
  Executions view — history list + queue ops (tabs), detail drawer.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  History,
  CheckCircle2,
  AlertCircle,
  Loader2,
  Clock,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
  X,
  Play,
  Ban,
  RotateCcw,
  Zap,
  Trash2,
  ListOrdered,
  Layers,
  Pencil,
  Eye,
  ExternalLink,
} from 'lucide-vue-next';
import {
  knotApi,
  type ExecutionLog,
  type ExecutionSummary,
  type QueueDashboardData,
  type QueueRetryRow,
  type QueueWorkflowQueuedRow,
} from '../lib/api';
import { formatExecutionDuration, resolveExecutionDurationMs } from '../lib/executionFormat';
import { useToast } from '../composables/useToast';
import { useConfirm } from '../composables/useConfirm';

const toastApi = useToast();
const confirmDialog = useConfirm();

const props = defineProps<{
  workflowId: number | null;
  executionId: number | null;
  executionTab?: string | null;
}>();

const { t, te } = useI18n();

const executions = ref<ExecutionSummary[]>([]);
const counts = ref<Record<string, number>>({});
const loading = ref(false);
const error = ref<string | null>(null);

const page = ref(0);
const pageSize = 25;

const selected = ref<ExecutionSummary | null>(null);
const selectedLogs = ref<ExecutionLog[]>([]);
const detailLoading = ref(false);
const replayingNode = ref<string | null>(null);
const actionRunning = ref<{ id: number; kind: 'cancel' | 'retry' | 'run' } | null>(null);

const activeTab = ref<'history' | 'queue'>(props.executionTab === 'queue' ? 'queue' : 'history');

const queueData = ref<QueueDashboardData | null>(null);
const queueLoading = ref(false);
const queueError = ref<string | null>(null);
const purging = ref(false);
const purgeDays = ref(7);

const historySearch = ref('');
const queueSearch = ref('');

type ExecutionHistoryStatusFilter =
  | 'queued'
  | 'running'
  | 'success'
  | 'failed'
  | 'error'
  | 'timeout'
  | 'cancelled';

const ALLOWED_HISTORY_STATUS_PARAMS = new Set([
  'queued',
  'running',
  'success',
  'failed',
  'error',
  'timeout',
  'cancelled',
]);

const historyStatusFilter = ref<ExecutionHistoryStatusFilter | null>(null);

const KPI_FILTER_KEYS = ['queued', 'running', 'success', 'failed'] as const;

function parseStatusFromUrl(): ExecutionHistoryStatusFilter | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = new URL(window.location.href).searchParams.get('status');
    const v = (raw || '').toLowerCase().trim();
    if (!v || !ALLOWED_HISTORY_STATUS_PARAMS.has(v)) return null;
    return v as ExecutionHistoryStatusFilter;
  } catch {
    return null;
  }
}

function syncExecutionStatusUrl(): void {
  if (typeof window === 'undefined') return;
  try {
    const u = new URL(window.location.href);
    if (historyStatusFilter.value) {
      u.searchParams.set('status', historyStatusFilter.value);
    } else {
      u.searchParams.delete('status');
    }
    window.history.replaceState({}, '', u.toString());
  } catch {
    /* ignore */
  }
}

function onKpiCardActivate(labelKey: string): void {
  if (!KPI_FILTER_KEYS.includes(labelKey as (typeof KPI_FILTER_KEYS)[number])) return;
  const key = labelKey as ExecutionHistoryStatusFilter;
  const fromQueue = activeTab.value === 'queue';
  if (!fromQueue && historyStatusFilter.value === key) {
    historyStatusFilter.value = null;
  } else {
    historyStatusFilter.value = key;
    if (fromQueue) {
      setTab('history');
    }
  }
  page.value = 0;
  syncExecutionStatusUrl();
  void load();
}

function kpiCardClasses(labelKey: string): string {
  const base =
    'k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-flex k-items-center k-gap-3 k-transition-shadow';
  const interactive =
    'k-cursor-pointer hover:k-border-knot-primary focus-visible:k-outline-none focus-visible:k-ring-2 focus-visible:k-ring-knot-primary k-ring-offset-2 k-ring-offset-knot-bg';
  const selected =
    historyStatusFilter.value === labelKey ? ' k-ring-2 k-ring-knot-primary k-border-knot-primary' : '';
  return `${base} ${interactive}${selected}`;
}

const historySearchPlaceholder = computed(() =>
  historyStatusFilter.value
    ? t('executionsPage.searchHistoryPlaceholderFiltered')
    : t('executionsPage.searchHistoryPlaceholder'),
);

function previewBase(): string {
  if (typeof window !== 'undefined' && window.KNOT_BASE_URL) {
    return window.KNOT_BASE_URL.replace(/\/+$/, '');
  }
  return '';
}

function editorWorkflowUrl(workflowId: number): string {
  const base = previewBase();
  if (!base) return '#';
  return `${base}?mode=editor&workflow_id=${workflowId}`;
}

function executionDetailUrl(executionId: number): string {
  const base = previewBase();
  if (!base) return '#';
  return `${base}?mode=execution&execution_id=${executionId}`;
}

function setTab(tab: 'history' | 'queue') {
  activeTab.value = tab;
  try {
    const u = new URL(window.location.href);
    if (tab === 'queue') {
      u.searchParams.set('execution_tab', 'queue');
    } else {
      u.searchParams.delete('execution_tab');
    }
    window.history.replaceState({}, '', u.toString());
  } catch {
    /* ignore */
  }
}

function workflowPrimaryLabel(summary: ExecutionSummary): string {
  return summary.workflowLabel?.trim() || `#${summary.workflowId}`;
}

function workflowSecondaryLabel(summary: ExecutionSummary): string {
  const ref = summary.workflowRef?.trim();
  if (ref) return `${ref} · #${summary.workflowId}`;
  return `#${summary.workflowId}`;
}

function retryWorkflowTitle(row: QueueRetryRow): string {
  return row.workflowLabel?.trim() || `#${row.workflowId}`;
}

function queuedWorkflowTitle(row: QueueWorkflowQueuedRow): string {
  return row.workflowLabel?.trim() || `#${row.workflowId}`;
}

function matchesQueueFilter(text: string): boolean {
  const q = queueSearch.value.trim().toLowerCase();
  if (!q) return true;
  return text.toLowerCase().includes(q);
}

const filteredExecutions = computed(() => {
  const q = historySearch.value.trim().toLowerCase();
  if (!q) return executions.value;
  return executions.value.filter((e) => {
    const blob = [
      String(e.id),
      String(e.workflowId),
      e.workflowLabel ?? '',
      e.workflowRef ?? '',
      e.triggerType,
      e.status,
      e.errorMessage ?? '',
    ]
      .join(' ')
      .toLowerCase();
    return blob.includes(q);
  });
});

const filteredQueuedByWorkflow = computed(() => {
  const rows = queueData.value?.queuedByWorkflow ?? [];
  return rows.filter((row) =>
    matchesQueueFilter(
      [String(row.workflowId), row.workflowLabel ?? '', row.workflowRef ?? '', String(row.queuedCount)].join(
        ' ',
      ),
    ),
  );
});

const filteredTopRetries = computed(() => {
  const rows = queueData.value?.topRetries ?? [];
  return rows.filter((row) =>
    matchesQueueFilter(
      [
        String(row.id),
        String(row.workflowId),
        row.workflowLabel ?? '',
        row.workflowRef ?? '',
        row.status,
        row.errorMessage ?? '',
        row.nextRetryAt ?? '',
      ].join(' '),
    ),
  );
});

async function loadQueue() {
  queueLoading.value = true;
  queueError.value = null;
  try {
    queueData.value = await knotApi.queueDashboard();
  } catch (err) {
    queueError.value = err instanceof Error ? err.message : String(err);
  } finally {
    queueLoading.value = false;
  }
}

async function purgeFailures() {
  const ok = await confirmDialog.confirm({
    title: t('executionsPage.purgeTitle'),
    message: String(t('executionsPage.purgeConfirm', { days: purgeDays.value })),
    danger: true,
  });
  if (!ok) return;
  purging.value = true;
  queueError.value = null;
  try {
    await knotApi.purgeFailedExecutions(purgeDays.value);
    await loadQueue();
    await load();
    flash(t('executionsPage.purgeDone'));
  } catch (err) {
    queueError.value = err instanceof Error ? err.message : String(err);
  } finally {
    purging.value = false;
  }
}

function flash(message: string) {
  toastApi.success(message);
}

function isPending(status: string): boolean {
  return status === 'queued' || status === 'running';
}

function isRetryable(status: string): boolean {
  return status === 'error' || status === 'failed' || status === 'cancelled' || status === 'timeout';
}

function executionDurationMs(exec: ExecutionSummary): number | null {
  return resolveExecutionDurationMs(exec.durationMs, exec.startedAt, exec.endedAt);
}

async function cancelExec(exec: ExecutionSummary, fromDrawer = false) {
  actionRunning.value = { id: exec.id, kind: 'cancel' };
  error.value = null;
  try {
    await knotApi.cancelExecution(exec.id);
    flash(t('executionsPage.executionCancelled', { id: exec.id }));
    await load();
    if (fromDrawer && selected.value?.id === exec.id) {
      const refreshed = await knotApi.getExecution(exec.id);
      selected.value = refreshed.execution;
      selectedLogs.value = refreshed.logs;
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.cancelFailed');
  } finally {
    actionRunning.value = null;
  }
}

async function retryExec(exec: ExecutionSummary) {
  actionRunning.value = { id: exec.id, kind: 'retry' };
  error.value = null;
  try {
    const result = await knotApi.retryExecution(exec.id);
    flash(t('executionsPage.retryQueued', { id: result.executionId }));
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.retryFailed');
  } finally {
    actionRunning.value = null;
  }
}

async function runNow(exec: ExecutionSummary) {
  actionRunning.value = { id: exec.id, kind: 'run' };
  error.value = null;
  try {
    const result = await knotApi.runExecutionNow(exec.id);
    flash(t('executionsPage.executionStarted', { id: exec.id, status: result.execution.status }));
    await load();
    if (selected.value?.id === exec.id) {
      const refreshed = await knotApi.getExecution(exec.id);
      selected.value = refreshed.execution;
      selectedLogs.value = refreshed.logs;
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.runNowFailed');
  } finally {
    actionRunning.value = null;
  }
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.listExecutions({
      workflowId: props.workflowId ?? undefined,
      limit: pageSize,
      offset: page.value * pageSize,
      status: historyStatusFilter.value ?? undefined,
    });
    executions.value = result.executions;
    counts.value = result.counts;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.loadExecutionsFailed');
  } finally {
    loading.value = false;
  }
}

async function refreshView() {
  if (activeTab.value === 'queue') {
    await loadQueue();
    await load();
    return;
  }
  await load();
}

async function openDetail(execution: ExecutionSummary) {
  selected.value = execution;
  selectedLogs.value = [];
  detailLoading.value = true;
  try {
    const result = await knotApi.getExecution(execution.id);
    selected.value = result.execution;
    selectedLogs.value = result.logs;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.loadExecutionFailed');
  } finally {
    detailLoading.value = false;
  }
}

const totals = computed(() => {
  const c = counts.value ?? {};
  return [
    { labelKey: 'queued', value: c.queued ?? 0, accent: 'k-text-knot-accent', icon: Clock },
    { labelKey: 'running', value: c.running ?? 0, accent: 'k-text-knot-primary', icon: Loader2 },
    { labelKey: 'success', value: c.success ?? 0, accent: 'k-text-knot-success', icon: CheckCircle2 },
    { labelKey: 'failed', value: c.failed ?? 0, accent: 'k-text-knot-danger', icon: AlertCircle },
  ];
});

const queueTotals = computed(() => {
  const c = queueData.value?.counts ?? counts.value ?? {};
  return [
    { labelKey: 'queued', value: c.queued ?? 0, accent: 'k-text-knot-accent', icon: Clock },
    { labelKey: 'running', value: c.running ?? 0, accent: 'k-text-knot-primary', icon: Loader2 },
    { labelKey: 'success', value: c.success ?? 0, accent: 'k-text-knot-success', icon: CheckCircle2 },
    { labelKey: 'failed', value: c.failed ?? 0, accent: 'k-text-knot-danger', icon: AlertCircle },
  ];
});

function kpiLabel(labelKey: string): string {
  if (labelKey === 'failed') return t('executionsPage.kpiFailed');
  return t(`status.${labelKey}`);
}

function statusBadge(status: string) {
  switch (status) {
    case 'success':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'failed':
    case 'error':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    case 'running':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'queued':
    default:
      return 'k-bg-knot-warning-soft k-text-knot-warning';
  }
}

function logBadge(status: string) {
  switch (status) {
    case 'success':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'skipped':
      return 'k-bg-knot-surface-soft k-text-knot-text-soft';
    case 'failed':
    case 'error':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    default:
      return 'k-bg-knot-primary-soft k-text-knot-primary';
  }
}

async function replayFromLog(log: ExecutionLog) {
  if (!selected.value) return;
  replayingNode.value = log.nodeId;
  error.value = null;
  try {
    const result = await knotApi.replayWorkflowFromNode(selected.value.workflowId, log.nodeId, log.input);
    flash(t('executionsPage.replayQueuedMsg', { id: result.executionId }));
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.replayFailed');
  } finally {
    replayingNode.value = null;
  }
}

function executionStatusLabel(status: string): string {
  const key = `status.${status}`;
  return te(key) ? t(key) : status;
}

function logStatusLabel(status: string): string {
  const key = `status.${status}`;
  return te(key) ? t(key) : status;
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleString();
  } catch {
    return value;
  }
}

function expressionFor(path: string): string {
  return `{{$json.${path}}}`;
}

function flattenJsonPaths(value: unknown, prefix = ''): Array<{ path: string; preview: string }> {
  if (value === null || typeof value !== 'object') {
    return prefix ? [{ path: prefix, preview: String(value) }] : [];
  }
  const result: Array<{ path: string; preview: string }> = [];
  const entries = Array.isArray(value)
    ? value.map((item, index) => [String(index), item] as const)
    : Object.entries(value as Record<string, unknown>);
  for (const [key, child] of entries) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (child !== null && typeof child === 'object') {
      result.push(...flattenJsonPaths(child, path));
    } else {
      result.push({ path, preview: String(child) });
    }
  }
  return result;
}

async function copyExpression(path: string) {
  const expression = expressionFor(path);
  await navigator.clipboard?.writeText(expression);
  error.value = `Copied ${expression}`;
}

watch(
  () => props.workflowId,
  () => {
    page.value = 0;
    void load();
  },
);

watch(page, () => {
  void load();
  if (activeTab.value === 'history') {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
});

watch(
  () => props.executionTab,
  (v) => {
    if (v === 'queue') activeTab.value = 'queue';
  },
);

watch(activeTab, (tab) => {
  if (tab === 'queue') {
    void loadQueue();
  }
});

onMounted(() => {
  historyStatusFilter.value = parseStatusFromUrl();
  void load();
  if (activeTab.value === 'queue') {
    void loadQueue();
  }
});
</script>

<template>
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4 k-flex-wrap">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <History :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('executionsPage.title') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">
            <template v-if="activeTab === 'history'">
              <span v-if="props.workflowId">{{ t('executionsPage.filteredWorkflow', { id: props.workflowId }) }}</span>
              <span v-else>{{ t('executionsPage.allWorkflows') }}</span>
              — {{ t('executionsPage.pageN', { n: page + 1 }) }}
            </template>
            <template v-else>{{ t('executionsPage.queueSubtitle') }}</template>
          </p>
        </div>
      </div>
      <button
        type="button"
        @click="refreshView()"
        :disabled="loading || (activeTab === 'queue' && queueLoading)"
        class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-60"
      >
        <Loader2 v-if="loading || queueLoading" :size="14" class="k-animate-spin" />
        <RefreshCw v-else :size="14" />
        {{ t('actions.refresh') }}
      </button>
    </header>

    <div class="k-flex k-gap-2 k-border-b k-border-knot-border k-pb-px">
      <button
        type="button"
        class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-text-sm k-font-semibold k-rounded-t-knot-sm k-border k-border-b-0 k-transition-colors"
        :class="
          activeTab === 'history'
            ? 'k-bg-knot-surface k-border-knot-border k-text-knot-primary k-relative k-z-[1]'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'
        "
        @click="setTab('history')"
      >
        <ListOrdered :size="16" />
        {{ t('executionsPage.tabHistory') }}
      </button>
      <button
        type="button"
        class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-text-sm k-font-semibold k-rounded-t-knot-sm k-border k-border-b-0 k-transition-colors"
        :class="
          activeTab === 'queue'
            ? 'k-bg-knot-surface k-border-knot-border k-text-knot-primary k-relative k-z-[1]'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'
        "
        @click="setTab('queue')"
      >
        <Layers :size="16" />
        {{ t('executionsPage.tabQueue') }}
      </button>
    </div>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <template v-if="activeTab === 'history'">
      <p class="k-text-sm k-text-knot-text-muted k-leading-relaxed">
        {{ t('executionsPage.historyIntro') }}
        <span v-if="historyStatusFilter" class="k-mt-1 k-block k-text-knot-primary k-font-medium">
          {{ t('executionsPage.historyStatusActive', { status: historyStatusFilter }) }}
        </span>
      </p>

      <section class="k-grid k-grid-cols-2 md:k-grid-cols-4 k-gap-3">
        <article
          v-for="kpi in totals"
          :key="kpi.labelKey"
          role="button"
          tabindex="0"
          :aria-pressed="historyStatusFilter === kpi.labelKey ? 'true' : 'false'"
          :aria-label="t('executionsPage.kpiFilterAria', { label: kpiLabel(kpi.labelKey) })"
          :class="kpiCardClasses(kpi.labelKey)"
          @click="onKpiCardActivate(kpi.labelKey)"
          @keydown.enter.prevent="onKpiCardActivate(kpi.labelKey)"
          @keydown.space.prevent="onKpiCardActivate(kpi.labelKey)"
        >
          <component :is="kpi.icon" :size="20" :class="kpi.accent" />
          <div>
            <div class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold">
              {{ kpiLabel(kpi.labelKey) }}
            </div>
            <div class="k-text-xl k-font-bold k-text-knot-text">{{ kpi.value }}</div>
          </div>
        </article>
      </section>

      <div class="k-flex k-flex-wrap k-items-center k-gap-3">
        <label class="k-flex k-items-center k-gap-2 k-text-sm k-text-knot-text-muted k-flex-1 k-min-w-[200px]">
          <span class="k-whitespace-nowrap">{{ t('actions.search') }}</span>
          <input
            v-model="historySearch"
            type="search"
            autocomplete="off"
            :placeholder="historySearchPlaceholder"
            class="k-flex-1 k-min-w-0 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-bg k-px-3 k-py-2 k-text-sm k-text-knot-text"
          />
        </label>
      </div>

      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-overflow-x-auto">
        <table class="k-w-full k-text-sm">
          <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
            <tr>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colId') }}</th>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colWorkflow') }}</th>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colTrigger') }}</th>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('common.status') }}</th>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colStarted') }}</th>
              <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colDuration') }}</th>
              <th class="k-text-right k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colActions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredExecutions.length && !loading">
              <td colspan="7" class="k-px-4 k-py-8 k-text-center k-text-knot-text-soft">
                {{ historySearch.trim() ? t('executionsPage.noHistoryMatch') : t('empty.executions.title') }}
              </td>
            </tr>
            <tr
              v-for="exec in filteredExecutions"
              :key="exec.id"
              tabindex="0"
              class="k-border-t k-border-knot-border hover:k-bg-knot-surface-soft k-transition-colors focus-visible:k-ring-2 focus-visible:k-ring-inset focus-visible:k-ring-knot-primary k-cursor-pointer"
              @click="openDetail(exec)"
              @keydown.enter.prevent="openDetail(exec)"
              @keydown.space.prevent="openDetail(exec)"
            >
              <td class="k-px-4 k-py-2.5 k-font-mono k-text-knot-text">#{{ exec.id }}</td>
              <td class="k-px-4 k-py-2.5">
                <a
                  :href="executionDetailUrl(exec.id)"
                  class="k-font-medium k-text-knot-text hover:k-text-knot-primary hover:k-underline"
                  @click.stop
                >
                  {{ workflowPrimaryLabel(exec) }}
                </a>
                <div class="k-text-xs k-text-knot-text-muted k-flex k-items-center k-gap-2 k-flex-wrap">
                  <span>{{ workflowSecondaryLabel(exec) }}</span>
                  <a
                    :href="editorWorkflowUrl(exec.workflowId)"
                    class="k-inline-flex k-items-center k-gap-0.5 k-text-knot-primary hover:k-underline"
                    :title="t('executionsPage.openWorkflowEditor')"
                    @click.stop
                  >
                    <Pencil :size="12" />
                    {{ t('executionsPage.editWorkflow') }}
                  </a>
                </div>
              </td>
              <td class="k-px-4 k-py-2.5 k-text-knot-text-muted">{{ exec.triggerType }}</td>
              <td class="k-px-4 k-py-2.5">
                <span
                  :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold', statusBadge(exec.status)]"
                >
                  {{ executionStatusLabel(exec.status) }}
                </span>
              </td>
              <td class="k-px-4 k-py-2.5 k-text-knot-text-soft">{{ formatDate(exec.startedAt) }}</td>
              <td class="k-px-4 k-py-2.5 k-text-knot-text-soft k-tabular-nums">
                {{ formatExecutionDuration(executionDurationMs(exec)) }}
              </td>
              <td class="k-px-4 k-py-2 k-text-right k-whitespace-nowrap">
                <div class="k-inline-flex k-items-center k-justify-end k-gap-0.5" @click.stop>
                  <button
                    type="button"
                    class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft hover:k-text-knot-primary"
                    :title="t('executionsPage.viewDetails')"
                    :aria-label="t('executionsPage.viewDetails')"
                    @click="openDetail(exec)"
                  >
                    <Eye :size="15" />
                  </button>
                  <a
                    :href="executionDetailUrl(exec.id)"
                    class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft hover:k-text-knot-primary"
                    :title="t('executionsPage.openFullPage')"
                    :aria-label="t('executionsPage.openFullPage')"
                  >
                    <ExternalLink :size="15" />
                  </a>
                  <button
                    v-if="isPending(exec.status)"
                    type="button"
                    @click="runNow(exec)"
                    :disabled="actionRunning?.id === exec.id"
                    class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-primary hover:k-bg-knot-primary-soft disabled:k-opacity-50"
                    :title="t('executionsPage.runNowHint')"
                  >
                    <Loader2 v-if="actionRunning?.id === exec.id && actionRunning?.kind === 'run'" :size="14" class="k-animate-spin" />
                    <Zap v-else :size="14" />
                  </button>
                  <button
                    v-if="isPending(exec.status)"
                    type="button"
                    @click="cancelExec(exec)"
                    :disabled="actionRunning?.id === exec.id"
                    class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-danger-soft hover:k-text-knot-danger disabled:k-opacity-50"
                    :title="t('actions.cancel')"
                  >
                    <Loader2 v-if="actionRunning?.id === exec.id && actionRunning?.kind === 'cancel'" :size="14" class="k-animate-spin" />
                    <Ban v-else :size="14" />
                  </button>
                  <button
                    v-if="isRetryable(exec.status)"
                    type="button"
                    @click="retryExec(exec)"
                    :disabled="actionRunning?.id === exec.id"
                    class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft hover:k-text-knot-primary disabled:k-opacity-50"
                    :title="t('executionsPage.retryHint')"
                  >
                    <Loader2 v-if="actionRunning?.id === exec.id && actionRunning?.kind === 'retry'" :size="14" class="k-animate-spin" />
                    <RotateCcw v-else :size="14" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="k-flex k-items-center k-justify-end k-gap-2">
        <button
          type="button"
          @click="page = Math.max(0, page - 1)"
          :disabled="page === 0 || loading"
          class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-text-knot-text-muted hover:k-text-knot-primary hover:k-border-knot-primary disabled:k-opacity-50"
        >
          <ChevronLeft :size="14" /> {{ t('executionsPage.prev') }}
        </button>
        <button
          type="button"
          @click="page = page + 1"
          :disabled="executions.length < pageSize || loading"
          class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-text-knot-text-muted hover:k-text-knot-primary hover:k-border-knot-primary disabled:k-opacity-50"
        >
          {{ t('executionsPage.next') }} <ChevronRight :size="14" />
        </button>
      </div>
    </template>

    <template v-else>
      <p class="k-text-sm k-text-knot-text-muted k-leading-relaxed">
        {{ t('executionsPage.queueIntro') }}
      </p>

      <div v-if="queueError" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
        {{ queueError }}
      </div>

      <div v-if="queueLoading && !queueData" class="k-flex k-items-center k-gap-2 k-text-knot-text-soft">
        <Loader2 :size="18" class="k-animate-spin" />
        {{ t('actions.loading') }}
      </div>

      <template v-else-if="queueData">
        <section class="k-grid k-grid-cols-2 md:k-grid-cols-4 k-gap-3">
          <article
            v-for="kpi in queueTotals"
            :key="kpi.labelKey"
            role="button"
            tabindex="0"
            :aria-pressed="historyStatusFilter === kpi.labelKey ? 'true' : 'false'"
            :aria-label="t('executionsPage.kpiFilterAria', { label: kpiLabel(kpi.labelKey) })"
            :class="kpiCardClasses(kpi.labelKey)"
            @click="onKpiCardActivate(kpi.labelKey)"
            @keydown.enter.prevent="onKpiCardActivate(kpi.labelKey)"
            @keydown.space.prevent="onKpiCardActivate(kpi.labelKey)"
          >
            <component :is="kpi.icon" :size="20" :class="kpi.accent" />
            <div>
              <div class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold">
                {{ kpiLabel(kpi.labelKey) }}
              </div>
              <div class="k-text-xl k-font-bold k-text-knot-text">{{ kpi.value }}</div>
            </div>
          </article>
        </section>

        <div class="k-flex k-flex-wrap k-items-center k-gap-3">
          <label class="k-flex k-items-center k-gap-2 k-text-sm k-text-knot-text-muted k-flex-1 k-min-w-[200px]">
            <span class="k-whitespace-nowrap">{{ t('actions.search') }}</span>
            <input
              v-model="queueSearch"
              type="search"
              autocomplete="off"
              :placeholder="t('executionsPage.searchQueuePlaceholder')"
              class="k-flex-1 k-min-w-0 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-bg k-px-3 k-py-2 k-text-sm k-text-knot-text"
            />
          </label>
        </div>

        <section class="k-space-y-3">
          <h2 class="k-text-sm k-font-semibold k-text-knot-text">{{ t('executionsPage.queuedByWorkflowTitle') }}</h2>
          <p class="k-text-xs k-text-knot-text-muted">{{ t('executionsPage.queuedByWorkflowHint') }}</p>
          <div class="k-rounded-knot-md k-border k-border-knot-border k-overflow-x-auto">
            <table class="k-w-full k-text-sm">
              <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
                <tr>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colWorkflow') }}</th>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colQueuedRuns') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="filteredQueuedByWorkflow.length === 0">
                  <td colspan="2" class="k-px-4 k-py-6 k-text-knot-text-soft">{{ t('executionsPage.noQueuedWorkflows') }}</td>
                </tr>
                <tr
                  v-for="row in filteredQueuedByWorkflow"
                  :key="row.workflowId"
                  class="k-border-t k-border-knot-border"
                >
                  <td class="k-px-4 k-py-2.5">
                    <a
                      :href="editorWorkflowUrl(row.workflowId)"
                      class="k-font-medium k-text-knot-text hover:k-text-knot-primary hover:k-underline"
                    >
                      {{ queuedWorkflowTitle(row) }}
                    </a>
                    <div class="k-text-xs k-text-knot-text-muted">
                      <span v-if="row.workflowRef">{{ row.workflowRef }} · </span>#{{ row.workflowId }}
                    </div>
                  </td>
                  <td class="k-px-4 k-py-2.5 k-font-mono">{{ row.queuedCount }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="k-space-y-3 k-mt-6">
          <h2 class="k-text-sm k-font-semibold k-text-knot-text">{{ t('executionsPage.topRetriesTitle') }}</h2>
          <p class="k-text-xs k-text-knot-text-muted">{{ t('executionsPage.topRetriesHint') }}</p>
          <div class="k-rounded-knot-md k-border k-border-knot-border k-overflow-x-auto">
            <table class="k-w-full k-text-sm">
              <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
                <tr>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">ID</th>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colWorkflow') }}</th>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('common.status') }}</th>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colRetries') }}</th>
                  <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('executionsPage.colNextRetry') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="filteredTopRetries.length === 0">
                  <td colspan="5" class="k-px-4 k-py-6 k-text-knot-text-soft">{{ t('executionsPage.noRetries') }}</td>
                </tr>
                <tr v-for="row in filteredTopRetries" :key="row.id" class="k-border-t k-border-knot-border">
                  <td class="k-px-4 k-py-2.5 k-font-mono">{{ row.id }}</td>
                  <td class="k-px-4 k-py-2.5">
                    <a
                      :href="editorWorkflowUrl(row.workflowId)"
                      class="k-font-medium k-text-knot-text hover:k-text-knot-primary hover:k-underline"
                    >
                      {{ retryWorkflowTitle(row) }}
                    </a>
                    <div class="k-text-xs k-text-knot-text-muted">
                      <span v-if="row.workflowRef">{{ row.workflowRef }} · </span>#{{ row.workflowId }}
                    </div>
                  </td>
                  <td class="k-px-4 k-py-2.5">{{ executionStatusLabel(row.status) }}</td>
                  <td class="k-px-4 k-py-2.5">{{ row.retryCount }}</td>
                  <td class="k-px-4 k-py-2.5 k-text-knot-text-soft">{{ row.nextRetryAt ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-mt-6">
          <h2 class="k-text-sm k-font-semibold k-text-knot-text k-mb-2">{{ t('executionsPage.purgeTitle') }}</h2>
          <p class="k-text-xs k-text-knot-text-muted k-mb-3">{{ t('executionsPage.purgeHint') }}</p>
          <div class="k-flex k-flex-wrap k-items-center k-gap-3">
            <label class="k-flex k-items-center k-gap-2 k-text-sm">
              {{ t('executionsPage.purgeDays') }}
              <input
                v-model.number="purgeDays"
                type="number"
                min="1"
                max="3650"
                class="k-w-24 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-bg k-px-2 k-py-1"
              />
            </label>
            <button
              type="button"
              class="k-inline-flex k-items-center k-gap-2 k-rounded-knot-sm k-bg-red-600 k-text-white k-px-3 k-py-2 k-text-sm hover:k-bg-red-700 disabled:k-opacity-50"
              :disabled="purging || queueLoading"
              @click="purgeFailures()"
            >
              <Trash2 class="k-size-4" />
              {{ t('executionsPage.purgeAction') }}
            </button>
          </div>
        </section>
      </template>
    </template>
    <transition name="k-toast">
      <div
        v-if="selected"
        class="k-fixed k-inset-y-0 k-right-0 k-w-[480px] k-max-w-full k-bg-knot-surface k-border-l k-border-knot-border k-shadow-knot-md k-z-30 k-flex k-flex-col"
      >
        <header class="k-flex k-items-start k-justify-between k-gap-3 k-px-5 k-py-4 k-border-b k-border-knot-border">
          <div>
            <div class="k-text-xs k-text-knot-text-soft k-font-semibold k-uppercase k-tracking-wider">
              {{ t('executionsPage.drawerKicker') }}
            </div>
            <div class="k-text-lg k-font-bold k-text-knot-text">#{{ selected.id }}</div>
            <div class="k-text-xs k-text-knot-text-muted">
              {{ workflowPrimaryLabel(selected) }} — {{ formatDate(selected.startedAt) }}
            </div>
          </div>
          <button
            @click="selected = null; selectedLogs = []"
            class="k-p-1 k-rounded-knot-sm k-text-knot-text-soft hover:k-text-knot-text hover:k-bg-knot-surface-soft"
            :aria-label="t('actions.close')"
          >
            <X :size="18" />
          </button>
        </header>

        <div class="k-px-5 k-py-3 k-border-b k-border-knot-border k-flex k-flex-wrap k-gap-3 k-text-xs k-items-center">
          <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-font-semibold', statusBadge(selected.status)]">
            {{ executionStatusLabel(selected.status) }}
          </span>
          <span class="k-text-knot-text-muted"
            >{{ t('executionsPage.inlineTriggerPrefix') }}
            <span class="k-font-mono">{{ selected.triggerType }}</span></span
          >
          <span class="k-text-knot-text-muted"
            >{{ t('executionsPage.inlineDurationPrefix') }}
            {{ formatExecutionDuration(executionDurationMs(selected)) }}</span
          >
          <div class="k-ml-auto k-flex k-gap-1.5">
            <button
              v-if="isPending(selected.status)"
              @click="runNow(selected)"
              :disabled="actionRunning?.id === selected.id"
              class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-px-2 k-py-1 k-rounded-knot-sm k-bg-knot-primary k-text-white hover:k-bg-knot-primary-strong disabled:k-opacity-50"
            >
              <Loader2 v-if="actionRunning?.kind === 'run'" :size="11" class="k-animate-spin" />
              <Zap v-else :size="11" />
              {{ t('executionsPage.runNow') }}
            </button>
            <button
              v-if="isPending(selected.status)"
              @click="cancelExec(selected, true)"
              :disabled="actionRunning?.id === selected.id"
              class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-px-2 k-py-1 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text-muted hover:k-text-knot-danger hover:k-border-knot-danger disabled:k-opacity-50"
            >
              <Loader2 v-if="actionRunning?.kind === 'cancel'" :size="11" class="k-animate-spin" />
              <Ban v-else :size="11" />
              {{ t('actions.cancel') }}
            </button>
            <button
              v-if="isRetryable(selected.status)"
              @click="retryExec(selected)"
              :disabled="actionRunning?.id === selected.id"
              class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-px-2 k-py-1 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text-muted hover:k-text-knot-primary hover:k-border-knot-primary disabled:k-opacity-50"
            >
              <Loader2 v-if="actionRunning?.kind === 'retry'" :size="11" class="k-animate-spin" />
              <RotateCcw v-else :size="11" />
              {{ t('executionsPage.retry') }}
            </button>
          </div>
        </div>

        <div class="k-flex-1 k-overflow-y-auto k-px-5 k-py-3 k-space-y-3">
          <div v-if="detailLoading" class="k-flex k-items-center k-gap-2 k-text-knot-text-soft k-text-sm">
            <Loader2 :size="14" class="k-animate-spin" /> {{ t('executionsPage.loadingLogs') }}
          </div>
          <div v-if="selected.errorMessage" class="k-bg-knot-danger-soft k-text-knot-danger k-px-3 k-py-2 k-rounded-knot-sm k-text-xs k-font-mono">
            {{ selected.errorMessage }}
          </div>
          <article
            v-for="log in selectedLogs"
            :key="log.id"
            class="k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-space-y-2"
          >
            <header class="k-flex k-items-center k-justify-between k-text-xs">
              <div class="k-flex k-items-center k-gap-2">
                <span class="k-font-mono k-text-knot-text">{{ log.nodeId }}</span>
                <span class="k-text-knot-text-soft">·</span>
                <span class="k-text-knot-text-muted">{{ log.nodeType }}</span>
              </div>
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-font-semibold', logBadge(log.status)]">
                {{ logStatusLabel(log.status) }}
              </span>
            </header>
            <button
              @click="replayFromLog(log)"
              :disabled="replayingNode === log.nodeId"
              class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary hover:k-text-knot-primary k-px-2 k-py-1 disabled:k-opacity-50"
            >
              <Loader2 v-if="replayingNode === log.nodeId" :size="11" class="k-animate-spin" />
              <Play v-else :size="11" />
              {{ t('executionsPage.replayFromNode') }}
            </button>
            <div v-if="log.errorMessage" class="k-text-xs k-text-knot-danger k-font-mono">{{ log.errorMessage }}</div>
            <details>
              <summary class="k-text-[11px] k-text-knot-text-soft k-cursor-pointer">{{ t('executionsPage.ioSummary') }}</summary>
              <pre class="k-mt-2 k-text-[11px] k-font-mono k-bg-knot-bg k-p-2 k-rounded-knot-sm k-overflow-auto k-max-h-[200px]">{{ JSON.stringify({ input: log.input, output: log.output }, null, 2) }}</pre>
            </details>
            <details>
              <summary class="k-text-[11px] k-text-knot-text-soft k-cursor-pointer">{{ t('executionsPage.clickablePathsSummary') }}</summary>
              <div class="k-mt-2 k-space-y-1 k-max-h-[160px] k-overflow-auto">
                <button
                  v-for="item in flattenJsonPaths(log.output)"
                  :key="log.id + item.path"
                  @click="copyExpression(item.path)"
                  class="k-w-full k-text-left k-rounded-knot-sm k-bg-knot-bg k-border k-border-knot-border k-px-2 k-py-1 hover:k-border-knot-primary"
                >
                  <div class="k-text-[11px] k-font-mono k-text-knot-primary k-truncate">{{ expressionFor(item.path) }}</div>
                  <div class="k-text-[10px] k-text-knot-text-muted k-truncate">{{ item.preview }}</div>
                </button>
              </div>
            </details>
          </article>
        </div>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.k-toast-enter-active, .k-toast-leave-active { transition: transform 220ms ease, opacity 220ms ease; }
.k-toast-enter-from, .k-toast-leave-to { transform: translateX(20px); opacity: 0; }
</style>
