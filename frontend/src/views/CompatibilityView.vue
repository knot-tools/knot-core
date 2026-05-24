<!--
  Schema compatibility — pilot snapshots and offline-style diffs (V2.7).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ClipboardCopy, Loader2 } from 'lucide-vue-next';
import { knotApi } from '../lib/api';

const { t } = useI18n();

const tab = ref<'live' | 'compare'>('live');
const liveJson = ref('');
const baselineJson = ref('');
const targetJson = ref('');
const workflowsJson = ref('[]');
const reportMd = ref('');
const resultJson = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const copied = ref(false);

const bundledSnapshots = ref<
  Array<{
    filename: string;
    dolibarr_version: string;
    schema_version: string;
    generated_at: string | null;
  }>
>([]);
const bundledCatalogLoaded = ref(false);
const bundledPick = ref('');

watch(bundledSnapshots, (list) => {
  if (list.length > 0 && bundledPick.value === '') {
    bundledPick.value = list[0].filename;
  }
});

watch(tab, (value) => {
  if (value === 'compare') {
    void ensureBundledCatalog();
  }
});

async function ensureBundledCatalog() {
  if (bundledCatalogLoaded.value) {
    return;
  }
  loading.value = true;
  error.value = null;
  try {
    const data = await knotApi.fetchCompatibilityBundledSnapshots();
    bundledSnapshots.value = data.snapshots ?? [];
    bundledCatalogLoaded.value = true;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Bundled catalog failed.';
  } finally {
    loading.value = false;
  }
}

async function loadBundledBaseline() {
  const file = bundledPick.value.trim();
  if (!file) return;
  loading.value = true;
  error.value = null;
  try {
    const data = await knotApi.fetchCompatibilityBundledSnapshot(file);
    baselineJson.value = JSON.stringify(data.snapshot ?? {}, null, 2);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Bundled baseline load failed.';
  } finally {
    loading.value = false;
  }
}

async function loadBundledTarget() {
  const file = bundledPick.value.trim();
  if (!file) return;
  loading.value = true;
  error.value = null;
  try {
    const data = await knotApi.fetchCompatibilityBundledSnapshot(file);
    targetJson.value = JSON.stringify(data.snapshot ?? {}, null, 2);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Bundled target load failed.';
  } finally {
    loading.value = false;
  }
}

async function loadLive() {
  loading.value = true;
  error.value = null;
  copied.value = false;
  try {
    const data = await knotApi.fetchCompatibilitySnapshotLive();
    liveJson.value = JSON.stringify(data.snapshot ?? {}, null, 2);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Live snapshot failed.';
  } finally {
    loading.value = false;
  }
}

async function loadSample() {
  loading.value = true;
  error.value = null;
  try {
    const data = await knotApi.fetchCompatibilitySample();
    baselineJson.value = JSON.stringify(data.snapshot ?? {}, null, 2);
    tab.value = 'compare';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Sample load failed.';
  } finally {
    loading.value = false;
  }
}

async function runDiff() {
  loading.value = true;
  error.value = null;
  reportMd.value = '';
  resultJson.value = '';
  try {
    let baseline: Record<string, unknown>;
    let target: Record<string, unknown>;
    try {
      baseline = JSON.parse(baselineJson.value || '{}') as Record<string, unknown>;
      target = JSON.parse(targetJson.value || '{}') as Record<string, unknown>;
    } catch {
      error.value = t('compatibilityPage.parseError');
      return;
    }
    let workflows: unknown[] | undefined;
    const wfRaw = workflowsJson.value.trim();
    if (wfRaw !== '' && wfRaw !== '[]') {
      const parsed = JSON.parse(wfRaw) as unknown;
      workflows = Array.isArray(parsed) ? parsed : [parsed];
    }
    const out = await knotApi.computeCompatibilityDiff({
      baseline,
      target,
      workflows,
    });
    reportMd.value = out.report_markdown ?? '';
    resultJson.value = JSON.stringify(
      { diff: out.diff, breaking: out.breaking, workflow_hints: out.workflow_hints },
      null,
      2,
    );
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Diff failed.';
  } finally {
    loading.value = false;
  }
}

async function copyReport() {
  if (!reportMd.value) return;
  try {
    await navigator.clipboard.writeText(reportMd.value);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch {
    error.value = 'Clipboard unavailable.';
  }
}
</script>

<template>
  <div class="k-max-w-5xl k-mx-auto k-p-6 k-space-y-6">
    <header class="k-space-y-1">
      <h1 class="k-text-2xl k-font-semibold k-text-knot-text">{{ t('compatibilityPage.title') }}</h1>
      <p class="k-text-sm k-text-knot-text-muted">{{ t('compatibilityPage.subtitle') }}</p>
    </header>

    <div class="k-flex k-flex-wrap k-gap-2">
      <button
        type="button"
        class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm"
        :class="tab === 'live' ? 'k-ring-2 k-ring-knot-primary' : ''"
        @click="tab = 'live'"
      >
        {{ t('compatibilityPage.tabLive') }}
      </button>
      <button
        type="button"
        class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm"
        :class="tab === 'compare' ? 'k-ring-2 k-ring-knot-primary' : ''"
        @click="tab = 'compare'"
      >
        {{ t('compatibilityPage.tabCompare') }}
      </button>
    </div>

    <div
      v-if="error"
      class="k-rounded-lg k-border k-border-knot-danger k-bg-knot-danger-soft k-px-4 k-py-3 k-text-sm k-text-knot-danger"
    >
      {{ error }}
    </div>

    <section v-if="tab === 'live'" class="k-space-y-3">
      <div class="k-flex k-flex-wrap k-gap-2">
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-primary k-px-3 k-py-2 k-text-sm k-text-white"
          :disabled="loading"
          @click="loadLive"
        >
          <Loader2 v-if="loading" class="k-h-4 k-w-4 k-animate-spin" />
          {{ t('compatibilityPage.loadLive') }}
        </button>
      </div>
      <textarea
        v-model="liveJson"
        rows="18"
        readonly
        class="k-w-full k-font-mono k-text-xs k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-3"
        placeholder="JSON…"
      />
    </section>

    <section v-else class="k-space-y-4">
      <div class="k-flex k-flex-wrap k-gap-2">
        <button
          type="button"
          class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm"
          :disabled="loading"
          @click="loadSample"
        >
          {{ t('compatibilityPage.loadSample') }}
        </button>
        <button
          type="button"
          class="k-rounded-lg k-border k-border-knot-border k-bg-knot-primary k-px-3 k-py-2 k-text-sm k-text-white"
          :disabled="loading"
          @click="runDiff"
        >
          {{ t('compatibilityPage.runDiff') }}
        </button>
      </div>

      <div class="k-space-y-2">
        <p class="k-text-xs k-text-knot-text-muted">{{ t('compatibilityPage.bundledCatalogHint') }}</p>
        <div class="k-flex k-flex-wrap k-items-center k-gap-2">
          <select
            id="knot-bundled-snapshot"
            v-model="bundledPick"
            :aria-label="t('compatibilityPage.bundledSnapshotSelectLabel')"
            class="k-min-w-[14rem] k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-px-2 k-py-1.5 k-text-xs"
          >
            <option v-for="s in bundledSnapshots" :key="s.filename" :value="s.filename">
              {{ s.filename }} · {{ s.dolibarr_version }}
            </option>
          </select>
          <button
            type="button"
            class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm"
            :disabled="loading || bundledSnapshots.length === 0"
            @click="loadBundledBaseline"
          >
            {{ t('compatibilityPage.loadBundledBaseline') }}
          </button>
          <button
            type="button"
            class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm"
            :disabled="loading || bundledSnapshots.length === 0"
            @click="loadBundledTarget"
          >
            {{ t('compatibilityPage.loadBundledTarget') }}
          </button>
        </div>
      </div>

      <div class="k-grid k-gap-4 md:k-grid-cols-2">
        <div class="k-space-y-1">
          <label class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('compatibilityPage.baselineLabel') }}</label>
          <textarea v-model="baselineJson" rows="12" class="k-w-full k-font-mono k-text-xs k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-2" />
        </div>
        <div class="k-space-y-1">
          <label class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('compatibilityPage.targetLabel') }}</label>
          <textarea v-model="targetJson" rows="12" class="k-w-full k-font-mono k-text-xs k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-2" />
        </div>
      </div>

      <div class="k-space-y-1">
        <label class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('compatibilityPage.workflowsLabel') }}</label>
        <textarea v-model="workflowsJson" rows="4" class="k-w-full k-font-mono k-text-xs k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-2" />
      </div>

      <div class="k-space-y-2">
        <div class="k-flex k-items-center k-justify-between">
          <h2 class="k-text-sm k-font-semibold">{{ t('compatibilityPage.reportLabel') }}</h2>
          <button
            type="button"
            class="k-inline-flex k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-1.5 k-text-xs"
            :disabled="!reportMd"
            @click="copyReport"
          >
            <ClipboardCopy class="k-h-3.5 k-w-3.5" />
            {{ copied ? t('compatibilityPage.copiedLabel') : t('compatibilityPage.copyReport') }}
          </button>
        </div>
        <pre class="k-max-h-64 k-overflow-auto k-whitespace-pre-wrap k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-text-xs">{{ reportMd || '—' }}</pre>
      </div>

      <div class="k-space-y-1">
        <h2 class="k-text-sm k-font-semibold">{{ t('compatibilityPage.resultLabel') }}</h2>
        <pre class="k-max-h-56 k-overflow-auto k-whitespace-pre-wrap k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-text-xs">{{ resultJson || '—' }}</pre>
      </div>
    </section>
  </div>
</template>
