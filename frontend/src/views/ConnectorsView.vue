<!--
  Connectors catalog view — interactive doc + constraints per connector.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, Plug, Loader2, KeyRound, Info, ArrowDownUp, AlertTriangle, ShoppingBag, Lock } from 'lucide-vue-next';
import { type ConnectorDescriptor, type ExtensionSummary } from '../lib/api';
import { getConnectorsCatalogCached } from '../lib/connectorDescriptorsCache';
import { resolveNodeMeta } from '../canvas/nodeRegistry';
import { connectorMessageKey, resolveConnectorLabel } from '../lib/connectorLabels';
import LicenseActivationModal from '../components/licensing/LicenseActivationModal.vue';
import LicenseDeactivateModal from '../components/licensing/LicenseDeactivateModal.vue';
import KProBadge from '../components/ui/KProBadge.vue';
import ConnectorBuilderView from './ConnectorBuilderView.vue';

const { t } = useI18n();

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const mainTab = ref<'catalog' | 'builder'>('catalog');

function syncTabToUrl(tab: 'catalog' | 'builder') {
  if (typeof window === 'undefined' || !window.history?.replaceState) return;
  const url = new URL(window.location.href);
  if (tab === 'builder') {
    url.searchParams.set('tab', 'builder');
  } else {
    url.searchParams.delete('tab');
  }
  window.history.replaceState({}, '', url.toString());
}

function setMainTab(tab: 'catalog' | 'builder') {
  mainTab.value = tab;
  syncTabToUrl(tab);
}

const connectors = ref<ConnectorDescriptor[]>([]);
const palette = ref<Array<{ category: string; title: string; ids: string[] }>>([]);
const extensions = ref<ExtensionSummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');
const selectedCategory = ref<string>('all');
const selectedSource = ref<'all' | 'core' | 'pro' | 'enterprise' | 'third-party' | 'unavailable'>('all');
const selectedId = ref<string | null>(null);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await getConnectorsCatalogCached();
    connectors.value = result.connectors;
    palette.value = result.palette;
    extensions.value = result.extensions ?? [];
    if (!selectedId.value && connectors.value.length > 0) {
      selectedId.value = String(connectors.value[0].metadata.id);
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to load connectors.';
  } finally {
    loading.value = false;
  }
}

const categories = computed(() => [
  { id: 'all', label: 'All', count: connectors.value.length },
  ...palette.value.map((s) => ({ id: s.category, label: s.title, count: s.ids.length })),
]);

function sourceTag(c: ConnectorDescriptor): 'core' | 'pro' | 'enterprise' | 'third-party' {
  if (!c.source || c.source === 'core') return 'core';
  const cat = c.extensionInfo?.category ?? 'third-party';
  if (cat === 'pro' || cat === 'premium') return 'pro';
  if (cat === 'enterprise') return 'enterprise';
  return 'third-party';
}

type SourceFilterId = 'core' | 'pro' | 'enterprise' | 'third-party' | 'unavailable';

const sourceFilters = computed(() => {
  const counts: Record<string, number> = {
    core: 0,
    pro: 0,
    enterprise: 0,
    'third-party': 0,
    unavailable: 0,
  };
  connectors.value.forEach((c) => {
    if (c.available === false) counts.unavailable += 1;
    else counts[sourceTag(c)] += 1;
  });
  return [
    { id: 'core' as const, label: 'Core', count: counts.core },
    { id: 'pro' as const, label: 'Pro', count: counts.pro },
    { id: 'enterprise' as const, label: 'Enterprise', count: counts.enterprise },
    { id: 'third-party' as const, label: 'Third-party', count: counts['third-party'] },
    { id: 'unavailable' as const, label: 'Unavailable', count: counts.unavailable },
  ].filter((s) => s.count > 0);
});

/** Toggle source chip: second click clears filter (same as former “All sources”). */
function selectSourceFilter(id: SourceFilterId) {
  selectedSource.value = selectedSource.value === id ? 'all' : id;
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return connectors.value.filter((c) => {
    if (selectedCategory.value !== 'all' && c.metadata.category !== selectedCategory.value) return false;
    if (selectedSource.value !== 'all') {
      if (selectedSource.value === 'unavailable') {
        if (c.available !== false) return false;
      } else if (sourceTag(c) !== selectedSource.value || c.available === false) {
        return false;
      }
    }
    if (!q) return true;
    return (
      String(c.metadata.id).toLowerCase().includes(q) ||
      catalogTitle(c.metadata).toLowerCase().includes(q) ||
      catalogDescription(c.metadata).toLowerCase().includes(q)
    );
  });
});

function reconcileSelectionToFiltered(list: ConnectorDescriptor[]) {
  const ids = new Set(list.map((c) => String(c.metadata.id)));
  if (selectedId.value !== null && ids.has(selectedId.value)) {
    return;
  }
  selectedId.value = list.length > 0 ? String(list[0].metadata.id) : null;
}

watch(
  () => filtered.value,
  (list) => reconcileSelectionToFiltered(list),
  { flush: 'post' },
);

function sourceBadgeClass(tag: 'core' | 'pro' | 'enterprise' | 'third-party'): string {
  switch (tag) {
    case 'core':
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
    case 'pro':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'enterprise':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    case 'third-party':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
  }
}

function licenseBadgeClass(status: string | null | undefined): string {
  if (!status) return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  if (status === 'valid' || status === 'not_required') return 'k-bg-knot-success-soft k-text-knot-success';
  return 'k-bg-knot-danger-soft k-text-knot-danger';
}

const selected = computed(() => connectors.value.find((c) => String(c.metadata.id) === selectedId.value) ?? null);

function meta(connector: ConnectorDescriptor) {
  return resolveNodeMeta(String(connector.metadata.id));
}

function catalogTitle(m: ConnectorDescriptor['metadata']): string {
  const lk = m.labelKey;
  if (lk) {
    return resolveConnectorLabel(lk, String(m.label ?? resolveNodeMeta(String(m.id)).label));
  }
  return String(m.label ?? resolveNodeMeta(String(m.id)).label);
}

function catalogDescription(m: ConnectorDescriptor['metadata']): string {
  const id = String(m.id ?? '');
  const dk = m.descriptionKey ? String(m.descriptionKey) : connectorMessageKey(id, 'description');
  return resolveConnectorLabel(dk, String(m.description ?? resolveNodeMeta(id).description));
}

function categoryBadge(category: string) {
  switch (category) {
    case 'trigger': return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'logic': return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'communication': return 'k-bg-knot-success-soft k-text-knot-success';
    case 'ai': return 'k-bg-knot-accent-soft k-text-knot-accent';
    case 'dolibarr': return 'k-bg-knot-danger-soft k-text-knot-danger';
    default: return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}

function fieldEntries(schema: Record<string, unknown> | null | undefined) {
  if (!schema) return [];
  const requiredList = Array.isArray(schema.required) ? (schema.required as string[]) : [];
  const properties = schema.properties as Record<string, Record<string, unknown>> | undefined;
  if (properties && typeof properties === 'object') {
    return Object.entries(properties).map(([name, prop]) => ({
      name,
      key: name,
      type: prop.type ?? 'string',
      required: requiredList.includes(name),
      description: prop.description ?? prop.title ?? '',
      descriptionKey: prop.descriptionKey ?? prop.titleKey ?? '',
      help: prop.description ?? '',
    }));
  }
  const fields = (schema.fields as Array<Record<string, unknown>>) ?? [];
  return Array.isArray(fields) ? fields : [];
}

function connectorUsageExample(c: ConnectorDescriptor): string {
  const id = String(c.metadata.id ?? '');
  const key = connectorMessageKey(id, 'usageExample');
  const resolved = resolveConnectorLabel(key, '');
  return resolved === key ? '' : resolved;
}

function fieldType(field: Record<string, unknown>): string {
  return String(field.type ?? 'string');
}

function fieldRequired(field: Record<string, unknown>): boolean {
  return !!field.required;
}

function credentialFieldEntries(schema: ConnectorDescriptor['credentialSchema']) {
  if (!schema?.properties) return [];
  const requiredList = Array.isArray(schema.required) ? schema.required : [];
  return Object.entries(schema.properties).map(([name, prop]) => ({
    name,
    key: name,
    type: prop.type ?? 'string',
    required: requiredList.includes(name),
    secret: !!prop.secret,
    description: prop.description ?? prop.title ?? '',
  }));
}

const expressionExample = '{{$json.field}}';

const activationTarget = ref<{ id: string; label: string } | null>(null);
const deactivationTarget = ref<{ id: string; label: string } | null>(null);

function openActivation(c: ConnectorDescriptor) {
  const extId = c.extensionInfo?.id;
  if (!extId) return;
  activationTarget.value = {
    id: String(extId),
    label: c.extensionInfo?.label ?? String(extId),
  };
}

function isActivatable(c: ConnectorDescriptor): boolean {
  // We only show the CTA for paid extensions whose licence is missing
  // or expired. The Core/free extensions never need activation, and
  // a valid licence already grants access.
  if (!c.extensionInfo?.id) return false;
  const cat = c.extensionInfo.category;
  if (cat !== 'pro' && cat !== 'premium' && cat !== 'enterprise') return false;
  const status = c.extensionInfo.license_status;
  return status !== 'valid' && status !== 'not_required';
}

function isDeactivatable(c: ConnectorDescriptor): boolean {
  if (!c.extensionInfo?.id) return false;
  const cat = c.extensionInfo.category;
  if (cat !== 'pro' && cat !== 'premium' && cat !== 'enterprise') return false;
  const status = c.extensionInfo.license_status;
  return status === 'valid' || status === 'grace';
}

function openDeactivation(c: ConnectorDescriptor) {
  const extId = c.extensionInfo?.id;
  if (!extId) return;
  deactivationTarget.value = {
    id: String(extId),
    label: c.extensionInfo?.label ?? String(extId),
  };
}

function onActivated() {
  activationTarget.value = null;
  load();
}

function onDeactivated() {
  deactivationTarget.value = null;
  load();
}

function showProBadge(c: ConnectorDescriptor): boolean {
  const tag = sourceTag(c);
  return tag === 'pro' || tag === 'enterprise';
}

function proBadgeLabel(c: ConnectorDescriptor): string {
  return sourceTag(c) === 'enterprise'
    ? t('marketplace.enterpriseBadgeLabel')
    : t('marketplace.proBadgeLabel');
}

onMounted(() => {
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'builder') {
    mainTab.value = 'builder';
  }
  load();
});
</script>

<template>
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-accent-soft k-text-knot-accent k-flex k-items-center k-justify-center">
          <Plug :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('connectorsPage.title') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">
            {{ t('connectorsPage.subtitle', { count: connectors.length }) }}
          </p>
        </div>
      </div>
    </header>

    <nav class="k-flex k-gap-1 k-border-b k-border-knot-border">
      <button
        type="button"
        class="k-px-4 k-py-2 k-text-sm k-font-semibold k-border-b-2 k-transition-colors"
        :class="
          mainTab === 'catalog'
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'
        "
        @click="setMainTab('catalog')"
      >
        {{ t('connectorsPage.tabCatalog') }}
      </button>
      <button
        type="button"
        class="k-px-4 k-py-2 k-text-sm k-font-semibold k-border-b-2 k-transition-colors"
        :class="
          mainTab === 'builder'
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'
        "
        @click="setMainTab('builder')"
      >
        {{ t('connectorsPage.tabBuilder') }}
      </button>
    </nav>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="k-flex k-items-center k-gap-2 k-text-knot-text-muted k-text-sm k-py-12 k-justify-center">
      <Loader2 :size="16" class="k-animate-spin" /> {{ t('connectorsPage.loading') }}
    </div>

    <template v-else>
      <ConnectorBuilderView v-show="mainTab === 'builder'" />
      <div v-show="mainTab === 'catalog'" class="knot-connectors-layout k-gap-5 k-min-h-[500px]" data-knot-test="knot-connectors-layout">
      <!-- Left list -->
      <aside class="knot-connectors-layout__pane k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-flex k-flex-col k-min-h-0 k-min-w-0">
        <div class="k-p-3 k-border-b k-border-knot-border k-space-y-2">
          <div class="k-relative">
            <Search :size="14" class="k-absolute k-left-3 k-top-1/2 k--translate-y-1/2 k-text-knot-text-soft" />
            <input
              v-model="search"
              type="text"
              :placeholder="t('connectorsPage.searchPlaceholder')"
              class="k-w-full k-pl-9 k-pr-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary k-text-knot-text"
            />
          </div>
          <div class="k-flex k-flex-wrap k-gap-1.5">
            <button
              v-for="cat in categories"
              :key="cat.id"
              type="button"
              @click="selectedCategory = cat.id"
              :class="[
                'k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold k-transition-colors',
                selectedCategory === cat.id
                  ? 'k-bg-knot-primary k-text-white'
                  : 'k-bg-knot-surface-soft k-text-knot-text-muted hover:k-text-knot-primary',
              ]"
            >
              {{ cat.label }} ({{ cat.count }})
            </button>
          </div>
          <div class="k-flex k-flex-wrap k-gap-1.5 k-items-center">
            <span class="k-text-[10px] k-font-semibold k-text-knot-text-soft k-uppercase k-tracking-wide">{{ t('connectorsPage.sourceFiltersLabel') }}</span>
            <button
              v-for="src in sourceFilters"
              :key="src.id"
              type="button"
              :aria-pressed="selectedSource === src.id"
              @click="selectSourceFilter(src.id)"
              :class="[
                'k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold k-transition-colors k-border',
                selectedSource === src.id
                  ? 'k-bg-knot-text k-text-knot-surface k-border-knot-text'
                  : 'k-bg-transparent k-text-knot-text-muted k-border-knot-border hover:k-text-knot-text',
              ]"
            >
              {{ src.label }} ({{ src.count }})
            </button>
          </div>
        </div>
        <div class="k-flex-1 k-min-h-0 k-overflow-y-auto k-p-2 k-space-y-1">
          <button
            v-for="c in filtered"
            :key="String(c.metadata.id)"
            type="button"
            @click="selectedId = String(c.metadata.id)"
            :class="[
              'k-w-full k-text-left k-px-2.5 k-py-2 k-rounded-knot-sm k-flex k-items-center k-gap-2.5 k-border k-transition-all',
              selectedId === String(c.metadata.id)
                ? 'k-bg-knot-primary-soft k-border-knot-primary'
                : 'k-border-transparent hover:k-bg-knot-surface-soft hover:k-border-knot-border',
              c.available === false ? 'k-opacity-60' : '',
            ]"
          >
            <div
              class="k-h-8 k-w-8 k-rounded-knot-sm k-flex k-items-center k-justify-center k-text-white k-shadow-knot-xs k-shrink-0"
              :style="{ background: 'linear-gradient(135deg, ' + meta(c).color + ' 0%, ' + meta(c).color + 'cc 100%)' }"
            >
              <component :is="meta(c).icon" :size="14" />
            </div>
            <div class="k-min-w-0 k-flex-1">
              <div class="k-flex k-items-center k-gap-1.5">
                <div class="k-text-[13px] k-font-semibold k-text-knot-text k-truncate">{{ catalogTitle(c.metadata) }}</div>
                <KProBadge v-if="showProBadge(c)" :label="proBadgeLabel(c)" />
                <span
                  v-if="sourceTag(c) !== 'core'"
                  :class="['k-px-1.5 k-py-px k-rounded-knot-pill k-text-[9px] k-font-bold k-uppercase k-tracking-wider', sourceBadgeClass(sourceTag(c))]"
                >
                  {{ sourceTag(c) }}
                </span>
                <Lock v-if="c.available === false" :size="11" class="k-text-knot-text-soft" />
              </div>
              <div class="k-text-[11px] k-text-knot-text-soft k-font-mono k-truncate">{{ c.metadata.id }}</div>
            </div>
          </button>
          <div v-if="!filtered.length" class="k-text-center k-text-knot-text-soft k-text-sm k-py-6">
            No connector matches.
          </div>
        </div>
      </aside>

      <!-- Right detail -->
      <LicenseActivationModal
        v-if="activationTarget"
        :open="!!activationTarget"
        :extension-id="activationTarget.id"
        :extension-label="activationTarget.label"
        @close="activationTarget = null"
        @activated="onActivated"
      />
      <LicenseDeactivateModal
        v-if="deactivationTarget"
        :open="!!deactivationTarget"
        :extension-id="deactivationTarget.id"
        :extension-label="deactivationTarget.label"
        @close="deactivationTarget = null"
        @deactivated="onDeactivated"
      />
      <section v-if="selected" class="knot-connectors-layout__pane k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-flex k-flex-col k-overflow-hidden k-min-w-0">
        <header class="k-px-6 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-gap-4">
          <div
            class="k-h-12 k-w-12 k-rounded-knot-md k-flex k-items-center k-justify-center k-text-white k-shadow-knot-sm"
            :style="{ background: 'linear-gradient(135deg, ' + meta(selected).color + ' 0%, ' + meta(selected).color + 'cc 100%)' }"
          >
            <component :is="meta(selected).icon" :size="22" />
          </div>
          <div class="k-min-w-0 k-flex-1">
            <div class="k-flex k-items-center k-gap-2 k-flex-wrap">
              <h2 class="k-text-lg k-font-bold k-text-knot-text">{{ catalogTitle(selected.metadata) }}</h2>
              <KProBadge v-if="showProBadge(selected)" :label="proBadgeLabel(selected)" />
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold', categoryBadge(String(selected.metadata.category))]">
                {{ selected.metadata.category }}
              </span>
              <span
                v-if="sourceTag(selected) !== 'core'"
                :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider', sourceBadgeClass(sourceTag(selected))]"
              >
                {{ sourceTag(selected) }}
              </span>
              <span
                v-if="selected.extensionInfo?.license_status"
                :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold', licenseBadgeClass(selected.extensionInfo.license_status)]"
              >
                <KeyRound :size="10" class="k-inline -k-mt-0.5 k-mr-0.5" /> {{ selected.extensionInfo.license_status }}
              </span>
            </div>
            <div class="k-text-xs k-font-mono k-text-knot-text-soft">{{ selected.metadata.id }}</div>
          </div>
        </header>

        <div class="k-flex-1 k-overflow-y-auto k-px-6 k-py-5 k-space-y-6">
          <section>
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-2">
              <Info :size="14" class="k-text-knot-primary" /> Description
            </h3>
            <p class="k-text-sm k-text-knot-text-muted k-leading-relaxed">
              {{ catalogDescription(selected.metadata) }}
            </p>
            <div
              v-if="String(selected.metadata.id) === 'action.email' && sourceTag(selected) === 'core'"
              class="k-mt-3 k-bg-knot-success-soft k-border k-border-knot-success/30 k-text-knot-success k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-leading-relaxed"
            >
              {{ t('connectorsPage.detail.coreIncludedCallout') }}
            </div>
          </section>

          <section v-if="connectorUsageExample(selected)">
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-mb-2">{{ t('connectorsPage.detail.usageExampleTitle') }}</h3>
            <pre class="k-text-xs k-font-mono k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text k-whitespace-pre-wrap">{{ connectorUsageExample(selected) }}</pre>
          </section>

          <section v-if="selected.extensionInfo">
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-2">
              <ShoppingBag :size="14" class="k-text-knot-primary" /> {{ t('connectorsPage.detail.extensionProvidedBy') }}
            </h3>
            <div class="k-bg-knot-surface-soft k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-space-y-1">
              <div>
                <strong>{{ selected.extensionInfo.label ?? selected.extensionInfo.id }}</strong>
                <span v-if="selected.extensionInfo.version" class="k-text-knot-text-soft k-ml-2 k-font-mono">v{{ selected.extensionInfo.version }}</span>
              </div>
              <div v-if="selected.extensionInfo?.license_status === 'tampered'" class="k-text-knot-danger k-text-xs k-space-y-1 k-border k-border-knot-danger/30 k-rounded-knot-sm k-p-2 k-bg-knot-danger-soft">
                <p>{{ t('connectorsPage.detail.manifestOutdated') }}</p>
                <p class="k-opacity-90">{{ t('connectorsPage.detail.manifestOutdatedHint') }}</p>
                <p v-if="selected.extensionInfo.license_error" class="k-font-mono k-text-[10px] k-opacity-80">{{ selected.extensionInfo.license_error }}</p>
              </div>
              <div v-else-if="selected.available === false" class="k-text-knot-danger k-text-xs">
                {{ t('connectorsPage.detail.notAvailable', {
                  reason: selected.extensionInfo.error ?? selected.extensionInfo.status ?? t('connectorsPage.detail.unknownReason'),
                }) }}
              </div>
              <div v-else-if="selected.extensionInfo.license_expires_at" class="k-text-knot-text-soft k-text-xs">
                {{ t('connectorsPage.detail.licenceExpires', { date: String(selected.extensionInfo.license_expires_at).slice(0, 10) }) }}
              </div>
              <div v-if="isActivatable(selected) || isDeactivatable(selected)" class="k-pt-2 k-flex k-flex-wrap k-gap-2">
                <button
                  v-if="isActivatable(selected)"
                  type="button"
                  class="k-px-3 k-py-1.5 k-text-xs k-font-semibold k-text-white k-bg-knot-primary hover:k-bg-knot-primary-strong k-rounded-knot-sm k-flex k-items-center k-gap-1.5"
                  @click="openActivation(selected)"
                >
                  <KeyRound :size="12" /> {{ t('connectorsPage.detail.activateAddon') }}
                </button>
                <button
                  v-if="isDeactivatable(selected)"
                  type="button"
                  class="k-px-3 k-py-1.5 k-text-xs k-font-semibold k-text-knot-warning k-border k-border-knot-warning k-rounded-knot-sm k-flex k-items-center k-gap-1.5 hover:k-bg-knot-warning-soft"
                  @click="openDeactivation(selected)"
                >
                  <KeyRound :size="12" /> {{ t('connectorsPage.detail.deactivateAddon') }}
                </button>
              </div>
            </div>
          </section>

          <section v-if="selected.credentialType">
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-2">
              <KeyRound :size="14" class="k-text-knot-warning" /> {{ t('connectorsPage.detail.credentialsRequired') }}
            </h3>
            <div class="k-bg-knot-warning-soft k-text-knot-warning k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-mb-3">
              {{ t('connectorsPage.detail.credentialsHint', { type: selected.credentialType }) }}
              <i18n-t keypath="connectorsPage.detail.createCredentialHint" tag="span">
                <template #link>
                  <a href="?mode=credentials" class="k-underline k-font-semibold">{{ t('connectorsPage.detail.credentialsLink') }}</a>
                </template>
              </i18n-t>
            </div>
            <div v-if="credentialFieldEntries(selected.credentialSchema).length" class="k-overflow-hidden k-rounded-knot-sm k-border k-border-knot-border">
              <h4 class="k-sr-only">{{ t('connectorsPage.detail.credentialFields') }}</h4>
              <table class="k-w-full k-text-sm">
                <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
                  <tr>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colField') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colType') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colRequired') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colSecret') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colDescription') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="field in credentialFieldEntries(selected.credentialSchema)"
                    :key="String(field.name)"
                    class="k-border-t k-border-knot-border"
                  >
                    <td class="k-px-3 k-py-2 k-font-mono k-text-knot-text">{{ field.name }}</td>
                    <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ fieldType(field) }}</td>
                    <td class="k-px-3 k-py-2">
                      <span v-if="fieldRequired(field)" class="k-text-knot-danger k-text-xs k-font-semibold">{{ t('connectorsPage.detail.required') }}</span>
                      <span v-else class="k-text-knot-text-soft k-text-xs">{{ t('connectorsPage.detail.optional') }}</span>
                    </td>
                    <td class="k-px-3 k-py-2 k-text-xs">
                      <span v-if="field.secret" class="k-text-knot-warning k-font-semibold">{{ t('connectorsPage.detail.secretYes') }}</span>
                      <span v-else class="k-text-knot-text-soft">{{ t('connectorsPage.detail.secretNo') }}</span>
                    </td>
                    <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ field.description }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="fieldEntries(selected.configSchema).length">
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-2">
              <ArrowDownUp :size="14" class="k-text-knot-primary" /> {{ t('connectorsPage.detail.configFields') }}
            </h3>
            <div class="k-overflow-hidden k-rounded-knot-sm k-border k-border-knot-border">
              <table class="k-w-full k-text-sm">
                <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
                  <tr>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colField') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colType') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colRequired') }}</th>
                    <th class="k-text-left k-px-3 k-py-2 k-font-semibold">{{ t('connectorsPage.detail.colDescription') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="field in fieldEntries(selected.configSchema)" :key="String(field.name ?? field.key ?? Math.random())" class="k-border-t k-border-knot-border">
                    <td class="k-px-3 k-py-2 k-font-mono k-text-knot-text">{{ field.name ?? field.key ?? '—' }}</td>
                    <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ fieldType(field) }}</td>
                    <td class="k-px-3 k-py-2">
                      <span v-if="fieldRequired(field)" class="k-text-knot-danger k-text-xs k-font-semibold">{{ t('connectorsPage.detail.required') }}</span>
                      <span v-else class="k-text-knot-text-soft k-text-xs">{{ t('connectorsPage.detail.optional') }}</span>
                    </td>
                    <td class="k-px-3 k-py-2 k-text-knot-text-muted">{{ field.description ?? field.help ?? '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="k-grid k-grid-cols-1 md:k-grid-cols-2 k-gap-4">
            <article>
              <h3 class="k-text-sm k-font-bold k-text-knot-text k-mb-2">{{ t('connectorsPage.detail.inputs') }}</h3>
              <div v-if="selected.inputs.length" class="k-space-y-1.5">
                <div
                  v-for="(input, idx) in selected.inputs"
                  :key="idx"
                  class="k-bg-knot-surface-soft k-px-3 k-py-2 k-rounded-knot-sm k-text-sm"
                >
                  <span class="k-font-mono k-text-knot-text">{{ input.id ?? input.name ?? '—' }}</span>
                  <span class="k-text-knot-text-soft k-ml-2">{{ input.label ?? input.description ?? '' }}</span>
                </div>
              </div>
              <p v-else class="k-text-sm k-text-knot-text-soft">{{ t('connectorsPage.detail.noInputs') }}</p>
            </article>
            <article>
              <h3 class="k-text-sm k-font-bold k-text-knot-text k-mb-2">{{ t('connectorsPage.detail.outputs') }}</h3>
              <div v-if="selected.outputs.length" class="k-space-y-1.5">
                <div
                  v-for="(output, idx) in selected.outputs"
                  :key="idx"
                  class="k-bg-knot-surface-soft k-px-3 k-py-2 k-rounded-knot-sm k-text-sm"
                >
                  <span class="k-font-mono k-text-knot-text">{{ output.id ?? output.name ?? '—' }}</span>
                  <span class="k-text-knot-text-soft k-ml-2">{{ output.label ?? output.description ?? '' }}</span>
                </div>
              </div>
              <p v-else class="k-text-sm k-text-knot-text-soft">{{ t('connectorsPage.detail.noOutputs') }}</p>
            </article>
          </section>

          <section>
            <h3 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-2">
              <AlertTriangle :size="14" class="k-text-knot-warning" /> {{ t('connectorsPage.detail.constraintsTitle') }}
            </h3>
            <ul class="k-text-sm k-text-knot-text-muted k-space-y-1.5 k-list-disc k-pl-5">
              <li v-if="String(selected.metadata.category) === 'ai'">
                {{ t('connectorsPage.detail.tipHttpTls') }}
              </li>
              <li v-if="String(selected.metadata.id) === 'ai.ollama_chat'">
                {{ t('connectorsPage.detail.tipOllama') }}
              </li>
              <li v-if="String(selected.metadata.id) === 'action.email'">
                {{ t('connectorsPage.detail.tipEmailCore') }}
              </li>
              <li v-else-if="String(selected.metadata.category) === 'communication'">
                {{ t('connectorsPage.detail.tipCommunicationTemplates', { example: expressionExample }) }}
              </li>
              <li v-if="String(selected.metadata.id) === 'notification.alert'">
                {{ t('connectorsPage.detail.tipAlertVsEmail') }}
              </li>
              <li v-if="String(selected.metadata.id) === 'logic.code'">
                {{ t('connectorsPage.detail.tipCodeSandbox') }}
              </li>
              <li>
                {{ t('connectorsPage.detail.tipSecretsMasked') }}
              </li>
              <li v-if="String(selected.metadata.category) === 'trigger'">
                {{ t('connectorsPage.detail.tipTriggerNoInputs') }}
              </li>
            </ul>
          </section>
        </div>
      </section>
    </div>
    </template>
  </div>
</template>
