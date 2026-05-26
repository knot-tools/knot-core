<!--
  Template detail page — hero, workflow preview, tabs, CTA, related templates.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, onMounted, ref } from 'vue';
import { ExternalLink, Library, Loader2, Lock } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { knotApi } from '../../lib/api';
import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';
import { KNOWN_SKU_ENTERPRISE, KNOWN_SKU_PRO_PACK } from '../../lib/known-skus';
import {
  knotToolsLocalePrefix,
  lockedTemplateExternalHref,
} from '../../lib/marketplaceLinks';

import KProBadge from '../../components/ui/KProBadge.vue';
import LicenseActivationModal from '../../components/licensing/LicenseActivationModal.vue';
import BlockRenderer from './BlockRenderer.vue';
import MarketplaceRelatedRow from './MarketplaceRelatedRow.vue';
import ProductTabs from './ProductTabs.vue';
import WorkflowMiniPreview from './blocks/WorkflowMiniPreview.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';

const { t, locale } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('TemplateDetailLayout requires Knot marketplace editorial context.');
}

const creating = ref(false);
const activationTarget = ref<{ id: string; label: string } | null>(null);

const slug = computed(() => (ctx.route.value.kind === 'template' ? ctx.route.value.slug : null));

const tmpl = computed(() => {
  if (!slug.value) {
    return null;
  }
  return ctx.marketplace.value?.templates.find((row) => row.slug === slug.value) ?? null;
});

const pageSpec = computed(() => {
  const s = slug.value;
  if (!s) {
    return null;
  }
  return ctx.marketplace.value?.editorial?.templatePages?.[s] ?? null;
});

const legacyLayout = computed(() => pageSpec.value?.layout ?? []);

const useLegacyBlocks = computed(
  () => legacyLayout.value.length > 0 && !pageSpec.value?.tabs?.length,
);

const tabs = computed(() => {
  if (pageSpec.value?.tabs?.length) {
    return pageSpec.value.tabs;
  }
  return [
    {
      id: 'overview',
      kind: 'richtext' as const,
      label: t('marketplace.tabOverview'),
      visible: true,
      body: tmpl.value?.description ?? '',
    },
  ];
});

const localePrefix = computed(() => knotToolsLocalePrefix(locale.value));

const baseUrl = computed(
  () =>
    (typeof window !== 'undefined'
      && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL)
    || '',
);

function nodeEdgeCounts(): { nodes: number; edges: number } {
  const def = tmpl.value?.definition as Record<string, unknown> | null;
  return {
    nodes: (def?.nodes as unknown[] | undefined)?.length ?? 3,
    edges: (def?.edges as unknown[] | undefined)?.length ?? 2,
  };
}

function packSkuForTemplate(): string {
  return tmpl.value?.tier === 'enterprise' ? KNOWN_SKU_ENTERPRISE : KNOWN_SKU_PRO_PACK;
}

async function useTemplateNow(): Promise<void> {
  if (!tmpl.value || tmpl.value.locked) {
    return;
  }
  creating.value = true;
  try {
    const res = await knotApi.instantiateTemplate(tmpl.value.slug);
    trackMarketplaceEvent('template_instantiated', { slug: tmpl.value.slug });
    window.location.href = `${baseUrl.value}?mode=editor&workflow_id=${res.workflow.id}`;
  } finally {
    creating.value = false;
  }
}

onMounted(() => {
  if (slug.value) {
    trackMarketplaceEvent('product_page_visit', { slug: slug.value, kind: 'template' });
  }
});
</script>

<template>
  <div class="k-space-y-6">
    <template v-if="!tmpl && !useLegacyBlocks">
      <p class="k-text-sm k-text-knot-text-muted">{{ t('marketplace.templateBlockEmpty') }}</p>
    </template>

    <BlockRenderer v-else-if="useLegacyBlocks" :blocks="legacyLayout" :context="ctx" />

    <template v-else-if="tmpl">
      <header class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-space-y-4 k-shadow-knot-sm">
        <div class="k-flex k-items-start k-gap-4">
          <div
            class="k-h-14 k-w-14 k-rounded-knot-md k-flex k-items-center k-justify-center"
            :class="tmpl.locked ? 'k-bg-knot-warning-soft k-text-knot-warning' : 'k-bg-knot-accent-soft k-text-knot-accent'"
          >
            <Lock v-if="tmpl.locked" :size="22" />
            <Library v-else :size="22" />
          </div>
          <div class="k-min-w-0 k-flex-1">
            <div class="k-flex k-flex-wrap k-items-center k-gap-2">
              <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ tmpl.label }}</h1>
              <KProBadge
                v-if="tmpl.tier === 'pro' || tmpl.tier === 'enterprise'"
                :label="tmpl.tier === 'enterprise' ? t('marketplace.enterpriseBadgeLabel') : t('marketplace.proBadgeLabel')"
              />
              <span
                v-if="tmpl.popular"
                class="k-text-[10px] k-font-bold k-uppercase k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-accent-soft k-text-knot-accent"
              >
                {{ t('marketplace.signalPopular') }}
              </span>
            </div>
            <p class="k-mt-1 k-text-sm k-text-knot-text-muted">
              {{ pageSpec?.hero?.tagline ?? tmpl.description }}
            </p>
            <p class="k-mt-2 k-text-[11px] k-text-knot-text-soft">{{ tmpl.category }}</p>
          </div>
        </div>

        <WorkflowMiniPreview
          class="k-border k-border-knot-border k-rounded-knot-md"
          :nodes="nodeEdgeCounts().nodes"
          :edges="nodeEdgeCounts().edges"
        />

        <div class="k-flex k-flex-wrap k-gap-2">
          <a
            v-if="tmpl.locked && lockedTemplateExternalHref(tmpl.lockedReason?.status ?? 'unknown', packSkuForTemplate(), localePrefix)"
            :href="lockedTemplateExternalHref(tmpl.lockedReason?.status ?? 'unknown', packSkuForTemplate(), localePrefix)!"
            target="_blank"
            rel="noopener"
            class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-text-sm k-font-semibold k-border"
          >
            <Lock :size="14" />
            {{ t('marketplace.lockedCtaBuyProPack') }}
            <ExternalLink :size="12" />
          </a>
          <button
            v-else-if="!tmpl.locked"
            type="button"
            :disabled="creating"
            class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold disabled:k-opacity-60"
            @click="useTemplateNow"
          >
            <Loader2 v-if="creating" :size="14" class="k-animate-spin" />
            {{ t('marketplace.useTemplate') }}
          </button>
        </div>
      </header>

      <ProductTabs :tabs="tabs" :product-slug="slug" />

      <MarketplaceRelatedRow
        v-if="pageSpec?.related?.length"
        :title="t('marketplace.relatedTemplatesTitle')"
        :slugs="pageSpec.related"
        kind="template"
        preview-mode
      />
    </template>

    <LicenseActivationModal
      v-if="activationTarget"
      :open="!!activationTarget"
      :extension-id="activationTarget.id"
      :extension-label="activationTarget.label"
      @close="activationTarget = null"
      @activated="activationTarget = null"
    />
  </div>
</template>
