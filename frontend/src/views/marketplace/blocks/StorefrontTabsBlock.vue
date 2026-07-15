<!--
  Tabbed storefront — Packs / Templates / Bundled (block-driven, no duplicate page hero).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Library,
  ShoppingBag,
  Package,
  Layers,
  Loader2,
  ExternalLink,
  KeyRound,
  CheckCircle2,
  AlertTriangle,
  Mail,
  MessageSquare,
  Cpu,
  Database,
  Repeat,
  FileText,
  ShoppingCart,
  CreditCard,
  Workflow as WorkflowIcon,
  Box,
  ListChecks,
  BarChart3,
  UserPlus,
  GitBranch,
  Info,
  RefreshCw,
  Lock,
} from 'lucide-vue-next';
import {
  knotApi,
  type BundledTemplateSummary,
  type MarketplacePack,
  type MarketplaceTemplate,
  type MarketplaceTier,
} from '../../../lib/api';
import { KNOWN_SKU_ENTERPRISE, KNOWN_SKU_PRO_PACK } from '../../../lib/known-skus';
import {
  knotToolsLocalePrefix,
  knotToolsPricing,
  lockedTemplateExternalHref,
  resolvePackBuyUrl,
} from '../../../lib/marketplaceLinks';
import LicenseActivationModal from '../../../components/licensing/LicenseActivationModal.vue';
import LicenseDeactivateModal from '../../../components/licensing/LicenseDeactivateModal.vue';
import KProBadge from '../../../components/ui/KProBadge.vue';
import MarketplaceEmptyState from '../MarketplaceEmptyState.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';
import type { BlockContext } from '../types';

const { t, locale } = useI18n();

const injectedCtx = inject(knotMarketplaceBlockContextKey);
if (!injectedCtx) {
  throw new Error('StorefrontTabsBlock must render under BlockRenderer (missing context).');
}
const ctx: BlockContext = injectedCtx;

const catalog = computed(() => ctx.marketplace.value);
const marketplaceLoading = computed(() => ctx.loading.value);
const marketplaceRefreshing = computed(() => ctx.refreshing.value);
const marketplaceError = computed(() => ctx.error.value);
const marketplaceLoadBlockedCode = computed(() => ctx.marketplaceLoadBlockedCode.value);


type TabKey = 'packs' | 'templates' | 'bundled';

function tabFromRouteKind(kind: string | undefined): TabKey | null {
  if (kind === 'templates') return 'templates';
  if (kind === 'packs') return 'packs';
  return null;
}

function readInitialTab(): TabKey {
  if (typeof window === 'undefined') return 'packs';
  const params = new URLSearchParams(window.location.search);
  const raw = (params.get('tab') ?? '').toLowerCase();
  if (raw === 'templates' || raw === 'bundled' || raw === 'packs') {
    return raw as TabKey;
  }
  // Hash route `#/templates` / `#/packs` must select the matching storefront tab
  // even when `?tab=` is absent (TopBar navigates via hash only).
  const fromRoute = tabFromRouteKind(ctx.route.value.kind);
  if (fromRoute) {
    return fromRoute;
  }
  const hashPath = (window.location.hash.split('?')[0] ?? '').replace(/^#/, '');
  if (hashPath === '/templates' || hashPath.startsWith('/templates/')) return 'templates';
  if (hashPath === '/packs' || hashPath.startsWith('/packs/')) return 'packs';
  return 'packs';
}

const activeTab = ref<TabKey>(readInitialTab());

const tierFilter = ref<MarketplaceTier | ''>('');
const templateCategoryFilter = ref<string>('');
const creating = ref<string | null>(null);

const bundledTemplates = ref<BundledTemplateSummary[]>([]);
const bundledLoading = ref(false);
const bundledError = ref<string | null>(null);
const instantiateError = ref<string | null>(null);

const baseUrl = computed(
  () =>
    (typeof window !== 'undefined'
      && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL)
    || '',
);

function setTab(tab: TabKey): void {
  activeTab.value = tab;
  if (tab === 'bundled') {
    void loadBundled();
  }
  // Keep the URL in sync so a copy/paste preserves the active tab and
  // the back/forward buttons feel right. We swap silently with
  // history.replaceState to avoid polluting browser history with a
  // new entry every click.
  if (typeof window !== 'undefined' && window.history?.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url.toString());
  }
}

async function loadBundled(): Promise<void> {
  bundledLoading.value = true;
  bundledError.value = null;
  try {
    const res = await knotApi.listBundledTemplates();
    bundledTemplates.value = res.templates ?? [];
  } catch (err) {
    bundledError.value = err instanceof Error ? err.message : t('marketplace.bundledLoadFailed');
    bundledTemplates.value = [];
  } finally {
    bundledLoading.value = false;
  }
}

async function importBundled(slug: string): Promise<void> {
  creating.value = slug;
    bundledError.value = null;
  try {
    const pack = await knotApi.getBundledTemplate(slug);
    const res = await knotApi.saveWorkflow({
      label: pack.workflow.label,
      description: pack.workflow.description,
      status: 'draft',
      definition: pack.workflow.definition,
    });
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${res.workflow.id}`;
  } catch (err) {
    bundledError.value = err instanceof Error ? err.message : t('marketplace.importFailed');
  } finally {
    creating.value = null;
  }
}

async function forceRefresh(): Promise<void> {
  await ctx.reloadMarketplace({ refresh: true });
}

async function instantiate(tmpl: MarketplaceTemplate): Promise<void> {
  // Hard guard so a stale UI state cannot bypass the gate. The API
  // also refuses with HTTP 403 — this client check is an early-exit
  // for UX, never a security boundary.
  if (tmpl.locked) {
    return;
  }
  creating.value = tmpl.slug;
  instantiateError.value = null;
  try {
    const res = await knotApi.instantiateTemplate(tmpl.slug);
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${res.workflow.id}`;
  } catch (err) {
    instantiateError.value = err instanceof Error ? err.message : t('marketplace.instantiateFailed');
  } finally {
    creating.value = null;
  }
}

const localePrefix = computed(() => knotToolsLocalePrefix(locale.value));

const pricingBrowseHref = computed(() => knotToolsPricing(localePrefix.value));

function packSkuForTemplate(tmpl: { tier: string }): string {
  return tmpl.tier === 'enterprise' ? KNOWN_SKU_ENTERPRISE : KNOWN_SKU_PRO_PACK;
}

function lockedCta(tmpl: { tier: string; lockedReason?: { status?: string } | null }): {
  label: string;
  href: string | null;
  inAppActivation: boolean;
} {
  const status = tmpl.lockedReason?.status ?? 'unknown';
  const sku = packSkuForTemplate(tmpl);
  const external = lockedTemplateExternalHref(status, sku, localePrefix.value);
  if (status === 'expired') {
    return {
      label: t('marketplace.lockedCtaRenew'),
      href: external,
      inAppActivation: false,
    };
  }
  if (status === 'not_installed') {
    return {
      label: tmpl.tier === 'enterprise'
        ? t('marketplace.lockedCtaBuyEnterprise')
        : t('marketplace.lockedCtaBuyProPack'),
      href: external,
      inAppActivation: false,
    };
  }
  return {
    label: t('marketplace.lockedCtaActivate'),
    href: external,
    inAppActivation: external === null,
  };
}

function openLockedActivation(tmpl: { tier: string }): void {
  const sku = packSkuForTemplate(tmpl);
  activationTarget.value = { id: sku, label: sku === KNOWN_SKU_ENTERPRISE ? 'Enterprise' : 'Pro Pack' };
}

function openActivation(pack: MarketplacePack): void {
  activationTarget.value = { id: pack.slug, label: pack.label };
}

function isDeactivatablePack(pack: MarketplacePack): boolean {
  if (!pack.installed) {
    return false;
  }
  if (pack.tier !== 'pro' && pack.tier !== 'enterprise') {
    return false;
  }
  return pack.licenseActive
    || pack.licenseStatus === 'valid'
    || pack.licenseStatus === 'grace';
}

function isActivatablePack(pack: MarketplacePack): boolean {
  if (!pack.installed) {
    return false;
  }
  if (pack.tier !== 'pro' && pack.tier !== 'enterprise') {
    return false;
  }
  if (pack.licenseStatus === 'not_required') {
    return false;
  }
  return !isDeactivatablePack(pack);
}

const activationTarget = ref<{ id: string; label: string } | null>(null);

const deactivationTarget = ref<{ id: string; label: string } | null>(null);

function openDeactivation(pack: MarketplacePack): void {
  deactivationTarget.value = { id: pack.slug, label: pack.label };
}

function onDeactivated(): void {
  deactivationTarget.value = null;
  void ctx.reloadMarketplace();
}

function packTierBadgeLabel(tier: MarketplaceTier): string | null {
  if (tier === 'pro') {
    return t('marketplace.proBadgeLabel');
  }
  if (tier === 'enterprise') {
    return t('marketplace.enterpriseBadgeLabel');
  }
  return null;
}

function onActivated(): void {
  activationTarget.value = null;
  void ctx.reloadMarketplace();
}

function priceLabel(pack: MarketplacePack): string {
  if (pack.priceMonthlyCents === null && pack.priceYearlyCents === null) {
    return pack.tier === 'free' ? t('marketplace.priceFree') : t('marketplace.priceDash');
  }
  const monthly = pack.priceMonthlyCents !== null
    ? t('marketplace.priceMonthly', { amount: (pack.priceMonthlyCents / 100).toFixed(0) })
    : null;
  const yearly = pack.priceYearlyCents !== null
    ? t('marketplace.priceYearly', { amount: (pack.priceYearlyCents / 100).toFixed(0) })
    : null;
  return [monthly, yearly].filter(Boolean).join(t('marketplace.priceJoin'));
}

function tierClass(tier: MarketplaceTier): string {
  switch (tier) {
    case 'free':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'beta':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'pro':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'enterprise':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}

function packStatusInfo(pack: MarketplacePack): { label: string; color: string; icon: typeof CheckCircle2 } {
  if (!pack.installed) {
    return {
      label: t('marketplace.packStatusNotInstalled'),
      color: 'k-bg-knot-surface-soft k-text-knot-text-muted',
      icon: Package,
    };
  }
  // `licenseActive` is the canonical signal: a pack can be installed
  // (module enabled in Dolibarr) yet not have an active licence (no
  // activation, expired, tampered, …). The marketplace gate enforces
  // active-licence semantics, so the badge must reflect the same.
  if (pack.licenseActive) {
    return {
      label: t('marketplace.packStatusLicenseActive'),
      color: 'k-bg-knot-success-soft k-text-knot-success',
      icon: CheckCircle2,
    };
  }
  if (pack.licenseStatus === 'not_required') {
    return {
      label: t('marketplace.packStatusActive'),
      color: 'k-bg-knot-success-soft k-text-knot-success',
      icon: CheckCircle2,
    };
  }
  if (pack.licenseStatus === 'expired') {
    return {
      label: t('marketplace.packStatusExpired'),
      color: 'k-bg-knot-warning-soft k-text-knot-warning',
      icon: AlertTriangle,
    };
  }
  if (pack.licenseStatus === 'missing' || !pack.licenseStatus) {
    return {
      label: t('marketplace.packStatusNeedsActivation'),
      color: 'k-bg-knot-warning-soft k-text-knot-warning',
      icon: KeyRound,
    };
  }
  return {
    label: pack.licenseStatus,
    color: 'k-bg-knot-warning-soft k-text-knot-warning',
    icon: AlertTriangle,
  };
}

const filteredPacks = computed<MarketplacePack[]>(() => {
  const list = catalog.value?.packs ?? [];
  return list.filter((p: MarketplacePack) => {
    if (tierFilter.value && p.tier !== tierFilter.value) return false;
    return true;
  });
});

const bundledCategories = computed<string[]>(() => {
  const set = new Set<string>();
  bundledTemplates.value.forEach((t) => {
    if (t.category) set.add(t.category);
  });
  return [...set].sort();
});

const templateCategories = computed<string[]>(() => {
  const set = new Set<string>();
  (catalog.value?.templates ?? []).forEach((t: MarketplaceTemplate) => {
    if (t.category) set.add(t.category);
  });
  return [...set].sort();
});

const categoryFilterOptions = computed<string[]>(() => (
  activeTab.value === 'bundled' ? bundledCategories.value : templateCategories.value
));

const filteredBundled = computed<BundledTemplateSummary[]>(() => {
  return bundledTemplates.value.filter((t: BundledTemplateSummary) => {
    if (tierFilter.value && t.tier !== tierFilter.value) return false;
    if (templateCategoryFilter.value && t.category !== templateCategoryFilter.value) return false;
    return true;
  });
});

const filteredTemplates = computed<MarketplaceTemplate[]>(() => {
  return (catalog.value?.templates ?? []).filter((t: MarketplaceTemplate) => {
    if (tierFilter.value && t.tier !== tierFilter.value) return false;
    if (templateCategoryFilter.value && t.category !== templateCategoryFilter.value) return false;
    return true;
  });
});

const iconMap: Record<string, typeof Mail> = {
  mail: Mail,
  slack: MessageSquare,
  cpu: Cpu,
  database: Database,
  repeat: Repeat,
  'file-invoice': FileText,
  'file-signature': FileText,
  box: Box,
  tasks: ListChecks,
  chart: BarChart3,
  'user-plus': UserPlus,
  'credit-card': CreditCard,
  workflow: WorkflowIcon,
  'shopping-cart': ShoppingCart,
  'git-branch': GitBranch,
  'shopping-bag': ShoppingBag,
};

function templateIconFor(name: string) {
  return iconMap[name] ?? Library;
}

function packIconFor(name: string | null) {
  return name && iconMap[name] ? iconMap[name] : ShoppingBag;
}

function templateCategoryColor(cat: string): string {
  switch (cat) {
    case 'communication':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'ai':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    case 'crm':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'logic':
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
    case 'dolibarr':
      return 'k-bg-knot-warning-soft k-text-knot-warning';
    case 'reporting':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'ecommerce':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}

const tiers: MarketplaceTier[] = ['free', 'beta', 'pro', 'enterprise'];

onMounted(() => {
  if (activeTab.value === 'bundled') {
    void loadBundled();
  }
});

watch(
  () => ctx.route.value.kind,
  (kind) => {
    const next = tabFromRouteKind(kind);
    if (!next || next === activeTab.value) {
      return;
    }
    // Prefer explicit ?tab=bundled over route-derived packs/templates.
    const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
    if ((params?.get('tab') ?? '').toLowerCase() === 'bundled') {
      return;
    }
    activeTab.value = next;
  },
);
</script>

<template>
  <section class="k-space-y-5" role="region" aria-label="Marketplace catalog">
    <nav class="k-border-b k-border-knot-border k-flex k-gap-1 k-flex-wrap">
      <button
        type="button"
        :class="[
          'k-px-4 k-py-2 k-text-sm k-font-semibold k-flex k-items-center k-gap-2 k-border-b-2 k-transition-colors',
          activeTab === 'packs'
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text',
        ]"
        @click="setTab('packs')"
      >
        <ShoppingBag :size="14" /> {{ t('marketplace.tabPacks') }}
        <span v-if="catalog?.packs.length" class="k-text-[11px] k-font-normal k-bg-knot-surface-soft k-text-knot-text-muted k-px-1.5 k-py-0.5 k-rounded-knot-pill">
          {{ catalog?.packs.length }}
        </span>
      </button>
      <button
        type="button"
        :class="[
          'k-px-4 k-py-2 k-text-sm k-font-semibold k-flex k-items-center k-gap-2 k-border-b-2 k-transition-colors',
          activeTab === 'templates'
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text',
        ]"
        @click="setTab('templates')"
      >
        <Library :size="14" /> {{ t('marketplace.tabTemplates') }}
        <span v-if="catalog?.templates.length" class="k-text-[11px] k-font-normal k-bg-knot-surface-soft k-text-knot-text-muted k-px-1.5 k-py-0.5 k-rounded-knot-pill">
          {{ catalog?.templates.length }}
        </span>
      </button>
      <button
        type="button"
        :class="[
          'k-px-4 k-py-2 k-text-sm k-font-semibold k-flex k-items-center k-gap-2 k-border-b-2 k-transition-colors',
          activeTab === 'bundled'
            ? 'k-border-knot-primary k-text-knot-primary'
            : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text',
        ]"
        @click="setTab('bundled')"
      >
        <Layers :size="14" /> {{ t('marketplace.tabBundled') }}
        <span v-if="bundledTemplates.length" class="k-text-[11px] k-font-normal k-bg-knot-surface-soft k-text-knot-text-muted k-px-1.5 k-py-0.5 k-rounded-knot-pill">
          {{ bundledTemplates.length }}
        </span>
      </button>
    </nav>

    <div class="k-flex k-justify-end">
      <button
        v-if="activeTab !== 'bundled'"
        type="button"
        :disabled="marketplaceRefreshing || marketplaceLoading"
        class="k-px-3 k-py-1.5 k-text-xs k-text-knot-text-muted hover:k-text-knot-text k-flex k-items-center k-gap-1.5 k-border k-border-knot-border k-rounded-knot-sm disabled:k-opacity-50"
        :title="t('marketplace.refreshTitle')"
        @click="forceRefresh"
      >
        <RefreshCw :size="12" :class="marketplaceRefreshing ? 'k-animate-spin' : ''" />
        {{ marketplaceRefreshing ? t('marketplace.refreshBusy') : t('marketplace.refreshIdle') }}
      </button>
    </div>

    <div class="k-flex k-items-center k-gap-3 k-flex-wrap">
      <select
        v-model="tierFilter"
        class="k-text-sm k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-px-3 k-py-2 k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
      >
        <option value="">{{ t('marketplace.allTiers') }}</option>
        <option v-for="t in tiers" :key="t" :value="t">{{ t }}</option>
      </select>
      <select
        v-if="activeTab === 'templates' || activeTab === 'bundled'"
        v-model="templateCategoryFilter"
        class="k-text-sm k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-px-3 k-py-2 k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
      >
        <option value="">{{ t('marketplace.allCategories') }}</option>
        <option v-for="c in categoryFilterOptions" :key="c" :value="c">{{ c }}</option>
      </select>
    </div>

    <div v-if="bundledError && activeTab === 'bundled'" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">{{ bundledError }}</div>

    <div
      v-if="(marketplaceError || instantiateError) && activeTab !== 'bundled'"
      :class="
        marketplaceLoadBlockedCode === 'marketplace_ui_disabled'
          ? 'k-bg-knot-warning-soft k-text-knot-warning k-border k-border-knot-warning/40'
          : 'k-bg-knot-danger-soft k-text-knot-danger'
      "
      class="k-px-4 k-py-3 k-rounded-knot-sm k-text-sm"
      role="status"
    >
      {{ marketplaceError || instantiateError }}
    </div>

    <div
      v-if="catalog?.packsMeta.stale"
      class="k-bg-knot-warning-soft k-text-knot-warning k-px-4 k-py-2 k-rounded-knot-sm k-text-xs k-flex k-items-center k-gap-2"
    >
      <AlertTriangle :size="14" />
      {{ t('marketplace.catalogStalePacks') }}
    </div>

    <div
      v-if="activeTab === 'templates' && catalog?.templatesMeta.stale"
      class="k-bg-knot-warning-soft k-text-knot-warning k-px-4 k-py-2 k-rounded-knot-sm k-text-xs k-flex k-items-center k-gap-2"
    >
      <AlertTriangle :size="14" />
      {{ t('marketplace.catalogStaleTemplates') }}
    </div>

    <!-- Tab: Packs -->
    <section v-if="activeTab === 'packs'">
      <div v-if="marketplaceLoading" class="k-flex k-items-center k-gap-2 k-text-knot-text-soft k-py-12 k-justify-center">
        <Loader2 :size="14" class="k-animate-spin" /> {{ t('marketplace.loadingPacks') }}
      </div>
      <div v-else-if="filteredPacks.length === 0">
        <MarketplaceEmptyState
          :title="t('marketplace.noPacks')"
          :body="t('marketplace.productBlockEmpty')"
          :action-label="t('marketplace.pricingCta')"
          :action-href="pricingBrowseHref"
        >
          <template #illustration>
            <ShoppingBag :size="64" stroke-width="1.25" aria-hidden="true" />
          </template>
        </MarketplaceEmptyState>
      </div>
      <div v-else class="k-grid k-gap-4 sm:k-grid-cols-2 lg:k-grid-cols-3">
        <article
          v-for="pack in filteredPacks"
          :key="pack.slug"
          class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-3 k-flex k-flex-col k-shadow-knot-sm hover:k-border-knot-primary k-transition-colors"
        >
          <header class="k-flex k-items-start k-justify-between k-gap-3">
            <div class="k-flex k-items-center k-gap-2">
              <div class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
                <component :is="packIconFor(pack.icon)" :size="16" />
              </div>
              <div>
                <div class="k-flex k-items-center k-gap-1.5">
                  <h3 class="k-font-bold k-text-knot-text k-text-sm">{{ pack.label }}</h3>
                  <KProBadge
                    v-if="packTierBadgeLabel(pack.tier)"
                    :label="packTierBadgeLabel(pack.tier) ?? 'Pro'"
                  />
                </div>
                <code class="k-text-[10px] k-text-knot-text-soft k-font-mono">{{ pack.slug }}</code>
              </div>
            </div>
            <span
              :class="[
                'k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider',
                tierClass(pack.tier),
              ]"
            >
              {{ pack.tier }}
            </span>
          </header>

          <p class="k-text-xs k-text-knot-text-muted k-flex-1 k-line-clamp-3">
            {{ pack.description ?? t('marketplace.packDefaultDescription') }}
          </p>

          <div class="k-text-xs k-text-knot-text k-font-semibold">{{ priceLabel(pack) }}</div>

          <div class="k-flex k-items-center k-gap-2 k-text-[11px]">
            <component :is="packStatusInfo(pack).icon" :size="12" />
            <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill', packStatusInfo(pack).color]">
              {{ packStatusInfo(pack).label }}
            </span>
            <span v-if="pack.version" class="k-text-knot-text-soft k-font-mono">v{{ pack.version }}</span>
          </div>

          <!-- Action area: activate in-app when installed; otherwise knot.tools beta / product page. -->
          <div v-if="pack.installed" class="k-flex k-items-center k-gap-2 k-mt-auto">
            <button
              v-if="isActivatablePack(pack)"
              type="button"
              class="k-flex-1 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-primary k-text-white hover:k-bg-knot-primary-strong k-flex k-items-center k-justify-center k-gap-1.5"
              @click="openActivation(pack)"
            >
              <KeyRound :size="11" />
              {{ pack.licenseStatus === 'valid' ? t('marketplace.reactivateLicence') : t('marketplace.activateLicence') }}
            </button>
            <button
              v-if="isDeactivatablePack(pack)"
              type="button"
              class="k-flex-1 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-text-knot-warning k-border k-border-knot-warning hover:k-bg-knot-warning-soft k-flex k-items-center k-justify-center k-gap-1.5"
              @click="openDeactivation(pack)"
            >
              <KeyRound :size="11" />
              {{ t('marketplace.deactivateLicence') }}
            </button>
          </div>
          <div
            v-else
            class="k-mt-auto k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-space-y-2 k-text-[11px] k-text-knot-text-muted"
          >
            <div class="k-flex k-items-center k-gap-1.5 k-text-knot-text k-font-semibold">
              <Info :size="12" /> {{ t('marketplace.installHowTitle') }}
            </div>
            <ol class="k-list-decimal k-list-inside k-space-y-1">
              <li>
                {{ t('marketplace.installStep1Buy') }}
                <a
                  :href="resolvePackBuyUrl(pack.slug, pack.buyUrl, localePrefix)"
                  target="_blank"
                  rel="noopener"
                  class="k-text-knot-primary hover:k-underline"
                >knot.tools</a>
                {{ t('marketplace.installStep1OrBeta') }}
              </li>
              <li>
                {{ t('marketplace.installStep2') }} <code>mod{{ pack.slug.replace(/^knot-/, 'Knot') }}</code>
                {{ t('marketplace.installStep2b') }} <code>htdocs/custom/</code>.
              </li>
              <li>
                {{ t('marketplace.installStep3') }}
              </li>
              <li>
                {{ t('marketplace.installStep4') }}
              </li>
            </ol>
          </div>
        </article>
      </div>
    </section>

    <!-- Tab: Templates -->
    <section v-if="activeTab === 'templates'">
      <div v-if="marketplaceLoading" class="k-flex k-items-center k-gap-2 k-text-knot-text-soft k-py-12 k-justify-center">
        <Loader2 :size="14" class="k-animate-spin" /> {{ t('marketplace.loadingTemplates') }}
      </div>
      <div v-else-if="filteredTemplates.length === 0">
        <MarketplaceEmptyState
          :title="t('marketplace.noTemplatesMatch')"
          :body="t('marketplace.templateBlockEmpty')"
          :action-label="t('marketplace.pricingCta')"
          :action-href="pricingBrowseHref"
        >
          <template #illustration>
            <Library :size="64" stroke-width="1.25" aria-hidden="true" />
          </template>
        </MarketplaceEmptyState>
      </div>
      <div v-else class="k-grid k-gap-4 sm:k-grid-cols-2 lg:k-grid-cols-3">
        <article
          v-for="tpl in filteredTemplates"
          :key="tpl.slug"
          :class="[
            'k-bg-knot-surface k-border k-rounded-knot-md k-p-5 k-space-y-3 k-flex k-flex-col k-shadow-knot-sm k-transition-colors',
            tpl.locked
              ? 'k-border-knot-warning/40'
              : 'k-border-knot-border hover:k-border-knot-primary',
          ]"
        >
          <header class="k-flex k-items-start k-justify-between k-gap-3">
            <div
              :class="[
                'k-h-10 k-w-10 k-rounded-knot-sm k-flex k-items-center k-justify-center',
                tpl.locked
                  ? 'k-bg-knot-warning-soft k-text-knot-warning'
                  : 'k-bg-knot-primary-soft k-text-knot-primary',
              ]"
            >
              <component :is="tpl.locked ? Lock : templateIconFor(tpl.icon)" :size="18" />
            </div>
            <div class="k-flex k-flex-col k-items-end k-gap-1">
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider', tierClass(tpl.tier)]">
                {{ tpl.tier }}
              </span>
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider', templateCategoryColor(tpl.category)]">
                {{ tpl.category }}
              </span>
            </div>
          </header>

          <div class="k-flex-1">
            <h3 class="k-font-bold k-text-knot-text k-text-sm">{{ tpl.label }}</h3>
            <p class="k-text-xs k-text-knot-text-muted k-mt-1 k-line-clamp-3">{{ tpl.description }}</p>
          </div>

          <div v-if="!tpl.locked" class="k-text-[11px] k-text-knot-text-soft">
            {{
              t('marketplace.nodesEdges', {
                nodes: (((tpl.definition as Record<string, unknown> | null)?.nodes as unknown[]) ?? []).length,
                edges: (((tpl.definition as Record<string, unknown> | null)?.edges as unknown[]) ?? []).length,
              })
            }}
          </div>
          <div
            v-else
            class="k-text-[11px] k-text-knot-text-soft k-italic k-flex k-items-center k-gap-1"
          >
            <Lock :size="11" />
            {{ t('marketplace.lockedContent', { tier: tpl.tier }) }}
          </div>

          <!-- Locked state: never enable instantiation, never expose
               the workflow JSON. The API already strips it; this block
               keeps the user on the right rails by pointing to the
               only place a real licence can be obtained. -->
          <a
            v-if="tpl.locked && lockedCta(tpl).href"
            :href="lockedCta(tpl).href!"
            target="_blank"
            rel="noopener"
            class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-sm k-font-semibold k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-border k-border-knot-warning/30 hover:k-bg-knot-warning hover:k-text-white"
          >
            <Lock :size="13" />
            {{ lockedCta(tpl).label }}
            <ExternalLink :size="11" />
          </a>
          <button
            v-else-if="tpl.locked && lockedCta(tpl).inAppActivation"
            type="button"
            class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-sm k-font-semibold k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-border k-border-knot-warning/30 hover:k-bg-knot-warning hover:k-text-white"
            @click="openLockedActivation(tpl)"
          >
            <Lock :size="13" />
            {{ lockedCta(tpl).label }}
          </button>
          <button
            v-else
            :disabled="creating === tpl.slug"
            class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-sm k-font-semibold k-rounded-knot-sm k-bg-knot-hero k-text-white hover:k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] disabled:k-opacity-60"
            @click="instantiate(tpl)"
          >
            <Loader2 v-if="creating === tpl.slug" :size="14" class="k-animate-spin" />
            {{ t('marketplace.useTemplate') }}
          </button>
        </article>
      </div>
    </section>

    <!-- Tab: bundled templates (offline) -->
    <section v-if="activeTab === 'bundled'" class="k-space-y-3">
      <p class="k-text-xs k-text-knot-text-muted">
        {{ t('marketplace.bundledIntro') }}
      </p>
      <div v-if="bundledLoading" class="k-flex k-items-center k-gap-2 k-text-knot-text-soft k-py-12 k-justify-center">
        <Loader2 :size="14" class="k-animate-spin" /> {{ t('marketplace.loadingBundled') }}
      </div>
      <div v-else-if="filteredBundled.length === 0">
        <MarketplaceEmptyState :title="t('marketplace.noBundledMatch')" :body="t('marketplace.bundledIntro')">
          <template #illustration>
            <Layers :size="64" stroke-width="1.25" aria-hidden="true" />
          </template>
        </MarketplaceEmptyState>
      </div>
      <div v-else class="k-grid k-gap-4 sm:k-grid-cols-2 lg:k-grid-cols-3">
        <article
          v-for="b in filteredBundled"
          :key="b.slug"
          class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5 k-space-y-3 k-flex k-flex-col k-shadow-knot-sm hover:k-border-knot-primary k-transition-colors"
        >
          <header class="k-flex k-items-start k-justify-between k-gap-3">
            <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
              <Layers :size="18" />
            </div>
            <div class="k-flex k-flex-col k-items-end k-gap-1">
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider', tierClass(b.tier as MarketplaceTier)]">
                {{ b.tier }}
              </span>
              <span :class="['k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase k-tracking-wider', templateCategoryColor(b.category)]">
                {{ b.category }}
              </span>
              <span
                v-if="b.demoInvalid"
                class="k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-bg-knot-warning-soft k-text-knot-warning"
              >{{ t('marketplace.demoLintBadge') }}</span>
            </div>
          </header>
          <div class="k-flex-1">
            <h3 class="k-font-bold k-text-knot-text k-text-sm">{{ b.title }}</h3>
            <p class="k-text-xs k-text-knot-text-muted k-mt-1 k-line-clamp-3">{{ b.description }}</p>
            <p class="k-text-[11px] k-text-knot-text-soft k-mt-2">{{ b.difficulty }} · <code class="k-font-mono">{{ b.slug }}</code></p>
          </div>
          <button
            :disabled="creating === b.slug"
            class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-sm k-font-semibold k-rounded-knot-sm k-bg-knot-hero k-text-white hover:k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] disabled:k-opacity-60"
            type="button"
            @click="importBundled(b.slug)"
          >
            <Loader2 v-if="creating === b.slug" :size="14" class="k-animate-spin" />
            {{ t('marketplace.importBundled') }}
          </button>
        </article>
      </div>
    </section>

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
  </section>
</template>
