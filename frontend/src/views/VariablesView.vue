<!--
  VariablesView — manage llx_knot_variable.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Variable, Plus, Trash2, Save, Copy, RefreshCw, Lock } from 'lucide-vue-next';
import { knotApi, type KnotVariable } from '../lib/api';
import { useConfirm } from '../composables/useConfirm';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const confirmDialog = useConfirm();

const variables = ref<KnotVariable[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const draft = ref<Partial<KnotVariable>>({ ref: '', label: '', value: '', scope: 'global', isSecret: false });

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.listVariables();
    variables.value = result.variables;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('variablesPage.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function add() {
  if (!draft.value.ref) return;
  try {
    await knotApi.createVariable(draft.value);
    draft.value = { ref: '', label: '', value: '', scope: 'global', isSecret: false };
    success.value = t('variablesPage.created');
    setTimeout(() => (success.value = null), 2500);
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('variablesPage.createFailed');
  }
}

async function remove(v: KnotVariable) {
  const ok = await confirmDialog.confirm({
    title: t('variablesPage.deleteConfirmTitle'),
    message: t('variablesPage.deleteConfirmMessage', { ref: v.ref }),
    danger: true,
  });
  if (!ok) return;
  try {
    await knotApi.deleteVariable(v.id);
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('variablesPage.deleteFailed');
  }
}

async function save(v: KnotVariable) {
  try {
    await knotApi.updateVariable(v.id, { label: v.label, value: v.value ?? '', scope: v.scope, isSecret: v.isSecret });
    success.value = t('variablesPage.updated', { ref: v.ref });
    setTimeout(() => (success.value = null), 2500);
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('variablesPage.updateFailed');
  }
}

function copyExpr(v: KnotVariable) {
  navigator.clipboard?.writeText(`{{$vars.${v.ref}}}`);
  success.value = t('variablesPage.copied', { ref: v.ref });
  setTimeout(() => (success.value = null), 1800);
}

function scopeLabel(scope: string): string {
  if (scope === 'global') return t('variablesPage.scopeGlobal');
  if (scope === 'workflow') return t('variablesPage.scopeWorkflow');
  if (scope === 'env') return t('variablesPage.scopeEnv');
  return scope;
}

onMounted(load);
</script>

<template>
  <div class="k-p-6 k-max-w-[1300px] k-mx-auto k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4 k-flex-wrap">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <Variable :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('nav.variables') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">{{ t('variablesPage.subtitle') }}</p>
        </div>
      </div>
      <button @click="load" class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-sm k-flex k-items-center k-gap-1.5 k-text-knot-text hover:k-border-knot-primary">
        <RefreshCw :size="14" /> {{ t('actions.refresh') }}
      </button>
    </header>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-2 k-rounded-knot-sm k-text-sm">{{ error }}</div>
    <div v-if="success" class="k-bg-knot-success-soft k-text-knot-success k-px-4 k-py-2 k-rounded-knot-sm k-text-sm">{{ success }}</div>

    <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('variablesPage.newVariable') }}</h2>
      <div class="k-grid k-grid-cols-1 md:k-grid-cols-5 k-gap-2">
        <input v-model="draft.ref" :placeholder="t('variablesPage.placeholderRef')" class="k-px-2.5 k-py-2 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
        <input v-model="draft.label" :placeholder="t('variablesPage.placeholderLabel')" class="k-px-2.5 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
        <input v-model="draft.value" :type="draft.isSecret ? 'password' : 'text'" :placeholder="t('variablesPage.placeholderValue')" class="k-px-2.5 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
        <select v-model="draft.scope" class="k-px-2.5 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text">
          <option value="global">{{ t('variablesPage.scopeGlobal') }}</option>
          <option value="workflow">{{ t('variablesPage.scopeWorkflow') }}</option>
          <option value="env">{{ t('variablesPage.scopeEnv') }}</option>
        </select>
        <button @click="add" class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-inline-flex k-items-center k-justify-center k-gap-1.5">
          <Plus :size="14" /> {{ t('variablesPage.addButton') }}
        </button>
      </div>
      <label class="k-flex k-items-center k-gap-2 k-text-xs k-text-knot-text-muted">
        <input type="checkbox" v-model="draft.isSecret" /> {{ t('variablesPage.markSecret') }}
      </label>
    </div>

    <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-overflow-hidden">
      <div v-if="loading" class="k-py-12 k-text-center k-text-knot-text-muted k-text-sm">{{ t('variablesPage.loading') }}</div>
      <div v-else-if="!variables.length" class="k-py-12 k-text-center k-text-knot-text-soft k-text-sm">{{ t('variablesPage.noneYet') }}</div>
      <table v-else class="k-w-full k-text-sm">
        <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase">
          <tr>
            <th class="k-text-left k-px-3 k-py-2">{{ t('variablesPage.colRef') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('variablesPage.colLabel') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('variablesPage.colValue') }}</th>
            <th class="k-text-left k-px-3 k-py-2">{{ t('variablesPage.colScope') }}</th>
            <th class="k-text-right k-px-3 k-py-2">{{ t('variablesPage.colActions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in variables" :key="v.id" class="k-border-t k-border-knot-border">
            <td class="k-px-3 k-py-2 k-font-mono k-text-knot-primary">{{ v.ref }}</td>
            <td class="k-px-3 k-py-2"><input v-model="v.label" class="k-w-full k-px-2 k-py-1 k-text-sm k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-text-knot-text" /></td>
            <td class="k-px-3 k-py-2">
              <input v-if="!v.isSecret" v-model="v.value" class="k-w-full k-px-2 k-py-1 k-text-sm k-font-mono k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-text-knot-text" />
              <span v-else class="k-text-knot-text-soft k-flex k-items-center k-gap-1"><Lock :size="11" /> {{ t('variablesPage.masked') }}</span>
            </td>
            <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ scopeLabel(v.scope) }}</td>
            <td class="k-px-3 k-py-2 k-text-right">
              <button @click="copyExpr(v)" class="k-px-2 k-py-1 k-rounded-knot-sm k-text-knot-text-soft hover:k-text-knot-primary k-text-xs k-inline-flex k-items-center k-gap-1 k-mr-1" :title="t('variablesPage.copyExprTitle')"><Copy :size="11" /></button>
              <button @click="save(v)" class="k-px-2 k-py-1 k-rounded-knot-sm k-text-knot-primary hover:k-bg-knot-primary-soft k-text-xs k-inline-flex k-items-center k-gap-1 k-mr-1"><Save :size="11" /> {{ t('actions.save') }}</button>
              <button @click="remove(v)" class="k-px-2 k-py-1 k-rounded-knot-sm k-text-knot-danger hover:k-bg-knot-danger-soft k-text-xs k-inline-flex k-items-center k-gap-1"><Trash2 :size="11" /> {{ t('actions.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
