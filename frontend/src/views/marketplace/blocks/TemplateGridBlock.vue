<!--
  Remote template grid with lightweight actions (uses catalog from `/api/marketplace.php`).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ExternalLink, Library, Loader2, Lock } from 'lucide-vue-next';

import { knotApi, type MarketplaceTemplate, type MarketplaceTier } from '../../../lib/api';
import { KNOWN_SKU_ENTERPRISE, KNOWN_SKU_PRO_PACK } from '../../../lib/known-skus';
import {
  knotToolsLocalePrefix,
  knotToolsPricing,
  lockedTemplateExternalHref,
} from '../../../lib/marketplaceLinks';
import LicenseActivationModal from '../../../components/licensing/LicenseActivationModal.vue';

import { knotMarketplaceBlockContextKey } from '../contextKey';
import MarketplaceEmptyState from '../MarketplaceEmptyState.vue';

import WorkflowMiniPreview from './WorkflowMiniPreview.vue';

const props = withDefaults(
  defineProps<{
    limit?: number;
    mode?: 'grid' | 'detail';
    /** When set, only these template slugs appear (order preserved), then `limit` caps the list. */
    pins?: string[];
    showHeader?: boolean;
  }>(),
  { limit: 6, mode: 'grid', pins: undefined, showHeader: true },
);

const { t, locale } = useI18n();

const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('TemplateGridBlock requires Knot marketplace editorial context.');
}

const creating = ref<string | null>(null);
const activationTarget = ref<{ id: string; label: string } | null>(null);
const localePrefix = computed(() => knotToolsLocalePrefix(locale.value));
const pricingBrowseHref = computed(() => knotToolsPricing(localePrefix.value));

function packSkuForTemplate(tmpl: MarketplaceTemplate): string {
  return tmpl.tier === 'enterprise' ? KNOWN_SKU_ENTERPRISE : KNOWN_SKU_PRO_PACK;
}

function lockedCta(tmpl: MarketplaceTemplate): {
  label: string;
  href: string | null;
  inAppActivation: boolean;
} {
  const status = tmpl.lockedReason?.status ?? 'unknown';
  const sku = packSkuForTemplate(tmpl);
  const external = lockedTemplateExternalHref(status, sku, localePrefix.value);
  if (status === 'expired') {
    return { label: t('marketplace.lockedCtaRenew'), href: external, inAppActivation: false };
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

function openLockedActivation(tmpl: MarketplaceTemplate): void {
  const sku = packSkuForTemplate(tmpl);
  activationTarget.value = {
    id: sku,
    label: tmpl.tier === 'enterprise' ? t('marketplace.enterpriseBadgeLabel') : t('marketplace.proBadgeLabel'),
  };
}

const baseUrl = computed(
  () =>
    (typeof window !== 'undefined'
      && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL)
    || '',
);

const templates = computed<MarketplaceTemplate[]>(() => {
  const source = ctx.marketplace.value?.templates ?? [];
  if (props.mode === 'detail' && ctx.route.value.kind === 'template' && ctx.route.value.slug) {
    const match = source.find((row) => row.slug === ctx.route.value.slug);
    return match ? [match] : [];
  }
  const pins = props.pins?.filter(Boolean) ?? [];
  if (pins.length > 0) {
    const out: MarketplaceTemplate[] = [];
    for (const slug of pins) {
      const hit = source.find((row) => row.slug === slug);
      if (hit) {
        out.push(hit);
      }
    }
    return out.slice(0, Math.max(0, props.limit));
  }
  return source.slice(0, Math.max(0, props.limit));
});

function tierTone(tier: MarketplaceTier): string {
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

function categoryTone(cat: string): string {
  switch (cat) {
    case 'communication':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    case 'ai':
      return 'k-bg-knot-accent-soft k-text-knot-accent';
    case 'crm':
      return 'k-bg-knot-success-soft k-text-knot-success';
    default:
      return 'k-bg-knot-surface-soft k-text-knot-text-muted';
  }
}

function nodeEdgeCounts(tmpl: MarketplaceTemplate): { nodes: number; edges: number } {
  const def = tmpl.definition as Record<string, unknown> | null;
  const nodes = (def?.nodes as unknown[] | undefined)?.length ?? 0;
  const edges = (def?.edges as unknown[] | undefined)?.length ?? 0;
  return { nodes, edges };
}

async function useTemplateNow(tmpl: MarketplaceTemplate): Promise<void> {
  if (tmpl.locked) {
    return;
  }
  creating.value = tmpl.slug;
  try {
    const res = await knotApi.instantiateTemplate(tmpl.slug);
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${res.workflow.id}`;
  } catch {
    // Errors surface elsewhere (Knot global handlers / network layer).
  } finally {
    creating.value = null;
  }
}
</script>

<template>
  <section class="k-space-y-4" role="region" :aria-label="t('marketplace.templateBlockTitle')">
    <header v-if="props.showHeader" class="k-flex k-items-center k-justify-between">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">
        {{ t('marketplace.templateBlockTitle') }}
      </h2>
    </header>

    <MarketplaceEmptyState
      v-if="templates.length === 0"
      :title="t('marketplace.templateBlockEmpty')"
      :action-label="t('marketplace.pricingCta')"
      :action-href="pricingBrowseHref"
    >
      <template #illustration>
        <Library :size="56" stroke-width="1.25" aria-hidden="true" />
      </template>
    </MarketplaceEmptyState>

    <div v-else class="k-grid k-gap-4 md:k-grid-cols-2">
      <article
        v-for="tmpl in templates"
        :key="tmpl.slug"
        class="km-card k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4 k-space-y-3 k-flex k-flex-col k-shadow-knot-sm k-cursor-pointer focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        role="link"
        tabindex="0"
        @click="props.mode === 'grid' ? ctx.navigate(`/template/${tmpl.slug}`) : undefined"
        @keydown.enter.prevent="props.mode === 'grid' ? ctx.navigate(`/template/${tmpl.slug}`) : undefined"
        @mouseenter="ctx.prefetchTemplateDetail?.(tmpl.slug)"
      >
        <header class="k-flex k-items-start k-justify-between k-gap-2">
          <div class="k-flex k-items-center k-gap-2">
            <div
              class="k-h-9 k-w-9 k-rounded-knot-sm k-flex k-items-center k-justify-center"
              :class="tmpl.locked ? 'k-bg-knot-warning-soft k-text-knot-warning' : 'k-bg-knot-primary-soft k-text-knot-primary'"
            >
              <Lock v-if="tmpl.locked" :size="16" />
              <Library v-else :size="16" />
            </div>
            <div>
              <p class="k-text-sm k-font-semibold k-text-knot-text">{{ tmpl.label }}</p>
              <p class="k-text-[11px] k-text-knot-text-soft">{{ tmpl.category }}</p>
            </div>
          </div>
          <span :class="['k-shrink-0 k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold', tierTone(tmpl.tier)]">{{ tmpl.tier }}</span>
        </header>

        <span :class="['k-self-start k-inline-flex k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold', categoryTone(tmpl.category)]">{{ tmpl.category }}</span>

        <p class="k-text-xs k-text-knot-text-muted">{{ tmpl.description }}</p>

        <WorkflowMiniPreview class="k-border k-border-knot-border k-rounded-knot-sm" :nodes="nodeEdgeCounts(tmpl).nodes" :edges="nodeEdgeCounts(tmpl).edges" />

        <a
          v-if="tmpl.locked && lockedCta(tmpl).href"
          :href="lockedCta(tmpl).href!"
          target="_blank"
          rel="noopener"
          class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-border"
        >
          <Lock :size="12" />
          {{ lockedCta(tmpl).label }}
          <ExternalLink :size="11" />
        </a>
        <button
          v-else-if="tmpl.locked && lockedCta(tmpl).inAppActivation"
          type="button"
          class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-border"
          @click="openLockedActivation(tmpl)"
        >
          <Lock :size="12" />
          {{ lockedCta(tmpl).label }}
        </button>
        <button
          v-else
          type="button"
          :disabled="creating === tmpl.slug"
          class="k-mt-auto k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-primary k-text-white disabled:k-opacity-60"
          @click.stop="useTemplateNow(tmpl)"
        >
          <Loader2 v-if="creating === tmpl.slug" class="k-animate-spin" :size="14" />
          {{ t('marketplace.useTemplate') }}
        </button>
      </article>
    </div>
    <LicenseActivationModal
      v-if="activationTarget"
      :open="!!activationTarget"
      :extension-id="activationTarget.id"
      :extension-label="activationTarget.label"
      @close="activationTarget = null"
      @activated="activationTarget = null"
    />
  </section>
</template>
