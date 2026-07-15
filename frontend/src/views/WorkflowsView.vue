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
  Trash2,
  History,
  CheckSquare,
  Square,
  Gift,
} from 'lucide-vue-next';
import { knotApi, type WorkflowSummary, formatWorkflowImportWarningLine } from '../lib/api';
import { normalizeWorkflowImport, parseWorkflowImportText, WorkflowImportFormatError } from '../lib/normalizeWorkflowImport';
import { minimapRiskHex, type RiskLevel } from '../composables/useNodeRisk';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';
import { useToast } from '../composables/useToast';
import { useConfirm } from '../composables/useConfirm';
import { useI18n } from 'vue-i18n';
import MigrationBanner from '../components/licensing/MigrationBanner.vue';
import KEmptyState from '../components/ui/KEmptyState.vue';

const { t, te } = useI18n();

const toast = useToast();
const confirmDialog = useConfirm();

const items = ref<WorkflowSummary[]>([]);
const counts = ref<Record<string, number>>({});
const loading = ref(false);
const starterBusy = ref(false);
const error = ref<string | null>(null);

/** Deep-link from dashboard star journey: ?starter=02-relance-facture-impayee */
const starterSlug = ref<string | null>(null);
try {
  const q = new URLSearchParams(window.location.search);
  const s = q.get('starter');
  starterSlug.value = s && /^[a-z0-9][a-z0-9-]{1,80}$/i.test(s) ? s : null;
} catch {
  starterSlug.value = null;
}

const starterFileHint = computed(() =>
  starterSlug.value ? `examples/starter/${starterSlug.value}.knot.json` : null,
);

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

function toggleSelectId(id: number) {
  const idx = selectedIds.value.indexOf(id);
  if (idx >= 0) {
    selectedIds.value.splice(idx, 1);
  } else {
    selectedIds.value.push(id);
  }
}

const allFilteredSelected = computed(() =>
  filtered.value.length > 0 && filtered.value.every((w) => selectedIds.value.includes(w.id)),
);

function toggleSelectAll() {
  if (allFilteredSelected.value) {
    const ids = new Set(filtered.value.map((w) => w.id));
    selectedIds.value = selectedIds.value.filter((id) => !ids.has(id));
  } else {
    const current = new Set(selectedIds.value);
    for (const w of filtered.value) {
      current.add(w.id);
    }
    selectedIds.value = [...current];
  }
}

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

function goMarketplaceTemplates() {
  window.location.href = `${baseUrl.value}?mode=marketplace&tab=templates`;
}

async function deleteWorkflow(wf: WorkflowSummary) {
  const ok = await confirmDialog.confirm({
    title: t('workflowsPage.deleteTitle'),
    message: t('workflowsPage.deleteConfirm', { ref: wf.ref, label: wf.label }),
  });
  if (!ok) return;
  loading.value = true;
  error.value = null;
  try {
    await knotApi.bulkWorkflows({ operation: 'delete', ids: [wf.id] });
    toast.success(t('workflowsPage.deleteSuccess', { label: wf.label }));
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('workflowsPage.deleteFailed');
    toast.error(error.value);
  } finally {
    loading.value = false;
  }
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

async function importStarterTemplates() {
  if (starterBusy.value) return;
  starterBusy.value = true;
  error.value = null;
  try {
    const result = await knotApi.importStarters();
    const count = result.imported?.length ?? 0;
    toast.success(t('marketplace.startersImported', { count }));
    await load();
  } catch (err) {
    const msg = err instanceof Error ? err.message : t('workflowsPage.createFailed');
    error.value = msg;
    toast.error(msg);
  } finally {
    starterBusy.value = false;
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
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-5">
    <header class="k-flex k-flex-wrap k-items-center k-justify-between k-gap-3 k-min-w-0">
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
      <div class="k-flex k-flex-wrap k-items-center k-gap-2 k-min-w-0 k-justify-end">
        <button
          @click="load"
          :disabled="loading"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-60"
        >
          <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
          <RefreshCw v-else :size="14" />
          <span class="k-hidden xl:k-inline">{{ t('actions.refresh') }}</span>
        </button>
        <a
          v-if="marketplaceChromeEnabled"
          :href="`${baseUrl}?mode=marketplace&tab=templates`"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary k-no-underline"
        >
          <Library :size="14" />
          <span class="k-hidden xl:k-inline">{{ t('workflowsPage.fromTemplate') }}</span>
        </a>
        <button
          @click="importJsonClick"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary"
        >
          <Upload :size="14" />
          <span class="k-hidden xl:k-inline">{{ t('actions.import') }}</span>
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
          class="k-inline-flex k-items-center k-gap-2 k-px-3.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] hover:k-shadow-[0_12px_24px_rgba(99,102,241,0.45)] disabled:k-opacity-60 k-flex-shrink-0"
        >
          <PlusCircle :size="14" />
          {{ t('workflowsPage.newWorkflow') }}
        </button>
      </div>
    </header>

    <MigrationBanner global />

    <aside
      v-if="starterFileHint"
      class="k-rounded-knot-md k-border k-border-knot-primary/30 k-bg-knot-primary-soft k-text-knot-primary k-px-4 k-py-3 k-text-sm"
      data-testid="starter-import-hint"
      role="status"
    >
      <p class="k-font-semibold">{{ t('workflowsPage.starterHintTitle') }}</p>
      <p class="k-text-xs k-mt-1 k-opacity-90">
        {{ t('workflowsPage.starterHintBody', { file: starterFileHint }) }}
      </p>
    </aside>

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

    <div class="k-flex k-gap-4 k-items-start k-w-full k-min-w-0">
      <aside class="k-w-52 xl:k-w-56 k-shrink-0 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-3 k-space-y-2">
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

      <div
        class="k-flex-1 k-min-w-0 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-overflow-x-auto"
        data-knot-test="workflows-table-scroll"
      >
      <table class="k-w-full k-text-sm knot-workflows-table" data-knot-test="workflows-table">
        <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
          <tr>
            <th class="k-px-3 k-py-3 k-w-8">
              <button
                type="button"
                class="k-flex k-items-center k-justify-center k-text-knot-text-muted hover:k-text-knot-primary"
                :title="t('workflowsPage.selectAll')"
                @click="toggleSelectAll"
              >
                <CheckSquare v-if="allFilteredSelected && filtered.length > 0" :size="15" class="k-text-knot-primary" />
                <Square v-else :size="15" />
              </button>
            </th>
            <th class="k-text-left k-px-3 k-py-3 k-font-semibold k-whitespace-nowrap">{{ t('workflowsPage.thRef') }}</th>
            <th class="k-text-left k-px-3 k-py-3 k-font-semibold">{{ t('workflowsPage.thLabel') }}</th>
            <th class="k-text-left k-px-3 k-py-3 k-font-semibold k-whitespace-nowrap">{{ t('workflowsPage.thStatus') }}</th>
            <th class="k-text-left k-px-3 k-py-3 k-font-semibold k-whitespace-nowrap">{{ t('workflowsPage.folderAssign') }}</th>
            <th class="k-text-left k-px-3 k-py-3 k-font-semibold k-whitespace-nowrap">{{ t('workflowsPage.thUpdated') }}</th>
            <th class="k-text-right k-px-3 k-py-3 k-font-semibold k-whitespace-nowrap">{{ t('workflowsPage.thActions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!filtered.length && !loading">
            <td colspan="7" class="k-px-4 k-py-6">
              <KEmptyState
                :title="t('workflowsPage.emptyTitle')"
                :body="t('workflowsPage.emptyBody')"
                :action-label="starterBusy ? t('workflowsPage.emptyStarterBusy') : t('workflowsPage.emptyStarterCta')"
                :secondary-label="t('workflowsPage.blankWorkflow')"
                :tertiary-label="marketplaceChromeEnabled ? t('workflowsPage.fromTemplate') : undefined"
                @action="importStarterTemplates"
                @secondary="createBlank"
                @tertiary="goMarketplaceTemplates"
              >
                <template #icon>
                  <Gift v-if="!starterBusy" :size="22" />
                  <Loader2 v-else :size="22" class="k-animate-spin" />
                </template>
              </KEmptyState>
            </td>
          </tr>
          <tr
            v-for="wf in filtered"
            :key="wf.id"
            class="k-border-t k-border-knot-border hover:k-bg-knot-surface-soft k-transition-colors"
          >
            <td class="k-px-3 k-py-2.5 k-align-top k-w-8">
              <button
                type="button"
                class="k-flex k-items-center k-justify-center k-text-knot-text-muted hover:k-text-knot-primary"
                @click="toggleSelectId(wf.id)"
              >
                <CheckSquare v-if="selectedIds.includes(wf.id)" :size="15" class="k-text-knot-primary" />
                <Square v-else :size="15" />
              </button>
            </td>
            <td class="k-px-3 k-py-2.5 k-font-mono k-text-[11px] k-text-knot-text-muted k-whitespace-nowrap k-align-top">{{ wf.ref }}</td>
            <td class="k-px-3 k-py-2.5 k-align-top">
              <div class="k-flex k-items-center k-gap-1.5 k-min-w-0">
                <a
                  :href="editUrl(wf.id)"
                  class="k-text-knot-text k-font-semibold hover:k-text-knot-primary"
                  :title="wf.label"
                >{{ wf.label }}</a>
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
              <div
                v-if="wf.description"
                class="k-mt-0.5 k-text-xs k-text-knot-text-soft k-line-clamp-1"
                :title="wf.description"
              >{{ wf.description }}</div>
            </td>
            <td class="k-px-3 k-py-2.5 k-align-top k-whitespace-nowrap">
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold', statusBadge(wf.status)]">
                {{ workflowStatusLabel(wf.status) }}
              </span>
            </td>
            <td class="k-px-3 k-py-2.5 k-align-top">
              <select
                class="k-text-xs k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1 k-min-w-[7rem] k-max-w-[11rem]"
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
            <td class="k-px-3 k-py-2.5 k-text-knot-text-soft k-whitespace-nowrap k-text-xs k-align-top">{{ wf.updatedAt }}</td>
            <td
              class="k-px-2 k-py-2 k-align-top k-whitespace-nowrap"
              data-knot-test="workflow-row-actions"
            >
              <div class="k-inline-flex k-items-center k-justify-end k-gap-0.5">
                <a
                  :href="editUrl(wf.id)"
                  class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-primary hover:k-bg-knot-primary-soft"
                  :title="t('workflowsPage.edit')"
                  :aria-label="t('workflowsPage.edit')"
                >
                  <ExternalLink :size="15" />
                </a>
                <a
                  :href="executionsUrl(wf.id)"
                  class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft hover:k-text-knot-primary"
                  :title="t('workflowsPage.executions')"
                  :aria-label="t('workflowsPage.executions')"
                >
                  <History :size="15" />
                </a>
                <button
                  type="button"
                  @click="cloneWorkflow(wf)"
                  :disabled="loading"
                  class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft hover:k-text-knot-primary disabled:k-opacity-50"
                  :title="t('workflowsPage.cloneTitle')"
                  :aria-label="t('workflowsPage.clone')"
                >
                  <Copy :size="15" />
                </button>
                <button
                  type="button"
                  @click="deleteWorkflow(wf)"
                  :disabled="loading"
                  class="k-inline-flex k-items-center k-justify-center k-h-8 k-w-8 k-rounded-knot-sm k-text-knot-danger hover:k-bg-knot-danger-soft disabled:k-opacity-50"
                  :title="t('workflowsPage.deleteTitle')"
                  :aria-label="t('workflowsPage.delete')"
                >
                  <Trash2 :size="15" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</template>
