<!--
  AuditView — read-only audit log (admin / authorized users only).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ScrollText, RefreshCw, Filter, Download } from 'lucide-vue-next';
import { knotApi, type AuditEntry } from '../lib/api';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const entries = ref<AuditEntry[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const filter = ref({ actionType: '', entityType: '', search: '' });

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.listAudit({
      actionType: filter.value.actionType || undefined,
      entityType: filter.value.entityType || undefined,
      q: filter.value.search.trim() || undefined,
      limit: 200,
    });
    entries.value = result.audit;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('auditPage.loadFailed');
  } finally {
    loading.value = false;
  }
}

function exportCsv() {
  const params = new URLSearchParams({ format: 'csv', limit: '5000' });
  if (filter.value.actionType) params.set('action_type', filter.value.actionType);
  if (filter.value.entityType) params.set('entity_type', filter.value.entityType);
  if (filter.value.search.trim()) params.set('q', filter.value.search.trim());
  const url = `${baseUrl.value.replace(/\?.*$/, '')}/custom/knot/api/audit.php?${params.toString()}`;
  window.location.href = url;
}

function formatDate(value: string): string {
  try {
    return new Date(value.replace(' ', 'T')).toLocaleString();
  } catch {
    return value;
  }
}

onMounted(load);
</script>

<template>
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4 k-flex-wrap">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-flex k-items-center k-justify-center">
          <ScrollText :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('auditPage.title') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">{{ t('auditPage.subtitle') }}</p>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          @click="exportCsv"
          :disabled="loading || !entries.length"
          class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-sm k-flex k-items-center k-gap-1.5 k-text-knot-text hover:k-border-knot-primary disabled:k-opacity-50"
          :title="t('auditPage.exportCsvTitle')"
        >
          <Download :size="14" /> {{ t('auditPage.exportCsv') }}
        </button>
        <button @click="load" class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-sm k-flex k-items-center k-gap-1.5 k-text-knot-text hover:k-border-knot-primary">
          <RefreshCw :size="14" /> {{ t('actions.refresh') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-2 k-rounded-knot-sm k-text-sm">{{ error }}</div>

    <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-3 k-flex k-items-center k-gap-2 k-flex-wrap">
      <Filter :size="14" class="k-text-knot-text-muted" />
      <input v-model="filter.actionType" :placeholder="t('auditPage.placeholderActionType')" class="k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
      <input v-model="filter.entityType" :placeholder="t('auditPage.placeholderEntityType')" class="k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
      <input v-model="filter.search" :placeholder="t('auditPage.placeholderSearch')" class="k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text k-min-w-[240px]" />
      <button @click="load" class="k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm">{{ t('auditPage.apply') }}</button>
    </div>

    <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-overflow-x-auto">
      <div v-if="loading" class="k-py-12 k-text-center k-text-knot-text-muted k-text-sm">{{ t('auditPage.loading') }}</div>
      <div v-else-if="!entries.length" class="k-py-12 k-text-center k-text-knot-text-soft k-text-sm">{{ t('auditPage.empty') }}</div>
      <table v-else class="k-w-full k-text-sm">
        <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase">
          <tr>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thWhen') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thAction') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thEntity') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thUser') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thIp') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('auditPage.thPayload') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id" class="k-border-t k-border-knot-border k-align-top">
            <td class="k-px-3 k-py-2 k-text-knot-text-muted k-whitespace-nowrap">{{ formatDate(e.createdAt) }}</td>
            <td class="k-px-3 k-py-2 k-font-mono k-text-knot-primary">{{ e.actionType }}</td>
            <td class="k-px-3 k-py-2 k-text-knot-text">{{ e.entityType }}<span v-if="e.entityId !== null" class="k-text-knot-text-soft"> #{{ e.entityId }}</span></td>
            <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ e.userId ?? '—' }}</td>
            <td class="k-px-3 k-py-2 k-font-mono k-text-knot-text-soft">{{ e.ip }}</td>
            <td class="k-px-3 k-py-2"><pre class="k-text-[10px] k-text-knot-text-muted k-whitespace-pre-wrap k-max-w-md k-truncate">{{ JSON.stringify(e.payload) }}</pre></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
