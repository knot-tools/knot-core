<!--
  Marketplace banner — editorial promo strip and/or catalog health hints.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, ExternalLink, Sparkles } from 'lucide-vue-next';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';
import MarketplacePromoArt from '../MarketplacePromoArt.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

const props = withDefaults(
  defineProps<{
    kicker?: string;
    title?: string;
    body?: string;
    cta?: { label?: string; href?: string };
    image?: { src?: string; alt?: string };
    /** Surface cached catalog stale flags from `/api/marketplace.php`. */
    showStale?: boolean;
    showPricingCta?: boolean;
  }>(),
  { showStale: true, showPricingCta: true },
);

const { t } = useI18n();
const { sanitize, localePrefix } = useSanitizedMarketplaceHref();

const ctx = inject(knotMarketplaceBlockContextKey);
if (!ctx) {
  throw new Error('BannerBlock requires Knot marketplace editorial context.');
}

const catalog = computed(() => ctx.marketplace.value);
const imageFailed = ref(false);

const stalePacks = computed(
  () => !!(props.showStale && catalog.value?.packsMeta?.stale),
);

const staleTemplates = computed(
  () => !!(props.showStale && catalog.value?.templatesMeta?.stale),
);

const tmplError = computed(() => catalog.value?.templatesMeta?.error ?? '');

const isEditorialPromo = computed(
  () => !!(props.title || props.body || props.kicker),
);

const showRemoteImage = computed(
  () => !!(props.image?.src && !imageFailed.value),
);

const showCatalogFooter = computed(
  () =>
    stalePacks.value
    || staleTemplates.value
    || !!(props.showStale && tmplError.value)
    || ((!isEditorialPromo.value || !props.cta?.href) && (props.showStale || props.showPricingCta)),
);

const showLeadRow = computed(
  () => (props.showStale || props.showPricingCta) && (!isEditorialPromo.value || !props.cta?.href),
);

function onImageError(): void {
  imageFailed.value = true;
}
</script>

<template>
  <div class="k-space-y-3">
    <article
      v-if="isEditorialPromo"
      class="k-relative k-overflow-hidden k-rounded-knot-lg k-border k-border-knot-color-premium-gold k-bg-gradient-to-br k-from-knot-color-premium-gold-soft k-via-knot-surface k-to-knot-surface k-p-6 k-flex k-flex-col md:k-flex-row k-gap-5 k-shadow-knot-premium"
      role="region"
      :aria-label="props.title ?? props.kicker ?? 'Marketplace'"
    >
      <div
        class="k-pointer-events-none k-absolute k-inset-0 k-opacity-[0.35]"
        aria-hidden="true"
        style="background: var(--knot-gradient-premium-subtle)"
      />

      <div class="k-relative k-flex-1 k-space-y-3 k-min-w-0">
        <p
          v-if="props.kicker"
          class="k-inline-flex k-items-center k-gap-1.5 k-text-[11px] k-font-bold k-uppercase k-tracking-wide k-text-knot-color-premium-gold-strong"
        >
          <Sparkles :size="12" aria-hidden="true" />
          {{ props.kicker }}
        </p>
        <h2 v-if="props.title" class="k-text-xl k-font-bold k-text-knot-text k-display-2">
          {{ props.title }}
        </h2>
        <p v-if="props.body" class="k-text-sm k-text-knot-text-muted k-max-w-prose">
          {{ props.body }}
        </p>
        <a
          v-if="sanitize(props.cta?.href)"
          :href="sanitize(props.cta?.href)"
          target="_blank"
          rel="noopener"
          class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-opacity-95 focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        >
          {{ props.cta?.label ?? t('marketplace.pricingCta') }}
          <ExternalLink :size="14" aria-hidden="true" />
        </a>
      </div>

      <div
        class="k-relative k-w-full md:k-w-52 lg:k-w-60 k-h-32 md:k-h-36 k-shrink-0 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-2 k-shadow-knot-sm"
        :aria-label="props.image?.alt ?? undefined"
        :role="showRemoteImage ? undefined : 'presentation'"
      >
        <img
          v-if="showRemoteImage"
          :src="props.image!.src"
          :alt="props.image!.alt ?? props.title ?? ''"
          class="k-w-full k-h-full k-object-cover k-rounded-knot-sm"
          loading="lazy"
          @error="onImageError"
        />
        <MarketplacePromoArt v-else variant="banner" />
      </div>
    </article>

    <div v-if="showCatalogFooter" class="k-space-y-2">
      <div
        v-if="showLeadRow"
        class="k-flex k-flex-wrap k-items-center k-justify-between k-gap-3"
      >
        <p class="k-text-sm k-text-knot-text-muted">
          {{ t('marketplace.bannerLead') }}
        </p>
        <a
          :href="`https://knot.tools${localePrefix}/pricing/`"
          target="_blank"
          rel="noopener"
          class="k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
        >
          {{ t('marketplace.pricingCta') }}
          <ExternalLink :size="11" aria-hidden="true" />
        </a>
      </div>

      <div v-if="stalePacks" class="k-bg-knot-warning-soft k-text-knot-warning k-px-3 k-py-2 k-rounded-knot-sm k-text-xs k-flex k-items-start k-gap-2">
        <AlertTriangle :size="14" class="k-mt-0.5 k-shrink-0" aria-hidden="true" />
        <span>{{ t('marketplace.catalogStalePacks') }}</span>
      </div>

      <div v-if="staleTemplates" class="k-bg-knot-warning-soft k-text-knot-warning k-px-3 k-py-2 k-rounded-knot-sm k-text-xs k-flex k-items-start k-gap-2">
        <AlertTriangle :size="14" class="k-mt-0.5 k-shrink-0" aria-hidden="true" />
        <span>{{ t('marketplace.catalogStaleTemplates') }}</span>
      </div>

      <p v-if="tmplError && props.showStale" class="k-text-[11px] k-text-knot-text-soft">{{ tmplError }}</p>
    </div>
  </div>
</template>
