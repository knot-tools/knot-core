<!--
  Product updates apply surface (Core + licensable extensions).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, Loader2, RefreshCw, ShieldCheck, Sparkles } from 'lucide-vue-next';
import {
  knotApi,
  type MarketplaceResponse,
  type UpdatesApplyResult,
  type UpdatesCheckEntry,
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

const pendingCount = computed(
  () => (snapshot.value?.entries ?? []).filter((e) => e.hasUpdate).length,
);

const allUpToDate = computed(
  () =>
    snapshot.value !== null
    && snapshot.value.entries.length > 0
    && pendingCount.value === 0,
);

function buyUrlForSlug(slug: string): string | null {
  if (slug === 'knot') {
    return null;
  }
  const raw = packsBySlug.value[slug];
  return raw && /^https:\/\//i.test(raw) ? raw : null;
}

function showBuyLink(slug: string): boolean {
  return slug !== 'knot' && isMarketplaceUiEnabled();
}

function isAheadOfPublished(row: UpdatesCheckEntry): boolean {
  if (!row.latestVersion || row.hasUpdate || row.error) {
    return false;
  }
  // Loose semver compare aligned with PHP version_compare for x.y.z.
  const parts = (v: string) =>
    v
      .split(/[.+-]/)
      .map((p) => Number.parseInt(p, 10))
      .map((n) => (Number.isFinite(n) ? n : 0));
  const a = parts(row.installedVersion);
  const b = parts(row.latestVersion);
  const len = Math.max(a.length, b.length);
  for (let i = 0; i < len; i += 1) {
    const left = a[i] ?? 0;
    const right = b[i] ?? 0;
    if (left > right) {
      return true;
    }
    if (left < right) {
      return false;
    }
  }
  return false;
}

function statusLabel(row: UpdatesCheckEntry): string {
  if (row.error) {
    return t('updatesPage.statusCheckFailed');
  }
  if (row.hasUpdate) {
    return t('updatesPage.statusUpdateAvailable');
  }
  if (isAheadOfPublished(row)) {
    return t('updatesPage.statusAhead');
  }
  return t('updatesPage.statusUpToDate');
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
  const entry = snapshot.value?.entries.find((e) => e.slug === slug);
  const product = labelForSlug(slug);
  const confirmed = await confirm({
    title: t('updatesPage.confirmTitle'),
    message: t('updatesPage.confirmBody', {
      product,
      version: entry?.latestVersion ?? '—',
    }),
    danger: true,
    confirmLabel: t('updatesPage.apply'),
  });
  if (!confirmed) {
    return;
  }
  applyingSlug.value = slug;
  try {
    const applyPayload: { slug: string; version?: string; channel?: string } = { slug };
    if (entry?.latestVersion) {
      applyPayload.version = entry.latestVersion;
    }
    if (entry?.channel) {
      applyPayload.channel = entry.channel;
    }
    const data = (await knotApi.updatesApply(applyPayload)) as UpdatesApplyResult;
    toast.success(t('updatesPage.applySuccess', { slug: labelForSlug(data.slug ?? slug) }));
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
    if (code === 'activation_code_missing') {
      toast.error(t('updatesPage.activationMissingTitle'), {
        body: t('updatesPage.activationMissingBody'),
      });
      return;
    }
    if (code === 'license_download_token_denied') {
      toast.error(t('updatesPage.downloadDeniedTitle'), {
        body: `${t('updatesPage.downloadDeniedBody')} ${e.message ?? ''}`.trim(),
      });
      return;
    }
    if (code === 'backend_unreachable') {
      toast.error(t('updatesPage.backendUnreachableTitle'), {
        body: t('updatesPage.backendUnreachableBody'),
      });
      return;
    }
    if (code === 'license_invalid') {
      toast.error(t('updatesPage.licenseInvalidTitle'), {
        body: t('updatesPage.licenseInvalidBody'),
      });
      return;
    }
    if (code === 'release_version_mismatch') {
      toast.error(t('updatesPage.versionMismatchTitle'), {
        body: t('updatesPage.versionMismatchBody'),
      });
      return;
    }
    if (code === 'extension_unknown') {
      toast.error(t('updatesPage.extensionUnknownTitle'), {
        body: t('updatesPage.extensionUnknownBody'),
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
  <div class="k-max-w-5xl k-mx-auto k-p-6 k-space-y-6" data-testid="updates-page">
    <header class="k-flex k-flex-wrap k-items-start k-justify-between k-gap-4">
      <div class="k-max-w-2xl">
        <p
          class="k-inline-flex k-items-center k-gap-1.5 k-text-xs k-font-semibold k-uppercase k-tracking-wider k-text-knot-primary"
        >
          <Sparkles class="k-h-3.5 k-w-3.5" aria-hidden="true" />
          {{ t('updatesPage.heroPill') }}
        </p>
        <h1 class="k-mt-2 k-text-2xl k-font-semibold k-text-knot-text">
          {{ t('updatesPage.heroTitle') }}
        </h1>
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

    <div
      class="k-flex k-items-start k-gap-3 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface-soft k-px-4 k-py-3 k-text-sm k-text-knot-text-muted"
      data-testid="updates-trust-banner"
    >
      <ShieldCheck class="k-mt-0.5 k-h-5 k-w-5 k-shrink-0 k-text-knot-primary" aria-hidden="true" />
      <p>{{ t('updatesPage.trustBanner') }}</p>
    </div>

    <p
      v-if="pendingCount > 0"
      class="k-rounded-lg k-border k-border-knot-warning/40 k-bg-knot-warning-soft k-px-4 k-py-3 k-text-sm k-text-knot-warning"
      data-testid="updates-pending-summary"
    >
      {{ t('updatesPage.pendingSummary', { count: pendingCount }) }}
    </p>

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
      class="k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-p-6 k-text-center k-text-sm k-text-knot-text-muted"
    >
      {{ t('updatesPage.noUpdates') }}
    </div>

    <div
      v-else-if="allUpToDate"
      class="k-flex k-flex-col k-items-center k-gap-2 k-rounded-lg k-border k-border-knot-border k-bg-knot-surface k-px-6 k-py-8 k-text-center"
      data-testid="updates-all-current"
    >
      <CheckCircle2 class="k-h-8 k-w-8 k-text-knot-success" aria-hidden="true" />
      <p class="k-text-base k-font-semibold k-text-knot-text">{{ t('updatesPage.allUpToDateTitle') }}</p>
      <p class="k-max-w-md k-text-sm k-text-knot-text-muted">{{ t('updatesPage.allUpToDateBody') }}</p>
    </div>

    <ul
      v-if="snapshot && snapshot.entries.length > 0"
      class="k-grid k-grid-cols-1 k-gap-4 md:k-grid-cols-2"
      data-testid="updates-product-list"
    >
      <li
        v-for="row in snapshot.entries"
        :key="row.slug"
        class="k-flex k-flex-col k-rounded-xl k-border k-bg-knot-surface k-p-5"
        :class="
          row.hasUpdate
            ? 'k-border-knot-warning/50 k-shadow-sm'
            : 'k-border-knot-border'
        "
        :data-testid="`updates-card-${row.slug}`"
        :data-has-update="row.hasUpdate ? '1' : '0'"
      >
        <div class="k-flex k-items-start k-justify-between k-gap-3">
          <div>
            <h2 class="k-text-base k-font-semibold k-text-knot-text">
              {{ labelForSlug(row.slug) }}
            </h2>
            <p class="k-mt-1 k-text-xs k-text-knot-text-muted">
              {{ t('updatesPage.channelLabel', { channel: row.channel ?? '—' }) }}
            </p>
          </div>
          <span
            class="k-shrink-0 k-rounded-full k-px-2.5 k-py-1 k-text-[11px] k-font-semibold"
            :class="
              row.hasUpdate
                ? 'k-bg-knot-warning-soft k-text-knot-warning'
                : row.error
                  ? 'k-bg-knot-danger-soft k-text-knot-danger'
                  : isAheadOfPublished(row)
                    ? 'k-bg-knot-surface-soft k-text-knot-primary'
                    : 'k-bg-knot-success-soft k-text-knot-success'
            "
            data-testid="updates-status-badge"
          >
            {{ statusLabel(row) }}
          </span>
        </div>

        <dl class="k-mt-4 k-grid k-grid-cols-2 k-gap-3 k-text-sm">
          <div>
            <dt class="k-text-xs k-text-knot-text-muted">{{ t('updatesPage.colInstalled') }}</dt>
            <dd
              class="k-mt-0.5 k-font-mono k-text-knot-text"
              data-testid="updates-installed-version"
            >
              {{ row.installedVersion }}
            </dd>
          </div>
          <div>
            <dt class="k-text-xs k-text-knot-text-muted">{{ t('updatesPage.colLatest') }}</dt>
            <dd
              class="k-mt-0.5 k-font-mono k-text-knot-text"
              data-testid="updates-latest-version"
            >
              {{ row.latestVersion ?? '—' }}
            </dd>
          </div>
        </dl>

        <p v-if="row.error" class="k-mt-3 k-text-xs k-text-knot-danger">
          {{ row.error }}
        </p>

        <div class="k-mt-auto k-flex k-flex-wrap k-items-center k-justify-end k-gap-2 k-pt-5">
          <a
            v-if="showBuyLink(row.slug) && buyUrlForSlug(row.slug)"
            class="k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
            :href="buyUrlForSlug(row.slug)!"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ t('updatesPage.buy') }}
          </a>
          <a
            v-else-if="showBuyLink(row.slug)"
            class="k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
            :href="marketplaceHref"
          >
            {{ t('updatesPage.buy') }}
          </a>
          <button
            type="button"
            class="k-inline-flex k-items-center k-gap-1.5 k-rounded-md k-px-3 k-py-1.5 k-text-xs k-font-semibold disabled:k-opacity-50"
            :class="
              row.hasUpdate
                ? 'k-bg-knot-primary k-text-white'
                : 'k-border k-border-knot-border k-bg-knot-surface-soft k-text-knot-text-muted'
            "
            :disabled="applyingSlug !== null || loading || !row.hasUpdate"
            :data-testid="`updates-apply-${row.slug}`"
            @click="handleApply(row.slug)"
          >
            <Loader2 v-if="applyingSlug === row.slug" class="k-h-3.5 k-w-3.5 k-animate-spin" />
            {{ row.hasUpdate ? t('updatesPage.apply') : t('updatesPage.applyDisabled') }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
