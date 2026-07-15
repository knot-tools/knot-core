<!--
  Suite home — Knot Tools landing (brand surface, not Core dashboard).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Activity,
  ArrowRight,
  Boxes,
  HeartPulse,
  CloudDownload,
  Store,
  Workflow,
} from 'lucide-vue-next';
import { knotApi, type ExtensionLicenseStatus, type HealthSnapshot } from '../lib/api';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';
import { KNOWN_SKU_MIGRATION, KNOWN_SKU_PRO_PACK } from '../lib/known-skus';
import { knotToolsProductPage, knotToolsLocalePrefix } from '../lib/marketplaceLinks';
import KHero from '../components/ui/KHero.vue';

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t, locale } = useI18n();

const health = ref<HealthSnapshot | null>(null);
const extensions = ref<ExtensionLicenseStatus[]>([]);

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

function href(mode: string, hash = ''): string {
  return `${baseUrl.value}/workflows/preview.php?mode=${mode}${hash}`;
}

const marketplaceEnabled = computed(() => isMarketplaceUiEnabled());

const hasProPack = computed(() =>
  extensions.value.some((e) => e.id === KNOWN_SKU_PRO_PACK || e.id.includes('pro-pack')),
);

const hasMigration = computed(() =>
  extensions.value.some((e) => e.id === KNOWN_SKU_MIGRATION || e.id.includes('migration')),
);

const localePrefix = computed(() => knotToolsLocalePrefix(String(locale.value)));

const nextActions = computed(() => {
  const actions: Array<{
    key: string;
    label: string;
    href: string;
    external?: boolean;
    primary?: boolean;
    testid: string;
  }> = [
    {
      key: 'workflows',
      label: t('suiteHome.nextWorkflows'),
      href: href('workflows'),
      primary: true,
      testid: 'suite-home-next-workflows',
    },
  ];
  if (!hasProPack.value) {
    actions.push({
      key: 'install-pro',
      label: t('suiteHome.nextInstallPro'),
      href: marketplaceEnabled.value
        ? href('marketplace')
        : knotToolsProductPage(KNOWN_SKU_PRO_PACK, localePrefix.value),
      external: !marketplaceEnabled.value,
      testid: 'suite-home-next-install-pro',
    });
  } else {
    actions.push({
      key: 'open-pro',
      label: t('suiteHome.products.pro.cta'),
      href: href('pro-pack'),
      testid: 'suite-home-next-open-pro',
    });
  }
  if (hasMigration.value) {
    actions.push({
      key: 'open-migration',
      label: t('suiteHome.nextOpenMigration'),
      href: href('migration', '#/'),
      testid: 'suite-home-next-open-migration',
    });
  }
  return actions;
});

const products = computed(() => {
  const items = [
    {
      key: 'core',
      title: t('suiteHome.products.core.title'),
      lead: t('suiteHome.products.core.lead'),
      href: href('dashboard'),
      icon: Workflow,
      badge: 'CORE',
      cta: t('suiteHome.products.core.cta'),
    },
    {
      key: 'migration',
      title: t('suiteHome.products.migration.title'),
      lead: t('suiteHome.products.migration.lead'),
      href: hasMigration.value
        ? href('migration', '#/')
        : knotToolsProductPage(KNOWN_SKU_MIGRATION, localePrefix.value),
      icon: Boxes,
      badge: 'PRO',
      cta: hasMigration.value
        ? t('suiteHome.nextOpenMigration')
        : t('suiteHome.products.migration.cta'),
    },
    {
      key: 'pro',
      title: t('suiteHome.products.pro.title'),
      lead: t('suiteHome.products.pro.lead'),
      href: hasProPack.value
        ? href('pro-pack')
        : marketplaceEnabled.value
          ? href('marketplace')
          : knotToolsProductPage(KNOWN_SKU_PRO_PACK, localePrefix.value),
      icon: Store,
      badge: 'PRO',
      cta: hasProPack.value
        ? t('suiteHome.products.pro.cta')
        : t('suiteHome.nextInstallPro'),
    },
  ];
  if (marketplaceEnabled.value) {
    items.push({
      key: 'marketplace',
      title: t('suiteHome.products.marketplace.title'),
      lead: t('suiteHome.products.marketplace.lead'),
      href: href('marketplace'),
      icon: Store,
      badge: '',
      cta: t('suiteHome.products.marketplace.cta'),
    });
  }
  return items;
});

onMounted(async () => {
  try {
    const [h, lic] = await Promise.all([
      knotApi.health(),
      knotApi.licenseStatus().catch(() => ({ extensions: [] as ExtensionLicenseStatus[] })),
    ]);
    health.value = h;
    extensions.value = lic.extensions ?? [];
  } catch {
    health.value = null;
    extensions.value = [];
  }
});
</script>

<template>
  <div
    class="suite-home k-flex k-flex-col k-gap-8 k-p-4 md:k-p-6 k-max-w-[1100px] k-mx-auto"
    data-testid="suite-home"
  >
    <KHero
      variant="large"
      :pill="t('suiteHome.eyebrow')"
      :title="t('suiteHome.title')"
      :highlight="t('suiteHome.titleHighlight')"
      :subtitle="t('suiteHome.lead')"
    >
      <template #actions>
        <a
          :href="href('dashboard')"
          class="k-inline-flex k-items-center k-gap-2 k-px-5 k-py-2.5 k-rounded-knot-pill k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-no-underline k-shadow-[0_8px_24px_rgba(99,102,241,0.35)] hover:k-shadow-[0_12px_32px_rgba(99,102,241,0.5)] k-transition-shadow"
          data-testid="suite-home-cta-core"
        >
          <Workflow :size="15" />
          {{ t('suiteHome.ctaCore') }}
        </a>
        <a
          :href="href('suite-health')"
          class="k-inline-flex k-items-center k-gap-2 k-px-5 k-py-2.5 k-rounded-knot-pill k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold k-no-underline hover:k-border-knot-primary hover:k-text-knot-primary k-transition-colors"
          data-testid="suite-home-cta-health"
        >
          <HeartPulse :size="15" />
          {{ t('suiteHome.ctaHealth') }}
        </a>
      </template>
      <template #right>
        <p
          v-if="health?.version"
          class="k-m-0 k-px-2.5 k-py-1 k-rounded-knot-sm k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-[11px] k-font-semibold k-text-knot-text-muted"
        >
          {{ t('suiteHome.coreVersion', { version: health.version }) }}
        </p>
      </template>
    </KHero>

    <section
      aria-labelledby="suite-home-next-heading"
      class="k-flex k-flex-col k-gap-3"
      data-testid="suite-home-next-actions"
    >
      <h2
        id="suite-home-next-heading"
        class="k-m-0 k-text-sm k-font-bold k-uppercase k-tracking-wider k-text-knot-text-muted"
      >
        {{ t('suiteHome.nextHeading') }}
      </h2>
      <div class="k-flex k-flex-wrap k-items-center k-gap-2">
        <a
          v-for="action in nextActions"
          :key="action.key"
          :href="action.href"
          :target="action.external ? '_blank' : undefined"
          :rel="action.external ? 'noopener noreferrer' : undefined"
          class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-pill k-text-sm k-font-semibold k-no-underline k-transition-colors"
          :class="action.primary
            ? 'k-bg-knot-primary k-text-white hover:k-bg-knot-primary-strong'
            : 'k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text hover:k-border-knot-primary hover:k-text-knot-primary'"
          :data-testid="action.testid"
        >
          {{ action.label }}
          <ArrowRight :size="14" />
        </a>
      </div>
    </section>

    <section aria-labelledby="suite-home-products-heading" class="k-flex k-flex-col k-gap-4">
      <div class="k-flex k-flex-col k-gap-1">
        <h2
          id="suite-home-products-heading"
          class="k-m-0 k-text-sm k-font-bold k-uppercase k-tracking-wider k-text-knot-text-muted"
        >
          {{ t('suiteHome.productsHeading') }}
        </h2>
        <p class="k-m-0 k-text-sm k-text-knot-text-soft">
          {{ t('suiteHome.productsLead') }}
        </p>
      </div>

      <div class="k-grid k-grid-cols-1 md:k-grid-cols-2 k-gap-4">
        <a
          v-for="(product, index) in products"
          :key="product.key"
          :href="product.href"
          class="suite-home__card k-group k-relative k-flex k-flex-col k-gap-4 k-p-6 k-rounded-knot-lg k-border k-border-knot-border k-bg-knot-surface k-no-underline k-shadow-knot-sm hover:k-border-knot-primary hover:k-shadow-knot-md k-transition-all"
          :style="{ animationDelay: `${80 + index * 60}ms` }"
          :data-testid="`suite-home-card-${product.key}`"
        >
          <div class="k-flex k-items-start k-justify-between k-gap-3">
            <span
              class="k-inline-flex k-items-center k-justify-center k-w-11 k-h-11 k-rounded-knot-md k-bg-knot-primary-soft k-text-knot-primary group-hover:k-scale-105 k-transition-transform"
            >
              <component :is="product.icon" :size="20" />
            </span>
            <span
              v-if="product.badge"
              class="k-px-2 k-py-0.5 k-rounded-knot-sm k-text-[10px] k-font-bold k-uppercase k-tracking-wide"
              :class="product.badge === 'CORE'
                ? 'k-bg-knot-warning-soft k-text-knot-warning'
                : 'k-bg-knot-premium-gold-soft k-text-knot-premium-gold-strong'"
            >
              {{ product.badge }}
            </span>
          </div>

          <div class="k-flex k-flex-col k-gap-1.5 k-flex-1">
            <h3 class="k-m-0 k-text-lg k-font-bold k-text-knot-text k-tracking-tight">
              {{ product.title }}
            </h3>
            <p class="k-m-0 k-text-sm k-leading-relaxed k-text-knot-text-muted">
              {{ product.lead }}
            </p>
          </div>

          <span
            class="k-inline-flex k-items-center k-gap-1.5 k-text-sm k-font-semibold k-text-knot-primary"
          >
            {{ product.cta }}
            <ArrowRight
              :size="15"
              class="group-hover:k-translate-x-1 k-transition-transform"
            />
          </span>
        </a>
      </div>
    </section>

    <nav
      class="k-flex k-flex-wrap k-items-center k-gap-x-5 k-gap-y-2 k-pt-1 k-border-t k-border-knot-border"
      :aria-label="t('suiteHome.utilityNav')"
    >
      <a
        :href="href('suite-health')"
        class="k-inline-flex k-items-center k-gap-1.5 k-text-sm k-font-medium k-text-knot-text-muted k-no-underline hover:k-text-knot-primary k-transition-colors"
        data-testid="suite-home-link-health"
      >
        <HeartPulse :size="14" />
        {{ t('suiteHome.links.health') }}
      </a>
      <a
        :href="href('updates')"
        class="k-inline-flex k-items-center k-gap-1.5 k-text-sm k-font-medium k-text-knot-text-muted k-no-underline hover:k-text-knot-primary k-transition-colors"
        data-testid="suite-home-link-updates"
      >
        <CloudDownload :size="14" />
        {{ t('suiteHome.links.updates') }}
      </a>
      <a
        :href="href('dashboard')"
        class="k-inline-flex k-items-center k-gap-1.5 k-text-sm k-font-medium k-text-knot-text-muted k-no-underline hover:k-text-knot-primary k-transition-colors"
        data-testid="suite-home-link-core-dashboard"
      >
        <Activity :size="14" />
        {{ t('suiteHome.links.coreDashboard') }}
      </a>
    </nav>
  </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
  .suite-home__card {
    animation: suite-home-rise 420ms cubic-bezier(0.22, 1, 0.36, 1) both;
  }
}

@keyframes suite-home-rise {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
