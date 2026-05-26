<!--
  Hero strip for Marketplace editorial pages (supports scheduled rotation via heroes[]).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Store } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';
import MarketplacePromoArt from '../MarketplacePromoArt.vue';

interface HeroPeriod {
  from?: string;
  until?: string;
}

interface HeroEntry {
  title?: string;
  subtitle?: string;
  period?: HeroPeriod;
  cta?: { label?: string; href?: string };
  bullets?: string[];
}

const props = withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    cta?: { label?: string; href?: string };
    bullets?: string[];
    heroes?: HeroEntry[];
    image?: { src?: string; alt?: string };
  }>(),
  { heroes: () => [], bullets: () => [] },
);

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const watermarkPatternId = useId();

function isActivePeriod(period?: HeroPeriod): boolean {
  if (!period) {
    return true;
  }
  const now = Date.now();
  if (period.from) {
    const fromMs = Date.parse(period.from);
    if (!Number.isNaN(fromMs) && now < fromMs) {
      return false;
    }
  }
  if (period.until) {
    const untilMs = Date.parse(period.until);
    if (!Number.isNaN(untilMs) && now > untilMs) {
      return false;
    }
  }
  return true;
}

const activeHero = computed<HeroEntry | null>(() => {
  const list = props.heroes ?? [];
  if (list.length === 0) {
    return null;
  }
  const active = list.find((h) => isActivePeriod(h.period));
  if (active) {
    return active;
  }
  const timeless = list.find((h) => !h.period?.from && !h.period?.until);
  return timeless ?? list[0] ?? null;
});

const titleResolved = computed(
  () => activeHero.value?.title ?? props.title ?? t('marketplace.title'),
);
const subtitleResolved = computed(
  () => activeHero.value?.subtitle ?? props.subtitle ?? t('marketplace.subtitle'),
);
const ctaResolved = computed(
  () => activeHero.value?.cta ?? props.cta ?? null,
);
const bulletsResolved = computed(
  () => activeHero.value?.bullets ?? props.bullets ?? [],
);

const imageFailed = ref(false);

const showRemoteImage = computed(
  () => !!(props.image?.src && !imageFailed.value),
);

function onImageError(): void {
  imageFailed.value = true;
}
</script>

<template>
  <header
    class="k-relative k-overflow-hidden k-flex k-flex-col lg:k-flex-row k-items-start k-gap-6 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-premium"
    role="banner"
  >
    <div class="knot-hero-watermark k-pointer-events-none" aria-hidden="true">
      <svg class="knot-hero-watermark__svg k-text-knot-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" fill="none">
        <defs>
          <pattern :id="watermarkPatternId" width="28" height="28" patternUnits="userSpaceOnUse" patternTransform="rotate(-18)">
            <circle cx="3" cy="3" r="2" fill="currentColor" opacity="0.35" />
            <circle cx="17" cy="15" r="1.2" fill="currentColor" opacity="0.22" />
            <path d="M8 20h10" stroke="currentColor" stroke-width="0.8" opacity="0.25" />
          </pattern>
        </defs>
        <rect width="100%" height="100%" :fill="'url(#' + watermarkPatternId + ')'" />
      </svg>
    </div>
    <div class="k-relative k-z-[1] k-flex k-flex-1 k-flex-col sm:k-flex-row k-items-start k-gap-4">
      <div class="k-h-12 k-w-12 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center k-shrink-0">
        <Store :size="22" aria-hidden="true" />
      </div>
      <div class="k-flex-1 k-space-y-3">
        <h1 class="k-text-2xl k-font-bold k-text-knot-text k-display-1">
          {{ titleResolved }}
        </h1>
        <p class="k-text-sm k-text-knot-text-muted">
          {{ subtitleResolved }}
        </p>
        <ul v-if="bulletsResolved.length" class="k-space-y-1 k-text-xs k-text-knot-text-muted">
          <li v-for="(line, i) in bulletsResolved" :key="i" class="k-flex k-items-start k-gap-2">
            <span class="k-text-knot-success k-font-bold" aria-hidden="true">✓</span>
            <span>{{ line }}</span>
          </li>
        </ul>
        <a
          v-if="sanitize(ctaResolved?.href)"
          :href="sanitize(ctaResolved?.href)"
          target="_blank"
          rel="noopener"
          class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-opacity-90"
        >
          {{ ctaResolved?.label ?? t('marketplace.ctaBlockButton') }}
        </a>
      </div>
    </div>
    <div
      class="k-relative k-z-[1] k-w-full lg:k-w-72 k-h-44 k-rounded-knot-md k-overflow-hidden k-border k-border-knot-border k-bg-knot-surface-soft k-shrink-0 k-p-2 k-shadow-knot-sm"
    >
      <img
        v-if="showRemoteImage"
        :src="props.image!.src"
        :alt="props.image!.alt ?? titleResolved"
        class="k-w-full k-h-full k-object-cover k-rounded-knot-sm"
        loading="lazy"
        @error="onImageError"
      />
      <MarketplacePromoArt v-else variant="hero" />
    </div>
  </header>
</template>
