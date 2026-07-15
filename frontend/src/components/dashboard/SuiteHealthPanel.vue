<!--
  Suite Health Panel — installed product versions, license, cron, jobs.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Package,
  Clock,
  Activity,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Loader2,
} from 'lucide-vue-next';
import {
  knotApi,
  type ExtensionLicenseStatus,
  type HealthSnapshot,
  type UpdatesCheckResponse,
} from '../../lib/api';
import { productDisplayName } from '../../lib/productDisplayName';

const { t } = useI18n();

const health = ref<HealthSnapshot | null>(null);
const extensions = ref<ExtensionLicenseStatus[]>([]);
const updates = ref<UpdatesCheckResponse | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

interface ProductRow {
  slug: string;
  label: string;
  version: string | null;
  publishedVersion: string | null;
  hasUpdate: boolean;
  licenseStatus: string | null;
  licenseExpiresAt: string | null;
}

function publishedFor(slug: string): { latest: string | null; hasUpdate: boolean } {
  const entry = updates.value?.entries?.find((e) => e.slug === slug || e.slug === slug.replace(/^knot-/, ''));
  // Also match common product slugs from updates.php (knot / knot-pro-pack / knot-migration).
  const alt = updates.value?.entries?.find((e) => {
    const id = e.slug;
    return (
      id === slug
      || (slug === 'knot' && (id === 'knot' || id === 'core'))
      || (slug.includes('pro') && id.includes('pro'))
      || (slug.includes('migration') && id.includes('migration'))
    );
  });
  const hit = entry ?? alt ?? null;
  return {
    latest: hit?.latestVersion ?? null,
    hasUpdate: Boolean(hit?.hasUpdate),
  };
}

const products = computed((): ProductRow[] => {
  const rows: ProductRow[] = [];
  if (health.value) {
    const pub = publishedFor('knot');
    rows.push({
      slug: 'knot',
      label: productDisplayName('knot', t),
      version: health.value.version ?? null,
      publishedVersion: pub.latest,
      hasUpdate: pub.hasUpdate,
      licenseStatus: 'gpl',
      licenseExpiresAt: null,
    });
  }
  for (const ext of extensions.value) {
    const pub = publishedFor(ext.id);
    rows.push({
      slug: ext.id,
      label: ext.label ?? productDisplayName(ext.id, t),
      version: ext.version ?? null,
      publishedVersion: pub.latest,
      hasUpdate: pub.hasUpdate,
      licenseStatus: ext.licenseStatus,
      licenseExpiresAt: ext.licenseExpiresAt,
    });
  }
  return rows;
});

const cronState = computed(() => {
  const cron = health.value?.doctor?.cron;
  if (!cron) return null;
  return {
    registered: cron.registered,
    enabled: cron.enabled,
    globalEnabled: cron.globalEnabled,
    lastRun: cron.lastRun,
    nextRun: cron.nextRun,
  };
});

const jobsSummary = computed(() => {
  const exec = health.value?.executions ?? {};
  return {
    success: (exec.success ?? exec.completed ?? 0) as number,
    failed: (exec.failed ?? exec.error ?? 0) as number,
    queued: (exec.queued ?? 0) as number,
    running: (exec.running ?? 0) as number,
  };
});

function licenseChipClass(status: string | null): string {
  if (status === 'valid' || status === 'gpl') return 'k-bg-knot-success-soft k-text-knot-success';
  if (status === 'expired') return 'k-bg-knot-warning-soft k-text-knot-warning';
  return 'k-bg-knot-danger-soft k-text-knot-danger';
}

function licenseLabel(status: string | null): string {
  if (status === 'gpl') return 'GPL-3.0';
  if (status === 'valid') return t('suiteHealth.licenseValid');
  if (status === 'expired') return t('suiteHealth.licenseExpired');
  if (status === 'invalid') return t('suiteHealth.licenseInvalid');
  return t('suiteHealth.licenseUnknown');
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [h, lic, upd] = await Promise.all([
      knotApi.health(),
      knotApi.licenseStatus(),
      knotApi.updates().catch(() => null),
    ]);
    health.value = h;
    extensions.value = lic.extensions ?? [];
    updates.value = upd;
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5">
    <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-4">
      <Package :size="16" class="k-text-knot-primary" />
      {{ t('suiteHealth.title') }}
    </h2>

    <div v-if="loading" class="k-flex k-items-center k-gap-2 k-text-sm k-text-knot-text-muted">
      <Loader2 :size="14" class="k-animate-spin" />
      {{ t('actions.loading') }}
    </div>

    <div v-else-if="error" class="k-flex k-flex-col k-gap-2 k-text-sm">
      <p class="k-text-knot-danger">{{ error }}</p>
      <button
        type="button"
        class="k-self-start k-px-2.5 k-py-1 k-rounded-knot-sm k-border k-border-knot-border k-text-xs k-font-semibold k-text-knot-text hover:k-bg-knot-surface-soft"
        @click="load"
      >
        {{ t('actions.retry') }}
      </button>
    </div>

    <template v-else>
      <!-- Product versions & licences -->
      <div class="k-space-y-2 k-mb-4">
        <div
          v-for="row in products"
          :key="row.slug"
          class="k-flex k-items-center k-justify-between k-gap-2 k-text-sm"
        >
          <span class="k-font-medium k-text-knot-text">{{ row.label }}</span>
          <div class="k-flex k-items-center k-gap-2 k-flex-wrap k-justify-end">
            <span class="k-font-mono k-text-xs k-text-knot-text-muted" :title="t('suiteHealth.installedVersion')">
              {{ row.version ?? '—' }}
            </span>
            <span
              v-if="row.publishedVersion"
              class="k-font-mono k-text-[10px]"
              :class="row.hasUpdate ? 'k-text-knot-warning' : 'k-text-knot-text-soft'"
              :title="t('suiteHealth.publishedVersion')"
            >
              {{ t('suiteHealth.publishedShort', { version: row.publishedVersion }) }}
            </span>
            <span
              v-if="row.hasUpdate"
              class="k-px-1.5 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold k-bg-knot-warning-soft k-text-knot-warning"
            >
              {{ t('suiteHealth.updateAvailable') }}
            </span>
            <span
              :class="[
                'k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold',
                licenseChipClass(row.licenseStatus),
              ]"
            >
              {{ licenseLabel(row.licenseStatus) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Cron state -->
      <div v-if="cronState" class="k-border-t k-border-knot-border k-pt-3 k-mb-3">
        <h3 class="k-text-xs k-font-semibold k-text-knot-text-soft k-uppercase k-tracking-wider k-mb-2 k-flex k-items-center k-gap-1.5">
          <Clock :size="12" />
          {{ t('suiteHealth.cronTitle') }}
        </h3>
        <div class="k-grid k-grid-cols-2 k-gap-x-4 k-gap-y-1 k-text-xs">
          <span class="k-text-knot-text-muted">{{ t('suiteHealth.cronRegistered') }}</span>
          <span class="k-flex k-items-center k-gap-1">
            <CheckCircle2 v-if="cronState.registered" :size="11" class="k-text-knot-success" />
            <XCircle v-else :size="11" class="k-text-knot-danger" />
            {{ cronState.registered ? t('suiteHealth.yes') : t('suiteHealth.no') }}
          </span>
          <span class="k-text-knot-text-muted">{{ t('suiteHealth.cronEnabled') }}</span>
          <span class="k-flex k-items-center k-gap-1">
            <CheckCircle2 v-if="cronState.enabled" :size="11" class="k-text-knot-success" />
            <AlertTriangle v-else :size="11" class="k-text-knot-warning" />
            {{ cronState.enabled ? t('suiteHealth.yes') : t('suiteHealth.no') }}
          </span>
          <span class="k-text-knot-text-muted">{{ t('suiteHealth.cronLastRun') }}</span>
          <span class="k-text-knot-text">{{ cronState.lastRun ?? '—' }}</span>
        </div>
      </div>

      <!-- Jobs summary -->
      <div class="k-border-t k-border-knot-border k-pt-3">
        <h3 class="k-text-xs k-font-semibold k-text-knot-text-soft k-uppercase k-tracking-wider k-mb-2 k-flex k-items-center k-gap-1.5">
          <Activity :size="12" />
          {{ t('suiteHealth.jobsTitle') }}
        </h3>
        <div class="k-grid k-grid-cols-4 k-gap-2 k-text-center">
          <div>
            <div class="k-text-lg k-font-bold k-text-knot-success">{{ jobsSummary.success }}</div>
            <div class="k-text-[10px] k-text-knot-text-muted">{{ t('suiteHealth.jobsSuccess') }}</div>
          </div>
          <div>
            <div class="k-text-lg k-font-bold k-text-knot-warning">{{ jobsSummary.failed }}</div>
            <div class="k-text-[10px] k-text-knot-text-muted">{{ t('suiteHealth.jobsFailed') }}</div>
          </div>
          <div>
            <div class="k-text-lg k-font-bold k-text-knot-accent">{{ jobsSummary.queued }}</div>
            <div class="k-text-[10px] k-text-knot-text-muted">{{ t('suiteHealth.jobsQueued') }}</div>
          </div>
          <div>
            <div class="k-text-lg k-font-bold k-text-knot-primary">{{ jobsSummary.running }}</div>
            <div class="k-text-[10px] k-text-knot-text-muted">{{ t('suiteHealth.jobsRunning') }}</div>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
