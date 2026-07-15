<!--
  Doctor view — diagnostic complet de l'installation Knot.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Stethoscope,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  RefreshCw,
  Loader2,
  Database,
  Clock,
  Lock,
  HardDrive,
  Cpu,
  Search,
} from 'lucide-vue-next';
import { knotApi, type HealthSnapshot } from '../lib/api';

const { t } = useI18n();

const snapshot = ref<HealthSnapshot | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    snapshot.value = await knotApi.health();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('doctorPage.loadFailed');
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const overall = computed(() => snapshot.value?.status ?? 'unknown');
const overallLabel = computed(() => {
  switch (overall.value) {
    case 'ok':
      return t('doctorPage.overallOk');
    case 'warning':
      return t('doctorPage.overallWarning');
    case 'error':
      return t('doctorPage.overallError');
    default:
      return t('doctorPage.overallUnknown');
  }
});
const overallClass = computed(() => {
  switch (overall.value) {
    case 'ok':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'warning':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'error':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-soft';
  }
});

interface CheckRow {
  key: string;
  label: string;
  ok: boolean;
  detail: string;
  severity: 'error' | 'warning';
  group: 'system' | 'install' | 'engine';
  icon: typeof Database;
}

const checkLabels = computed<Record<string, { label: string; group: CheckRow['group']; icon: typeof Database }>>(
  () => ({
    dolibarr: { label: t('doctorPage.check.dolibarr'), group: 'system', icon: Cpu },
    php: { label: t('doctorPage.check.php'), group: 'system', icon: Cpu },
    openssl: { label: t('doctorPage.check.openssl'), group: 'system', icon: Lock },
    curl: { label: t('doctorPage.check.curl'), group: 'system', icon: Cpu },
    json: { label: t('doctorPage.check.json'), group: 'system', icon: Cpu },
    mbstring: { label: t('doctorPage.check.mbstring'), group: 'system', icon: Cpu },
    module: { label: t('doctorPage.check.module'), group: 'install', icon: CheckCircle2 },
    tables: { label: t('doctorPage.check.tables'), group: 'install', icon: Database },
    rights: { label: t('doctorPage.check.rights'), group: 'install', icon: Lock },
    menus: { label: t('doctorPage.check.menus'), group: 'install', icon: CheckCircle2 },
    cron_registered: { label: t('doctorPage.check.cron_registered'), group: 'engine', icon: Clock },
    cron_enabled: { label: t('doctorPage.check.cron_enabled'), group: 'engine', icon: Clock },
    cron_global: { label: t('doctorPage.check.cron_global'), group: 'engine', icon: Clock },
    encryption: { label: t('doctorPage.check.encryption'), group: 'engine', icon: Lock },
    documents_writable: { label: t('doctorPage.check.documents_writable'), group: 'engine', icon: HardDrive },
    engine_enabled: { label: t('doctorPage.check.engine_enabled'), group: 'engine', icon: CheckCircle2 },
    setup_completed: { label: t('doctorPage.check.setup_completed'), group: 'install', icon: CheckCircle2 },
    introspection_cache: { label: t('doctorPage.check.introspection_cache'), group: 'engine', icon: Search },
  }),
);

const rows = computed<CheckRow[]>(() => {
  if (!snapshot.value?.doctor?.checks) return [];
  const checks = snapshot.value.doctor.checks;
  return Object.keys(checks).map((key) => {
    const meta = checkLabels.value[key] ?? { label: key, group: 'system' as const, icon: Cpu };
    return {
      key,
      label: meta.label,
      ok: checks[key].ok,
      detail: checks[key].detail,
      severity: checks[key].severity,
      group: meta.group,
      icon: meta.icon,
    };
  });
});

function rowsByGroup(group: CheckRow['group']) {
  return rows.value.filter((r) => r.group === group);
}

const cron = computed(() => snapshot.value?.doctor?.cron ?? null);

const setupUrl = computed(() => {
  const fromWindow = (window as unknown as { KNOT_SETUP_URL?: string }).KNOT_SETUP_URL;
  return fromWindow ?? '/custom/knot/admin/setup.php?admin=1';
});

const dolibarrModulesUrl = computed(() => {
  const win = window as unknown as { KNOT_BASE_URL?: string };
  const root = win.KNOT_BASE_URL?.split('/custom/')[0] ?? '';
  return `${root}/admin/modules.php`;
});

/** Public docs pointer — disable in Modules UI; purge only via docs (no purge UI). */
const uninstallDocsUrl = 'https://github.com/knot-tools/knot-core/blob/main/docs/uninstall.md';

function severityLabel(severity: 'error' | 'warning'): string {
  return severity === 'error'
    ? t('doctorPage.severityError')
    : t('doctorPage.severityWarning');
}

const checkGroups = computed(() => [
  { key: 'system' as const, title: t('doctorPage.groupSystem') },
  { key: 'install' as const, title: t('doctorPage.groupInstall') },
  { key: 'engine' as const, title: t('doctorPage.groupEngine') },
]);
</script>

<template>
  <div class="k-p-6 k-max-w-[1100px] k-mx-auto k-space-y-6">
    <header class="k-flex k-items-center k-justify-between k-gap-4">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <Stethoscope :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('nav.doctor') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">{{ t('doctorPage.subtitle') }}</p>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <span :class="['k-px-3 k-py-1 k-rounded-knot-pill k-text-xs k-font-bold k-uppercase k-tracking-wider', overallClass]">
          {{ overallLabel }}
        </span>
        <button
          @click="load"
          :disabled="loading"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-60"
        >
          <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
          <RefreshCw v-else :size="14" />
          {{ t('doctorPage.retest') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">{{ error }}</div>

    <section v-if="snapshot" class="k-grid k-gap-4 md:k-grid-cols-3">
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-text-xs k-font-semibold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('doctorPage.cardVersion') }}</div>
        <div class="k-text-2xl k-font-bold k-text-knot-text k-mt-1">{{ snapshot.version }}</div>
        <div class="k-text-xs k-text-knot-text-muted k-mt-1">{{ snapshot.module }}</div>
      </article>
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-text-xs k-font-semibold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('doctorPage.cardWorkflows') }}</div>
        <div class="k-flex k-flex-wrap k-gap-2 k-mt-1 k-text-sm">
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-success-soft k-text-knot-success k-font-semibold">{{ t('doctorPage.wfCountActive', { n: snapshot.workflows.active ?? 0 }) }}</span>
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-surface-soft k-text-knot-text-muted k-font-semibold">{{ t('doctorPage.wfCountDisabled', { n: snapshot.workflows.disabled ?? 0 }) }}</span>
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-surface-soft k-text-knot-text-muted k-font-semibold">{{ t('doctorPage.wfCountDraft', { n: snapshot.workflows.draft ?? 0 }) }}</span>
        </div>
      </article>
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <div class="k-text-xs k-font-semibold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('doctorPage.cardExecutions30d') }}</div>
        <div class="k-flex k-flex-wrap k-gap-2 k-mt-1 k-text-sm">
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-warning-soft k-text-knot-warning k-font-semibold">{{ t('doctorPage.execQueued', { n: snapshot.executions.queued ?? 0 }) }}</span>
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-success-soft k-text-knot-success k-font-semibold">{{ t('doctorPage.execSuccess', { n: snapshot.executions.success ?? 0 }) }}</span>
          <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-danger-soft k-text-knot-danger k-font-semibold">{{ t('doctorPage.execError', { n: snapshot.executions.error ?? 0 }) }}</span>
        </div>
      </article>
    </section>

    <section v-if="cron" class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-3">
      <header class="k-flex k-items-center k-gap-2">
        <Clock :size="18" class="k-text-knot-primary" />
        <h2 class="k-font-bold k-text-knot-text">{{ t('doctorPage.cronTitle') }}</h2>
      </header>
      <div class="k-grid k-gap-3 sm:k-grid-cols-2 k-text-sm">
        <div class="k-flex k-items-center k-gap-2">
          <component :is="cron.registered ? CheckCircle2 : XCircle" :size="14" :class="cron.registered ? 'k-text-knot-success' : 'k-text-knot-danger'" />
          <span class="k-text-knot-text-muted">{{ t('doctorPage.cronRegistered') }}</span>
        </div>
        <div class="k-flex k-items-center k-gap-2">
          <component :is="cron.enabled ? CheckCircle2 : AlertTriangle" :size="14" :class="cron.enabled ? 'k-text-knot-success' : 'k-text-knot-warning'" />
          <span class="k-text-knot-text-muted">{{ t('doctorPage.cronEnabled') }}</span>
        </div>
        <div class="k-flex k-items-center k-gap-2">
          <component :is="cron.globalEnabled ? CheckCircle2 : AlertTriangle" :size="14" :class="cron.globalEnabled ? 'k-text-knot-success' : 'k-text-knot-warning'" />
          <span class="k-text-knot-text-muted">{{ t('doctorPage.cronGlobalScheduler') }}</span>
        </div>
        <div class="k-text-knot-text-muted">
          <span class="k-font-semibold">{{ t('doctorPage.lastRun') }}</span>
          {{ cron.lastRun ?? t('doctorPage.never') }}
        </div>
      </div>
      <div v-if="!cron.enabled" class="k-text-xs k-text-knot-text-muted">
        <a :href="setupUrl" class="k-text-knot-primary hover:k-underline">{{ t('doctorPage.goToSetup') }}</a> {{ t('doctorPage.enableCronHint') }}
      </div>
    </section>

    <section
      v-if="snapshot?.doctor?.introspection"
      class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-2 k-text-sm"
    >
      <header class="k-flex k-items-center k-gap-2">
        <Search :size="18" class="k-text-knot-primary" />
        <h2 class="k-font-bold k-text-knot-text">{{ t('doctorPage.introspectionTitle') }}</h2>
      </header>
      <div class="k-text-knot-text-muted k-text-xs k-font-mono k-break-all">
        {{ snapshot.doctor.introspection.cachePath }}
      </div>
      <div class="k-flex k-flex-wrap k-gap-3 k-text-xs">
        <span :class="snapshot.doctor.introspection.cacheReadable ? 'k-text-knot-success' : 'k-text-knot-danger'">
          {{ t('doctorPage.cacheFileReadable') }} {{ snapshot.doctor.introspection.cacheReadable ? t('doctorPage.yes') : t('doctorPage.no') }}
        </span>
        <span class="k-text-knot-text-muted">
          {{ t('doctorPage.descriptorRows') }} {{ snapshot.doctor.introspection.descriptorCount }}
        </span>
        <span
          v-if="snapshot.doctor.introspection.supportedSlugCount != null"
          class="k-text-knot-text-muted"
        >
          {{ t('doctorPage.apiSlugsHint') }} {{ snapshot.doctor.introspection.supportedSlugCount }}
        </span>
      </div>
      <p v-if="!snapshot.doctor.introspection.cacheReadable || snapshot.doctor.introspection.descriptorCount < 1" class="k-text-xs k-text-knot-warning">
        {{ t('doctorPage.refreshCacheHint') }}
        <a :href="`${setupUrl.split('#')[0]}#knot-introspection`" class="k-underline k-font-semibold">{{ t('doctorPage.introspectionAnchor') }}</a>.
      </p>
    </section>

    <section class="k-space-y-4">
      <article
        v-for="group in checkGroups"
        :key="group.key"
        class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-3"
      >
        <h2 class="k-font-bold k-text-knot-text">{{ group.title }}</h2>
        <ul class="k-divide-y k-divide-knot-border">
          <li v-for="row in rowsByGroup(group.key as 'system' | 'install' | 'engine')" :key="row.key" class="k-py-2.5 k-flex k-items-start k-gap-3">
            <component
              :is="row.ok ? CheckCircle2 : (row.severity === 'error' ? XCircle : AlertTriangle)"
              :size="18"
              :class="row.ok ? 'k-text-knot-success' : (row.severity === 'error' ? 'k-text-knot-danger' : 'k-text-knot-warning')"
            />
            <div class="k-flex-1">
              <div class="k-flex k-items-center k-gap-2 k-text-sm k-font-semibold k-text-knot-text">
                {{ row.label }}
                <span
                  v-if="!row.ok"
                  class="k-text-[10px] k-font-bold k-uppercase k-tracking-wider k-px-1.5 k-py-0.5 k-rounded-knot-sm"
                  :class="row.severity === 'error' ? 'k-bg-knot-danger-soft k-text-knot-danger' : 'k-bg-knot-warning-soft k-text-knot-warning'"
                >
                  {{ severityLabel(row.severity) }}
                </span>
              </div>
              <div class="k-text-xs k-text-knot-text-muted k-mt-0.5">{{ row.detail }}</div>
            </div>
          </li>
        </ul>
      </article>
    </section>

    <section v-if="snapshot?.doctor?.tablesMissing?.length" class="k-bg-knot-warning-soft k-border k-border-knot-warning k-rounded-knot-md k-p-4 k-text-sm k-text-knot-warning">
      <div class="k-font-semibold">{{ t('doctorPage.missingTables') }}</div>
      <ul class="k-mt-1 k-list-disc k-list-inside k-font-mono k-text-xs">
        <li v-for="tbl in snapshot.doctor.tablesMissing" :key="tbl">llx_knot_{{ tbl }}</li>
      </ul>
      <p class="k-mt-2 k-text-xs">
        {{ t('doctorPage.missingTablesHintBefore') }}
        <a :href="setupUrl" class="k-underline k-font-semibold">{{ t('doctorPage.setupLinkLabel') }}</a>
        {{ t('doctorPage.missingTablesHintAfter') }}
      </p>
    </section>

    <section
      class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-3"
      data-testid="doctor-uninstall-section"
    >
      <h2 class="k-font-bold k-text-knot-text k-m-0">{{ t('doctorPage.uninstallTitle') }}</h2>
      <p class="k-text-sm k-text-knot-text-muted k-m-0 k-leading-relaxed">
        {{ t('doctorPage.uninstallBody') }}
      </p>
      <div class="k-flex k-flex-wrap k-items-center k-gap-3 k-text-sm">
        <a
          :href="dolibarrModulesUrl"
          class="k-inline-flex k-items-center k-gap-1.5 k-font-semibold k-text-knot-primary k-no-underline hover:k-underline"
          data-testid="doctor-disable-module-link"
        >
          {{ t('doctorPage.disableModuleLink') }}
        </a>
        <a
          :href="uninstallDocsUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="k-inline-flex k-items-center k-gap-1.5 k-font-medium k-text-knot-text-muted k-no-underline hover:k-text-knot-primary hover:k-underline"
          data-testid="doctor-uninstall-docs-link"
        >
          {{ t('doctorPage.uninstallDocsLink') }}
        </a>
      </div>
    </section>
  </div>
</template>
