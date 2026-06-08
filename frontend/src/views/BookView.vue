<!--
  Book view — the operator dashboard for the active workflow surface.
  Aggregates workflow inventory, native Dolibarr automations, and conflict
  hints so an admin can grasp "what is going to happen" at a glance.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
  BookOpen,
  AlertTriangle,
  CheckCircle2,
  Loader2,
  RefreshCw,
  Workflow,
  Zap,
  Shield,
  Download,
} from 'lucide-vue-next';
import {
  knotApi,
  type ConflictReport,
  type ExecutionSummary,
  type WorkflowSummary,
} from '../lib/api';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const workflows = ref<WorkflowSummary[]>([]);
const counts = ref<Record<string, number>>({});
const report = ref<ConflictReport | null>(null);
const executions = ref<ExecutionSummary[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const lastRefresh = ref<string | null>(null);
const tab = ref<'list' | 'matrix' | 'timeline' | 'graph'>('list');

const baseUrl = computed(() => {
  const win = typeof window !== 'undefined'
    ? (window as unknown as { KNOT_BASE_URL?: string })
    : undefined;
  return win?.KNOT_BASE_URL ?? '';
});

const activeWorkflows = computed(() =>
  workflows.value.filter((w) => w.status === 'active'),
);

const dolibarrCoverage = computed(() => {
  const stats: Record<string, number> = {};
  for (const w of activeWorkflows.value) {
    const trigger = w.triggerType ?? 'manual';
    stats[trigger] = (stats[trigger] ?? 0) + 1;
  }
  return stats;
});

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [wf, rp, ex] = await Promise.all([
      knotApi.listWorkflows({ limit: 500 }),
      knotApi.conflictReport().catch(() => ({ report: null })),
      knotApi.listExecutions({ limit: 100 }).catch(() => ({ executions: [] as ExecutionSummary[] })),
    ]);
    workflows.value = wf.workflows;
    counts.value = wf.counts;
    report.value = rp.report ?? null;
    executions.value = ex.executions ?? [];
    lastRefresh.value = new Date().toLocaleString();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('bookPage.loadFailed');
  } finally {
    loading.value = false;
  }
}

// Build a coverage matrix: rows = triggers, cols = Dolibarr object types
// inferred from workflow definitions (best-effort: scan node types in
// listWorkflows snapshot — falls back to "n/a" when definition isn't loaded).
const coverageMatrix = computed(() => {
  const noDolibarr = t('bookPage.noDolibarrAction');
  const matrix: Record<string, Record<string, number>> = {};
  for (const w of activeWorkflows.value) {
    const trigger = w.triggerType ?? 'manual';
    const objects = inferObjects(w);
    matrix[trigger] = matrix[trigger] ?? {};
    if (objects.length === 0) {
      matrix[trigger][noDolibarr] = (matrix[trigger][noDolibarr] ?? 0) + 1;
    } else {
      for (const obj of objects) {
        matrix[trigger][obj] = (matrix[trigger][obj] ?? 0) + 1;
      }
    }
  }
  return matrix;
});

const allObjectColumns = computed(() => {
  const cols = new Set<string>();
  for (const t of Object.values(coverageMatrix.value)) {
    for (const k of Object.keys(t)) cols.add(k);
  }
  return Array.from(cols).sort();
});

function inferObjects(w: WorkflowSummary): string[] {
  const def = (w as unknown as { definition?: { nodes?: Array<Record<string, unknown>> } }).definition;
  if (!def?.nodes) return [];
  const set = new Set<string>();
  for (const n of def.nodes) {
    const type = String(n.type ?? '');
    if (type === 'dolibarr.object' || type === 'dolibarr.read_object') {
      const cfg = (n.config as Record<string, unknown>) ?? {};
      const obj = String(cfg.objectType ?? cfg.element ?? '');
      if (obj) set.add(obj);
    } else if (type === 'dolibarr.specialized') {
      const cfg = (n.config as Record<string, unknown>) ?? {};
      const op = String(cfg.operation ?? '');
      if (op) set.add(op.split('.')[0] ?? op);
    }
  }
  return Array.from(set);
}

const timelineRows = computed(() => {
  return executions.value
    .filter((e) => e.startedAt || e.endedAt)
    .slice()
    .sort((a, b) => {
      const ta = a.startedAt ?? a.endedAt ?? '';
      const tb = b.startedAt ?? b.endedAt ?? '';
      return tb.localeCompare(ta);
    })
    .slice(0, 60)
    .map((e) => ({
      ...e,
      workflowLabel: workflows.value.find((w) => w.id === e.workflowId)?.label ?? `#${e.workflowId}`,
    }));
});

function exportMarkdown() {
  const md: string[] = [];
  md.push(t('bookPage.mdTitle'));
  md.push('');
  md.push(t('bookPage.mdGenerated', { date: lastRefresh.value ?? new Date().toLocaleString() }));
  md.push('');
  md.push(t('bookPage.mdActiveCount', { n: activeWorkflows.value.length }));
  md.push(t('bookPage.mdTotalCount', { n: workflows.value.length }));
  md.push(
    t('bookPage.mdNativeCount', { n: report.value?.nativeWorkflows.length ?? 0 }),
  );
  md.push(t('bookPage.mdTriggerConflictsCount', { n: report.value?.triggerConflicts.length ?? 0 }));
  md.push(t('bookPage.mdNativeConflictsCount', { n: report.value?.nativeConflicts.length ?? 0 }));
  md.push('');
  md.push(t('bookPage.mdSectionActive'));
  md.push('');
  for (const w of activeWorkflows.value) {
    md.push(
      `- **${w.label}** \`${w.ref}\` — trigger: ${w.triggerType ?? 'manual'} — runs (success/error/wait): ${w.successCount ?? 0}/${w.errorCount ?? 0}/${w.waitingCount ?? 0}`,
    );
  }
  if (report.value?.triggerConflicts.length) {
    md.push('');
    md.push(t('bookPage.mdSectionTriggerConflicts'));
    md.push('');
    for (const c of report.value.triggerConflicts) {
      md.push(
        `- _${c.severity}_ — Trigger \`${c.trigger}\` shared by ${c.workflows
          .map((x) => `#${x.workflowId} ${x.label}`)
          .join(', ')} — ${c.suggestion}`,
      );
    }
  }
  if (report.value?.nativeConflicts.length) {
    md.push('');
    md.push(t('bookPage.mdSectionNativeOverlaps'));
    md.push('');
    for (const c of report.value.nativeConflicts) {
      md.push(
        `- _${c.severity ?? 'info'}_ — Workflow #${c.workflowId} (${c.workflowLabel}) overlaps with ${c.description ?? c.nativeId ?? 'Dolibarr automation'}`,
      );
    }
  }
  const blob = new Blob([md.join('\n')], { type: 'text/markdown;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `knot-book-${new Date().toISOString().slice(0, 10)}.md`;
  a.click();
  URL.revokeObjectURL(a.href);
}

onMounted(load);
</script>

<template>
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-6">
    <header class="k-flex k-items-center k-justify-between k-gap-3 k-flex-wrap">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-12 k-w-12 k-rounded-knot-md k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <BookOpen :size="24" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('bookPage.title') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">
            {{ t('bookPage.subtitle') }}
          </p>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          @click="exportMarkdown"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary"
        >
          <Download :size="14" /> {{ t('bookPage.exportMd') }}
        </button>
        <button
          @click="load"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white hover:k-bg-knot-primary-strong"
        >
          <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
          <RefreshCw v-else :size="14" /> {{ t('bookPage.refresh') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <section class="k-grid k-grid-cols-2 lg:k-grid-cols-4 k-gap-3">
      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-flex k-items-center k-gap-2 k-text-knot-text-muted k-text-xs k-uppercase k-tracking-wide">
          <Workflow :size="14" /> {{ t('bookPage.kpiActive') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-text k-mt-1">{{ activeWorkflows.length }}</div>
        <div class="k-text-xs k-text-knot-text-muted">{{ t('bookPage.kpiTotal', { n: workflows.length }) }}</div>
      </div>
      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-flex k-items-center k-gap-2 k-text-knot-text-muted k-text-xs k-uppercase k-tracking-wide">
          <Zap :size="14" /> {{ t('bookPage.kpiTriggerConflicts') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-warning k-mt-1">
          {{ report?.triggerConflicts.length ?? 0 }}
        </div>
        <div class="k-text-xs k-text-knot-text-muted">{{ t('bookPage.kpiTriggerConflictsHint') }}</div>
      </div>
      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-flex k-items-center k-gap-2 k-text-knot-text-muted k-text-xs k-uppercase k-tracking-wide">
          <Shield :size="14" /> {{ t('bookPage.kpiNativeOverlaps') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-warning k-mt-1">
          {{ report?.nativeConflicts.length ?? 0 }}
        </div>
        <div class="k-text-xs k-text-knot-text-muted">{{ t('bookPage.kpiNativeOverlapsHint') }}</div>
      </div>
      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-flex k-items-center k-gap-2 k-text-knot-text-muted k-text-xs k-uppercase k-tracking-wide">
          <CheckCircle2 :size="14" /> {{ t('bookPage.kpiNativeTriggers') }}
        </div>
        <div class="k-text-3xl k-font-bold k-text-knot-success k-mt-1">
          {{ report?.nativeWorkflows.length ?? 0 }}
        </div>
        <div class="k-text-xs k-text-knot-text-muted">{{ t('bookPage.kpiNativeTriggersHint') }}</div>
      </div>
    </section>

    <section v-if="report?.triggerConflicts.length || report?.nativeConflicts.length">
      <div class="k-flex k-items-center k-gap-2 k-text-knot-text k-font-semibold k-mb-2">
        <AlertTriangle :size="16" class="k-text-knot-warning" /> {{ t('bookPage.anomaliesTitle') }}
      </div>
      <div class="k-space-y-2">
        <div
          v-for="(c, idx) in report?.triggerConflicts ?? []"
          :key="`tc-${idx}`"
          class="k-bg-knot-warning-soft k-border k-border-knot-warning/40 k-rounded-knot-sm k-px-3 k-py-2 k-text-sm"
        >
          <div class="k-font-semibold k-text-knot-text">
            {{ t('bookPage.sharedTrigger') }} <code class="k-font-mono">{{ c.trigger }}</code>
          </div>
          <div class="k-text-knot-text-muted">
            {{ c.workflows.map((w) => `#${w.workflowId} ${w.label}`).join(' · ') }}
          </div>
          <div class="k-text-knot-text-soft k-text-xs k-mt-1">{{ c.suggestion }}</div>
        </div>
        <div
          v-for="(c, idx) in report?.nativeConflicts ?? []"
          :key="`nc-${idx}`"
          class="k-bg-knot-warning-soft k-border k-border-knot-warning/40 k-rounded-knot-sm k-px-3 k-py-2 k-text-sm"
        >
          <div class="k-font-semibold k-text-knot-text">
            {{ t('bookPage.nativeConflictHeading', { id: c.workflowId, label: c.workflowLabel }) }}
            <code class="k-font-mono">{{ c.nativeId ?? '?' }}</code>
          </div>
          <div class="k-text-knot-text-muted">{{ c.description ?? '' }}</div>
        </div>
      </div>
    </section>

    <nav class="k-flex k-items-center k-gap-1 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-1 k-w-fit">
      <button
        v-for="bk in (['list','matrix','timeline'] as const)"
        :key="bk"
        @click="tab = bk"
        class="k-px-3 k-py-1.5 k-text-sm k-rounded-knot-sm"
        :class="tab === bk ? 'k-bg-knot-primary k-text-white k-font-semibold' : 'k-text-knot-text-muted hover:k-text-knot-text'"
      >{{ bk === 'list' ? t('bookPage.tabList') : bk === 'matrix' ? t('bookPage.tabMatrix') : t('bookPage.tabTimeline') }}</button>
    </nav>

    <section v-if="tab === 'list'">
      <div class="k-flex k-items-center k-justify-between k-mb-2">
        <div class="k-text-knot-text k-font-semibold">{{ t('bookPage.activeWorkflowsTitle') }}</div>
        <div class="k-text-xs k-text-knot-text-muted">
          {{ t('bookPage.triggersCovered') }} {{ Object.keys(dolibarrCoverage).join(', ') || t('editor.commonEmDash') }}
        </div>
      </div>
      <div class="k-grid k-grid-cols-1 md:k-grid-cols-2 xl:k-grid-cols-3 k-gap-3">
        <article
          v-for="w in activeWorkflows"
          :key="w.id"
          class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-2"
        >
          <div class="k-flex k-items-start k-justify-between k-gap-2">
            <div>
              <div class="k-text-xs k-font-mono k-text-knot-text-soft">{{ w.ref }}</div>
              <h3 class="k-font-semibold k-text-knot-text">{{ w.label }}</h3>
            </div>
            <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-success-soft k-text-knot-success k-text-xs k-font-semibold">
              {{ w.status }}
            </span>
          </div>
          <p class="k-text-xs k-text-knot-text-muted k-line-clamp-2">{{ w.description ?? '' }}</p>
          <div class="k-flex k-items-center k-gap-2 k-text-xs k-flex-wrap">
            <span class="k-px-1.5 k-py-0.5 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-font-semibold">
              {{ w.triggerType ?? 'manual' }}
            </span>
            <span
              class="k-px-1.5 k-py-0.5 k-rounded-knot-sm k-bg-knot-surface-soft k-text-knot-text-muted k-font-mono"
              :title="t('bookPage.runsTotalTitle', { n: w.runsTotal ?? 0 })"
            >
              {{ t('bookPage.listRunsLine', { n: w.runsTotal ?? 0 }) }}
            </span>
            <span class="k-text-knot-success k-font-semibold" :title="t('bookPage.successTitle')">✓ {{ w.successCount ?? 0 }}</span>
            <span class="k-text-knot-error k-font-semibold" :title="t('bookPage.errorTitle')">✗ {{ w.errorCount ?? 0 }}</span>
            <span class="k-text-knot-warning k-font-semibold" :title="t('bookPage.waitingTitle')">⏳ {{ w.waitingCount ?? 0 }}</span>
          </div>
          <a
            v-if="baseUrl"
            :href="`${baseUrl}?mode=editor&workflow_id=${w.id}`"
            class="k-text-xs k-text-knot-primary hover:k-underline"
          >{{ t('bookPage.openEditor') }}</a>
        </article>
      </div>
      <div
        v-if="!activeWorkflows.length && !loading"
        class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-8 k-text-center k-text-knot-text-muted"
      >
        {{ t('bookPage.emptyActive') }}
      </div>
    </section>

    <section v-if="tab === 'matrix'">
      <div class="k-text-knot-text k-font-semibold k-mb-2">{{ t('bookPage.matrixTitle') }}</div>
      <div class="k-overflow-x-auto k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md">
        <table class="k-w-full k-text-sm">
          <thead>
            <tr class="k-bg-knot-surface-soft">
              <th class="k-text-left k-px-3 k-py-2 k-text-knot-text-muted k-font-semibold">{{ t('bookPage.matrixColTrigger') }}</th>
              <th
                v-for="col in allObjectColumns"
                :key="col"
                class="k-text-left k-px-3 k-py-2 k-text-knot-text-muted k-font-semibold k-whitespace-nowrap"
              >{{ col }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(cells, trigger) in coverageMatrix" :key="trigger" class="k-border-t k-border-knot-border">
              <td class="k-px-3 k-py-2 k-font-mono k-text-knot-text">{{ trigger }}</td>
              <td v-for="col in allObjectColumns" :key="`${trigger}-${col}`" class="k-px-3 k-py-2">
                <span
                  v-if="cells[col]"
                  class="k-px-2 k-py-0.5 k-rounded-knot-pill k-text-xs k-font-semibold"
                  :class="cells[col] > 1 ? 'k-bg-knot-warning-soft k-text-knot-warning' : 'k-bg-knot-primary-soft k-text-knot-primary'"
                >{{ cells[col] }}</span>
                <span v-else class="k-text-knot-text-soft">·</span>
              </td>
            </tr>
            <tr v-if="!Object.keys(coverageMatrix).length">
              <td colspan="999" class="k-px-3 k-py-6 k-text-center k-text-knot-text-muted">
                {{ t('bookPage.matrixEmpty') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="k-text-xs k-text-knot-text-muted k-mt-2">
        {{ t('bookPage.matrixHint') }}
      </p>
    </section>

    <section v-if="tab === 'timeline'">
      <div class="k-text-knot-text k-font-semibold k-mb-2">{{ t('bookPage.timelineTitle') }}</div>
      <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-divide-y k-divide-knot-border">
        <article
          v-for="row in timelineRows"
          :key="row.id"
          class="k-flex k-items-center k-gap-3 k-px-3 k-py-2 k-text-sm"
        >
          <span
            class="k-h-2 k-w-2 k-rounded-full"
            :class="{
              'k-bg-knot-success': row.status === 'completed',
              'k-bg-knot-error': row.status === 'failed',
              'k-bg-knot-warning': row.status === 'waiting' || row.status === 'queued',
              'k-bg-knot-primary': row.status === 'running',
            }"
          ></span>
          <span class="k-text-xs k-text-knot-text-muted k-w-44 k-truncate">
            {{ (row.endedAt || row.startedAt || '').replace('T', ' ').slice(0, 19) }}
          </span>
          <span class="k-flex-1 k-truncate k-text-knot-text">{{ row.workflowLabel }}</span>
          <span class="k-text-xs k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-surface-soft k-text-knot-text-muted">
            {{ row.triggerType }}
          </span>
          <span class="k-text-xs k-text-knot-text-muted k-w-16 k-text-right">
            {{ row.durationMs ? `${row.durationMs}ms` : t('editor.commonEmDash') }}
          </span>
          <span
            class="k-text-xs k-font-semibold k-w-20 k-text-right"
            :class="{
              'k-text-knot-success': row.status === 'completed',
              'k-text-knot-error': row.status === 'failed',
              'k-text-knot-warning': row.status === 'waiting',
              'k-text-knot-primary': row.status === 'running',
            }"
          >{{ row.status }}</span>
        </article>
        <div v-if="!timelineRows.length" class="k-px-3 k-py-8 k-text-center k-text-knot-text-muted">
          {{ t('bookPage.timelineEmpty') }}
        </div>
      </div>
    </section>

    <p v-if="lastRefresh" class="k-text-xs k-text-knot-text-soft k-text-right">
      {{ t('bookPage.lastRefresh') }} {{ lastRefresh }}
    </p>
  </div>
</template>
