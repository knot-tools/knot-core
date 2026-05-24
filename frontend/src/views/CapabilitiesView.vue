<!--
  Capabilities — instance manifest for support and imports.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ClipboardCopy, Loader2, RefreshCw } from 'lucide-vue-next';
import { knotApi, type KnotCapabilitiesResponse } from '../lib/api';

const { t } = useI18n();

const data = ref<KnotCapabilitiesResponse | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const copied = ref(false);

async function load(refresh = false) {
  loading.value = true;
  error.value = null;
  copied.value = false;
  try {
    data.value = await knotApi.capabilities({ refresh });
  } catch (err) {
    let msg = err instanceof Error ? err.message : t('capabilitiesPage.loadFailed');
    const withDetails = err as Error & { details?: { detail?: string } };
    const detail =
      withDetails.details &&
      typeof withDetails.details === 'object' &&
      typeof withDetails.details.detail === 'string'
        ? withDetails.details.detail.trim()
        : '';
    if (detail !== '') {
      msg = `${msg} (${detail})`;
    }
    error.value = msg;
  } finally {
    loading.value = false;
  }
}

async function copyJson() {
  if (!data.value) return;
  const text = JSON.stringify(data.value.capabilities, null, 2);
  try {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch {
    error.value = t('capabilitiesPage.clipboardUnavailable');
  }
}

onMounted(() => load(false));
</script>

<template>
  <div class="k-max-w-5xl k-mx-auto k-p-6 k-space-y-6">
    <header class="k-flex k-flex-wrap k-items-center k-justify-between k-gap-4">
      <div>
        <h1 class="k-text-2xl k-font-semibold k-text-knot-text">{{ t('capabilitiesPage.title') }}</h1>
        <p class="k-mt-1 k-text-sm k-text-knot-text-muted">
          {{ t('capabilitiesPage.subtitle') }}
        </p>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm hover:k-bg-knot-surface-soft"
          :disabled="loading || !data"
          @click="copyJson"
        >
          <ClipboardCopy class="k-h-4 k-w-4" />
          {{ copied ? t('capabilitiesPage.copied') : t('capabilitiesPage.copyJson') }}
        </button>
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm hover:k-bg-knot-surface-soft"
          :disabled="loading"
          @click="load(true)"
        >
          <RefreshCw class="k-h-4 k-w-4" :class="{ 'k-animate-spin': loading }" />
          {{ t('capabilitiesPage.refreshCache') }}
        </button>
      </div>
    </header>

    <div
      v-if="error"
      class="k-rounded-lg k-border k-border-knot-danger k-bg-knot-danger-soft k-px-4 k-py-3 k-text-sm k-text-knot-danger"
    >
      {{ error }}
    </div>

    <div v-if="loading && !data" class="k-flex k-items-center k-gap-2 k-text-knot-text-muted">
      <Loader2 class="k-h-5 k-w-5 k-animate-spin" />
      {{ t('actions.loading') }}
    </div>

    <template v-else-if="data">
      <p class="k-text-xs k-text-knot-text-muted">
        {{ t('capabilitiesPage.cachedLabel') }}
        {{ data.cached ? t('capabilitiesPage.cachedYes') : t('capabilitiesPage.cachedNo') }}
      </p>
      <section class="k-grid k-gap-4 md:k-grid-cols-2">
        <div class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-4">
          <h2 class="k-text-sm k-font-medium k-text-knot-text-muted">{{ t('capabilitiesPage.knotCard') }}</h2>
          <p class="k-mt-2 k-text-lg k-font-semibold">
            {{ (data.capabilities.knot as Record<string, string>)?.version ?? '—' }}
          </p>
        </div>
        <div class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-4">
          <h2 class="k-text-sm k-font-medium k-text-knot-text-muted">{{ t('capabilitiesPage.dolibarrCard') }}</h2>
          <p class="k-mt-2 k-text-lg k-font-semibold">
            {{ (data.capabilities.dolibarr as Record<string, string>)?.version ?? '—' }}
          </p>
          <p class="k-mt-1 k-text-xs k-text-knot-text-muted">
            {{ t('capabilitiesPage.modulesActive') }}
            {{ ((data.capabilities.dolibarr as Record<string, unknown>)?.modules_activated as string[])?.length ?? 0 }}
          </p>
        </div>
        <div class="k-grid k-gap-4 md:k-grid-cols-2 md:k-col-span-2">
          <div class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-4">
            <h2 class="k-text-sm k-font-medium k-text-knot-text-muted">
              {{ t('capabilitiesPage.supportedObjectsTitle') }}
            </h2>
            <p class="k-mt-2 k-text-2xl k-font-semibold">
              {{ data.capabilities.objects?.supported_count ?? '—' }}
            </p>
            <p class="k-mt-1 k-text-xs k-text-knot-text-muted">
              {{ t('capabilitiesPage.supportedObjectsHint') }}
            </p>
          </div>
          <div class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-4">
            <h2 class="k-text-sm k-font-medium k-text-knot-text-muted">
              {{ t('capabilitiesPage.descriptorFileTitle') }}
            </h2>
            <p class="k-mt-2 k-text-2xl k-font-semibold">
              {{ data.capabilities.objects?.descriptor_file_count ?? '—' }}
            </p>
            <p class="k-mt-1 k-text-xs k-text-knot-text-muted">
              {{ t('capabilitiesPage.descriptorFileHint') }}
            </p>
          </div>
        </div>
      </section>

      <div class="k-rounded-lg k-border k-border-knot-border k-bg-knot-bg k-p-4">
        <h2 class="k-mb-2 k-text-sm k-font-medium k-text-knot-text-muted">{{ t('capabilitiesPage.rawJson') }}</h2>
        <pre
          class="k-max-h-[480px] k-overflow-auto k-whitespace-pre-wrap k-break-all k-rounded k-bg-knot-surface k-p-3 k-text-xs k-leading-relaxed"
          >{{ JSON.stringify(data.capabilities, null, 2) }}</pre
        >
      </div>
    </template>
  </div>
</template>
