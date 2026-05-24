<!--
  Workflows view — list, search, create, status switch.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
  Workflow,
  PlusCircle,
  Loader2,
  RefreshCw,
  Search,
  ExternalLink,
  BookOpen,
  Library,
  Upload,
  Download,
  Copy,
  Folder,
} from 'lucide-vue-next';
import { knotApi, type WorkflowSummary, formatWorkflowImportWarningLine } from '../lib/api';
import { normalizeWorkflowImport, parseWorkflowImportText, WorkflowImportFormatError } from '../lib/normalizeWorkflowImport';
import { minimapRiskHex, type RiskLevel } from '../composables/useNodeRisk';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';
import { useToast } from '../composables/useToast';
import { useConfirm } from '../composables/useConfirm';
import { useI18n } from 'vue-i18n';
import MigrationBanner from '../components/licensing/MigrationBanner.vue';

const { t, te } = useI18n();

const toast = useToast();
const confirmDialog = useConfirm();

const items = ref<WorkflowSummary[]>([]);
const counts = ref<Record<string, number>>({});
const loading = ref(false);
const error = ref<string | null>(null);

const search = ref('');
const statusFilter = ref<string>('');
const folders = ref<Array<{ id: number; label: string; color: string | null; parentId: number | null }>>([]);
const selectedFolderId = ref<number | 'all' | 'unfiled'>('all');
const newFolderLabel = ref('');
const folderBusy = ref(false);

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

async function loadFolders() {
  try {
    const result = await knotApi.listFolders();
    folders.value = result.folders;
  } catch {
    folders.value = [];
  }
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.listWorkflows({
      status: statusFilter.value || undefined,
      limit: 200,
    });
    items.value = result.workflows;
    counts.value = result.counts;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('executionsPage.loadExecutionsFailed');
  } finally {
    loading.value = false;
  }
}

async function createFolder() {
  const label = newFolderLabel.value.trim();
  if (!label || folderBusy.value) {
    return;
  }
  folderBusy.value = true;
  try {
    await knotApi.createFolder({ label });
    newFolderLabel.value = '';
    await loadFolders();
  } catch (err) {
    toast.error(err instanceof Error ? err.message : t('workflowsPage.createFailed'));
  } finally {
    folderBusy.value = false;
  }
}

async function assignWorkflowFolder(workflowId: number, folderId: number | null) {
  folderBusy.value = true;
  try {
    await knotApi.assignFolder(workflowId, folderId);
    const wf = items.value.find((item) => item.id === workflowId);
    if (wf) {
      wf.folderId = folderId;
    }
  } catch (err) {
    toast.error(err instanceof Error ? err.message : t('workflowsPage.createFailed'));
  } finally {
    folderBusy.value = false;
  }
}

const filtered = computed(() => {
  let rows = items.value;
  if (selectedFolderId.value === 'unfiled') {
    rows = rows.filter((w) => !w.folderId);
  } else if (selectedFolderId.value !== 'all') {
    rows = rows.filter((w) => w.folderId === selectedFolderId.value);
  }
  if (!search.value.trim()) {
    return rows;
  }
  const q = search.value.toLowerCase();
  return rows.filter(
    (w) =>
      w.label.toLowerCase().includes(q) ||
      w.ref.toLowerCase().includes(q) ||
      (w.description ?? '').toLowerCase().includes(q),
  );
});

const importInput = ref<HTMLInputElement | null>(null);
const selectedIds = ref<number[]>([]);

function importJsonClick() {
  importInput.value?.click();
}

async function onImportFile(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  try {
    const text = await file.text();
    const parsed = parseWorkflowImportText(text);
    const record = parsed as Record<string, unknown>;
    if (record && typeof record === 'object' && Array.isArray(record.workflows)) {
      const pre = await knotApi.importWorkflowsPrecheck(record.workflows as Parameters<typeof knotApi.importWorkflowsPrecheck>[0]);
      if (pre.warnings.length > 0) {
        const lines = pre.warnings.map((w) => `- [${w.severity}] ${formatWorkflowImportWarningLine(w)}`).join('\n');
        const ok = await confirmDialog.confirm({
          title: t('workflowImport.compatTitle'),
          message: `${t('workflowImport.compatLead', { count: pre.warnings.length })}\n\n${lines}\n\n${t('workflowImport.compatConfirm')}`,
        });
        if (!ok) return;
      }
      const r = await knotApi.importWorkflowsBulk(record.workflows as Parameters<typeof knotApi.importWorkflowsBulk>[0]);
      toast.success(t('workflowsPage.importedBulk', { imported: r.imported, skipped: r.skipped }));
      await load();
      return;
    }
    const payload = normalizeWorkflowImport(parsed, {
      label: t('workflowsPage.importedWorkflowDefault'),
      status: 'draft',
    });
    const result = await knotApi.saveWorkflow(payload);
    if (!result?.workflow?.id) {
      throw new Error(t('assistantPage.msgImportNoWorkflow'));
    }
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${result.workflow.id}`;
  } catch (e) {
    if (e instanceof WorkflowImportFormatError) {
      toast.error(t('workflowsPage.unknownJson'));
    } else if (e instanceof SyntaxError) {
      toast.error(t('workflowsPage.importFailed'));
    } else {
      toast.error(t('workflowsPage.importFailedPrefix') + (e as Error).message);
    }
  } finally {
    input.value = '';
  }
}

function exportSelectedBulk() {
  if (selectedIds.value.length === 0) return;
  const ids = selectedIds.value.join(',');
  window.location.href = `${baseUrl.value.replace(/\?.*$/, '')}/custom/knot/api/workflows.php?action=export_bulk&ids=${ids}`;
}

async function cloneWorkflow(wf: WorkflowSummary) {
  const suggested = `${wf.label} ${t('workflowsPage.cloneDefaultSuffix')}`.trim();
  const label = window.prompt(t('workflowsPage.clonePrompt'), suggested);
  if (label === null) return;
  loading.value = true;
  error.value = null;
  try {
    const url = `${baseUrl.value.replace(/\?.*$/, '')}/custom/knot/api/workflows.php?action=clone&id=${wf.id}&label=${encodeURIComponent(label.trim() || suggested)}`;
    const csrfToken = (window as unknown as { KNOT_CSRF_TOKEN?: string }).KNOT_CSRF_TOKEN ?? '';
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Csrf-Token': csrfToken, 'Accept': 'application/json' },
    });
    const json = await response.json();
    if (!response.ok || json?.error) {
      throw new Error(json?.message ?? t('workflowsPage.cloneFailed'));
    }
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('workflowsPage.cloneFailed');
  } finally {
    loading.value = false;
  }
}

async function createBlank() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.saveWorkflow({
      label: t('workflowsPage.newWorkflow'),
      status: 'draft',
      definition: {
        schemaVersion: '1.0',
        nodes: [],
        edges: [],
        metadata: { createdFrom: 'workflows-list' },
      },
    });
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${result.workflow.id}`;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('workflowsPage.createFailed');
    loading.value = false;
  }
}

function statusBadge(status: string) {
  switch (status) {
    case 'active':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'disabled':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'error':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    case 'archived':
      return 'k-bg-knot-surface-soft k-text-knot-text-soft';
    case 'draft':
    default:
      return 'k-bg-knot-primary-soft k-text-knot-primary';
  }
}

function editUrl(id: number) {
  return `${baseUrl.value}?mode=editor&workflow_id=${id}`;
}

function executionsUrl(id: number) {
  return `${baseUrl.value}?mode=executions&workflow_id=${id}`;
}

const marketplaceChromeEnabled = computed(() => isMarketplaceUiEnabled());

const headerSubtitle = computed(() =>
  t('workflowsPage.subtitleCounts', {
    count: items.value.length,
    active: counts.value.active ?? 0,
    draft: counts.value.draft ?? 0,
  }),
);

function workflowStatusLabel(status: string): string {
  const key = `status.${status}`;
  return te(key) ? t(key) : status;
}

function isGuide(wf: WorkflowSummary): boolean {
  return wf.ref.startsWith('KNOT-DEMO-') || wf.label.startsWith('[Guide]');
}

function workflowRiskLevel(wf: WorkflowSummary): RiskLevel {
  const level = wf.riskWorstLevel ?? 'safe';
  if (level === 'critical' || level === 'caution' || level === 'safe') {
    return level;
  }
  return 'safe';
}

function workflowRiskTitle(wf: WorkflowSummary): string {
  const level = workflowRiskLevel(wf);
  if (level === 'critical') return t('workflowsPage.riskCritical');
  if (level === 'caution') return t('workflowsPage.riskCaution');
  return t('workflowsPage.riskSafe');
}

onMounted(async () => {
  await Promise.all([load(), loadFolders()]);
});
</script>

<template>
  <div class="k-p-6 k-max-w-[1280px] k-mx-auto k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <Workflow :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('nav.workflows') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">
            {{ headerSubtitle }}
          </p>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          @click="load"
          :disabled="loading"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-60"
        >
          <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
          <RefreshCw v-else :size="14" />
          {{ t('actions.refresh') }}
        </button>
        <a
          v-if="marketplaceChromeEnabled"
          :href="`${baseUrl}?mode=marketplace&tab=templates`"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary k-no-underline"
        >
          <Library :size="14" />
          {{ t('workflowsPage.fromTemplate') }}
        </a>
        <button
          @click="importJsonClick"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary"
        >
          <Upload :size="14" />
          {{ t('actions.import') }}
        </button>
        <input ref="importInput" type="file" accept="application/json,.json" class="k-hidden" @change="onImportFile" />
        <button
          @click="exportSelectedBulk"
          :disabled="!selectedIds.length"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-50"
        >
          <Download :size="14" />
          {{ t('workflowsPage.exportZip') }}
        </button>
        <button
          @click="createBlank"
          :disabled="loading"
          class="k-inline-flex k-items-center k-gap-2 k-px-3.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] hover:k-shadow-[0_12px_24px_rgba(99,102,241,0.45)] disabled:k-opacity-60"
        >
          <PlusCircle :size="14" />
          {{ t('workflowsPage.newWorkflow') }}
        </button>
      </div>
    </header>

    <MigrationBanner global />

    <div class="k-flex k-items-center k-gap-3">
      <div class="k-relative k-flex-1 k-max-w-md">
        <Search :size="14" class="k-absolute k-left-3 k-top-1/2 k--translate-y-1/2 k-text-knot-text-soft" />
        <input
          v-model="search"
          type="text"
          :placeholder="t('workflowsPage.searchPlaceholder')"
          class="k-w-full k-pl-9 k-pr-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary focus:k-ring-2 focus:k-ring-knot-primary/20 k-text-knot-text"
        />
      </div>
      <select
        v-model="statusFilter"
        @change="load"
        class="k-text-sm k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-px-3 k-py-2 k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
      >
        <option value="">{{ t('workflowsPage.allStatuses') }}</option>
        <option value="draft">{{ t('status.draft') }}</option>
        <option value="active">{{ t('status.active') }}</option>
        <option value="disabled">{{ t('status.disabled') }}</option>
        <option value="error">{{ t('status.error') }}</option>
        <option value="archived">{{ t('status.archived') }}</option>
      </select>
    </div>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <div class="k-flex k-gap-4 k-items-start">
      <aside class="k-w-56 k-shrink-0 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-3 k-space-y-2">
        <div class="k-flex k-items-center k-gap-2 k-text-xs k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">
          <Folder :size="12" /> {{ t('workflowsPage.foldersTitle') }}
        </div>
        <button
          type="button"
          class="k-w-full k-text-left k-px-2 k-py-1.5 k-rounded-knot-sm k-text-sm"
          :class="selectedFolderId === 'all' ? 'k-bg-knot-primary-soft k-text-knot-primary k-font-semibold' : 'hover:k-bg-knot-surface-soft k-text-knot-text'"
          @click="selectedFolderId = 'all'"
        >
          {{ t('workflowsPage.folderAll') }}
        </button>
        <button
          type="button"
          class="k-w-full k-text-left k-px-2 k-py-1.5 k-rounded-knot-sm k-text-sm"
          :class="selectedFolderId === 'unfiled' ? 'k-bg-knot-primary-soft k-text-knot-primary k-font-semibold' : 'hover:k-bg-knot-surface-soft k-text-knot-text'"
          @click="selectedFolderId = 'unfiled'"
        >
          {{ t('workflowsPage.folderUnfiled') }}
        </button>
        <button
          v-for="folder in folders"
          :key="folder.id"
          type="button"
          class="k-w-full k-text-left k-px-2 k-py-1.5 k-rounded-knot-sm k-text-sm k-truncate"
          :class="selectedFolderId === folder.id ? 'k-bg-knot-primary-soft k-text-knot-primary k-font-semibold' : 'hover:k-bg-knot-surface-soft k-text-knot-text'"
          @click="selectedFolderId = folder.id"
        >
          {{ folder.label }}
        </button>
        <div class="k-pt-2 k-border-t k-border-knot-border k-space-y-2">
          <input
            v-model="newFolderLabel"
            type="text"
            :placeholder="t('workflowsPage.folderNewPlaceholder')"
            class="k-w-full k-px-2 k-py-1.5 k-text-xs k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface"
            @keyup.enter="createFolder"
          />
          <button
            type="button"
            class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border hover:k-border-knot-primary disabled:k-opacity-50"
            :disabled="!newFolderLabel.trim() || folderBusy"
            @click="createFolder"
          >
            {{ t('workflowsPage.folderCreate') }}
          </button>
        </div>
      </aside>

      <div class="k-flex-1 k-min-w-0 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-overflow-x-auto">
      <table class="k-w-full k-text-sm">
        <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
          <tr>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.thRef') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.thLabel') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.thStatus') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.folderAssign') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.thUpdated') }}</th>
            <th class="k-text-right k-px-4 k-py-3 k-font-semibold">{{ t('workflowsPage.thActions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!filtered.length && !loading">
            <td colspan="6" class="k-px-4 k-py-10">
              <div class="k-flex k-flex-col k-items-center k-text-center k-gap-3">
                <div class="k-w-14 k-h-14 k-rounded-knot-pill k-bg-knot-hero k-text-white k-flex k-items-center k-justify-center k-shadow-[0_8px_24px_rgba(99,102,241,0.35)]">
                  <Workflow :size="22" />
                </div>
                <div class="k-text-base k-font-semibold k-text-knot-text">
                  {{ t('workflowsPage.emptyTitle') }}
                </div>
                <p class="k-text-sm k-text-knot-text-muted k-max-w-md">
                  {{ t('workflowsPage.emptyBody') }}
                </p>
                <div class="k-flex k-flex-wrap k-items-center k-gap-2 k-mt-1">
                  <a
                    v-if="marketplaceChromeEnabled"
                    :href="`${baseUrl}?mode=marketplace&tab=templates`"
                    class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-pill k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] hover:k-shadow-[0_12px_24px_rgba(99,102,241,0.5)] k-transition-shadow k-no-underline"
                  >
                    <Library :size="14" /> {{ t('workflowsPage.fromTemplate') }}
                  </a>
                  <button
                    @click="createBlank"
                    class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-pill k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary k-transition-colors"
                  >
                    <PlusCircle :size="14" /> {{ t('workflowsPage.blankWorkflow') }}
                  </button>
                </div>
              </div>
            </td>
          </tr>
          <tr
            v-for="wf in filtered"
            :key="wf.id"
            class="k-border-t k-border-knot-border hover:k-bg-knot-surface-soft k-transition-colors"
          >
            <td class="k-px-4 k-py-2.5 k-font-mono k-text-knot-text-muted">{{ wf.ref }}</td>
            <td class="k-px-4 k-py-2.5">
              <div class="k-flex k-items-center k-gap-2">
                <a :href="editUrl(wf.id)" class="k-text-knot-text k-font-semibold hover:k-text-knot-primary">{{ wf.label }}</a>
                <span
                  v-if="wf.riskWorstLevel && wf.riskWorstLevel !== 'safe'"
                  class="k-inline-block k-h-2.5 k-w-2.5 k-rounded-full k-shrink-0"
                  :style="{ backgroundColor: minimapRiskHex(workflowRiskLevel(wf)) }"
                  :title="workflowRiskTitle(wf)"
                  :aria-label="workflowRiskTitle(wf)"
                />
                <span
                  v-if="isGuide(wf)"
                  class="k-inline-flex k-items-center k-gap-1 k-px-1.5 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider k-bg-knot-accent-soft k-text-knot-accent"
                  :title="t('workflowsPage.guideTitle')"
                >
                  <BookOpen :size="10" />
                  {{ t('workflowsPage.guideBadge') }}
                </span>
              </div>
              <div v-if="wf.description" class="k-text-xs k-text-knot-text-soft k-truncate k-max-w-[420px]">{{ wf.description }}</div>
            </td>
            <td class="k-px-4 k-py-2.5">
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold', statusBadge(wf.status)]">
                {{ workflowStatusLabel(wf.status) }}
              </span>
            </td>
            <td class="k-px-4 k-py-2.5">
              <select
                class="k-text-xs k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1 k-max-w-[140px]"
                :value="wf.folderId ?? ''"
                :disabled="folderBusy"
                @change="assignWorkflowFolder(wf.id, ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
              >
                <option value="">{{ t('workflowsPage.folderNone') }}</option>
                <option v-for="folder in folders" :key="folder.id" :value="folder.id">
                  {{ folder.label }}
                </option>
              </select>
            </td>
            <td class="k-px-4 k-py-2.5 k-text-knot-text-soft">{{ wf.updatedAt }}</td>
            <td class="k-px-4 k-py-2.5 k-text-right">
              <a :href="editUrl(wf.id)" class="k-inline-flex k-items-center k-gap-1 k-text-knot-primary k-text-xs k-font-semibold hover:k-underline">
                {{ t('workflowsPage.edit') }} <ExternalLink :size="11" />
              </a>
              <a :href="executionsUrl(wf.id)" class="k-inline-flex k-items-center k-gap-1 k-text-knot-text-muted k-text-xs k-font-semibold hover:k-text-knot-primary k-ml-3">
                {{ t('workflowsPage.executions') }}
              </a>
              <button
                @click="cloneWorkflow(wf)"
                :disabled="loading"
                class="k-inline-flex k-items-center k-gap-1 k-text-knot-text-muted k-text-xs k-font-semibold hover:k-text-knot-primary k-ml-3 disabled:k-opacity-50"
                :title="t('workflowsPage.cloneTitle')"
              >
                <Copy :size="11" />
                {{ t('workflowsPage.clone') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</template>
