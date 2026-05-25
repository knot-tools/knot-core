<!--
  Product updates apply surface (Core + licensable extensions).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Loader2, RefreshCw } from 'lucide-vue-next';
import {
  knotApi,
  type MarketplaceResponse,
  type UpdatesApplyResult,
  type UpdatesCheckResponse,
} from '../lib/api';
import { useConfirm } from '../composables/useConfirm';
import { useToast } from '../composables/useToast';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';
import { productDisplayName } from '../lib/productDisplayName';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t } = useI18n();
const { confirm } = useConfirm();
const toast = useToast();

const snapshot = ref<UpdatesCheckResponse | null>(null);
const packsBySlug = ref<Record<string, string | null>>({});
const marketplaceLoadFailed = ref(false);

const loading = ref(false);
const applyingSlug = ref<string | null>(null);
const error = ref<string | null>(null);

const baseUrl = computed(() => {
  if (typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL) {
    return ((window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL ?? '').replace(/\/+$/, '');
  }
  return '';
});

function labelForSlug(slug: string): string {
  return productDisplayName(slug, t);
}

const marketplaceHref = computed(() => `${baseUrl.value}?mode=marketplace`);

function buyUrlForSlug(slug: string): string | null {
  const raw = packsBySlug.value[slug];
  return raw && /^https:\/\//i.test(raw) ? raw : null;
}

async function loadMarketplacePlaces() {
  marketplaceLoadFailed.value = false;
  if (!isMarketplaceUiEnabled()) {
    packsBySlug.value = {};
    return;
  }
  try {
    const mp: MarketplaceResponse = await knotApi.marketplace({});
    const map: Record<string, string | null> = {};
    for (const p of mp.packs ?? []) {
      if (typeof p.slug === 'string' && p.slug !== '') {
        map[p.slug] = typeof p.buyUrl === 'string' && p.buyUrl !== '' ? p.buyUrl : null;
      }
    }
    packsBySlug.value = map;
  } catch {
    marketplaceLoadFailed.value = true;
  }
}

async function load(forceRefresh = false) {
  loading.value = true;
  error.value = null;
  try {
    snapshot.value = await knotApi.updates({ force: forceRefresh });
    await loadMarketplacePlaces();
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err);
    snapshot.value = null;
  } finally {
    loading.value = false;
  }
}

async function handleApply(slug: string) {
  const confirmed = await confirm({
    title: t('updatesPage.confirmTitle'),
    message: t('updatesPage.confirmBody', { slug }),
    danger: true,
    confirmLabel: t('updatesPage.apply'),
  });
  if (!confirmed) {
    return;
  }
  applyingSlug.value = slug;
  try {
    const data = (await knotApi.updatesApply({ slug })) as UpdatesApplyResult;
    toast.success(t('updatesPage.applySuccess', { slug: data.slug ?? slug }));
    const migrationCount = Array.isArray(data.migrations) ? data.migrations.length : 0;
    if (migrationCount > 0) {
      toast.info(t('updatesPage.migrationsApplied', { count: migrationCount }));
    }
    await load(true);
  } catch (err) {
    const e = err as Error & {
      details?: { rollback?: string; instructions?: unknown; migrations?: unknown[] };
      error_code?: string;
      code?: string;
    };
    const code = e.error_code ?? e.code ?? '';
    if (code === 'release_signature_invalid') {
      toast.error(t('updatesPage.signatureInvalidTitle'), {
        body: t('updatesPage.signatureInvalidBody'),
      });
      return;
    }
    if (code === 'migration_failed') {
      const rollback = e.details?.rollback;
      if (rollback === 'restored') {
        toast.warning(t('updatesPage.migrationFailedRestored'), {
          body: e.message ?? String(err),
        });
      } else {
        toast.error(t('updatesPage.migrationFailedCritical'), {
          body: e.message ?? String(err),
        });
      }
      return;
    }
    const instr = e.details?.instructions;
    const extra =
      Array.isArray(instr) && instr.length > 0 ? ` — ${instr.join(' ')}` : '';
    toast.error(t('updatesPage.applyError'), {
      body: `${e.message ?? String(err)}${extra}`.trim(),
    });
  } finally {
    applyingSlug.value = null;
  }
}

onMounted(() => {
  const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
  const autoForce = params?.get('check') === '1' || params?.get('force') === '1';
  void load(autoForce);
});
</script>

<template>
  <div class="k-max-w-5xl k-mx-auto k-p-6 k-space-y-6">
    <header class="k-flex k-flex-wrap k-items-center k-justify-between k-gap-4">
      <div>
        <h1 class="k-text-2xl k-font-semibold k-text-knot-text">{{ t('updatesPage.heroTitle') }}</h1>
        <p class="k-mt-1 k-text-sm k-text-knot-text-muted">
          {{ t('updatesPage.heroSubtitle') }}
        </p>
      </div>
      <button
        type="button"
        class="k-inline-flex k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-2 k-text-sm hover:k-bg-knot-surface-soft"
        :disabled="loading"
        data-testid="updates-check-now"
        @click="load(true)"
      >
        <RefreshCw class="k-h-4 k-w-4" :class="{ 'k-animate-spin': loading }" />
        {{ t('updatesPage.checkNow') }}
      </button>
    </header>

    <p v-if="marketplaceLoadFailed" class="k-text-xs k-text-knot-text-muted">
      {{ t('updatesPage.loadMarketplaceHint') }}
      <a :href="marketplaceHref" class="k-text-knot-primary k-font-semibold hover:k-underline">{{
        t('updatesPage.marketplaceUnavailable')
      }}</a>
    </p>

    <div
      v-if="error"
      class="k-rounded-lg k-border k-border-knot-danger k-bg-knot-danger-soft k-px-4 k-py-3 k-text-sm k-text-knot-danger"
    >
      {{ error }}
    </div>

    <div v-if="loading && !snapshot" class="k-flex k-items-center k-gap-2 k-text-knot-text-muted">
      <Loader2 class="k-h-5 k-w-5 k-animate-spin" />
      {{ t('actions.loading') }}
    </div>

    <div
      v-else-if="snapshot && snapshot.entries.length === 0"
      class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-4 k-text-sm k-text-knot-text-muted"
    >
      {{ t('updatesPage.noUpdates') }}
    </div>

    <div v-else-if="snapshot" class="k-overflow-x-auto k-rounded-lg k-border k-border-knot-border">
      <table class="k-min-w-full k-divide-y k-divide-knot-border k-text-sm">
        <thead class="k-bg-knot-surface-soft">
          <tr>
            <th class="k-px-4 k-py-3 k-text-left k-font-semibold k-text-knot-text">
              {{ t('updatesPage.colProduct') }}
            </th>
            <th class="k-px-4 k-py-3 k-text-left k-font-semibold k-text-knot-text">
              {{ t('updatesPage.colInstalled') }}
            </th>
            <th class="k-px-4 k-py-3 k-text-left k-font-semibold k-text-knot-text">
              {{ t('updatesPage.colLatest') }}
            </th>
            <th class="k-px-4 k-py-3 k-text-left k-font-semibold k-text-knot-text">
              {{ t('updatesPage.colChannel') }}
            </th>
            <th class="k-px-4 k-py-3 k-text-left k-font-semibold k-text-knot-text">
              {{ t('updatesPage.colStatus') }}
            </th>
            <th class="k-px-4 k-py-3 k-text-right k-font-semibold k-text-knot-text">
              <!-- actions -->
            </th>
          </tr>
        </thead>
        <tbody class="k-divide-y k-divide-knot-border k-bg-knot-surface">
          <tr v-for="row in snapshot.entries" :key="row.slug">
            <td class="k-whitespace-nowrap k-px-4 k-py-3 k-text-sm k-font-medium k-text-knot-text">
              {{ labelForSlug(row.slug) }}
            </td>
            <td class="k-px-4 k-py-3">{{ row.installedVersion }}</td>
            <td class="k-px-4 k-py-3">{{ row.latestVersion ?? '—' }}</td>
            <td class="k-px-4 k-py-3">{{ row.channel ?? '—' }}</td>
            <td class="k-px-4 k-py-3">
              <span v-if="row.hasUpdate" class="k-font-semibold k-text-knot-warning">{{ row.source }}</span>
              <span v-else class="k-text-knot-text-muted">{{ row.source }}</span>
            </td>
            <td class="k-px-4 k-py-3 k-text-right k-space-x-2">
              <a
                v-if="buyUrlForSlug(row.slug)"
                class="k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
                :href="buyUrlForSlug(row.slug)!"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ t('updatesPage.buy') }}
              </a>
              <a
                v-else
                class="k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
                :href="marketplaceHref"
              >
                {{ t('updatesPage.buy') }}
              </a>
              <button
                type="button"
                class="k-inline-flex k-items-center k-gap-1 k-rounded-md k-bg-knot-primary k-px-3 k-py-1.5 k-text-xs k-font-semibold k-text-white disabled:k-opacity-50"
                :disabled="applyingSlug !== null || loading || !row.hasUpdate"
                @click="handleApply(row.slug)"
              >
                <Loader2 v-if="applyingSlug === row.slug" class="k-h-3.5 k-w-3.5 k-animate-spin" />
                {{ t('updatesPage.apply') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
