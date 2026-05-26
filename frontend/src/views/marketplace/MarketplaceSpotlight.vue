<!--
  Spotlight card for a featured pack or template.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowRight } from 'lucide-vue-next';

import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';
import ProductEmblem from './ProductEmblem.vue';
import SignalBadge from './SignalBadge.vue';
import WorkflowMiniCanvas from './WorkflowMiniCanvas.vue';
import { knotMarketplaceBlockContextKey } from './contextKey';
import type { BlockContext } from './types';

interface SpotlightCta {
  label?: string;
  href?: string;
  kind?: string;
}

const props = withDefaults(
  defineProps<{
    slug?: string;
    kind?: 'pack' | 'template';
    title?: string;
    subtitle?: string;
    badge?: string;
    ctas?: SpotlightCta[];
    nodes?: number;
    edges?: number;
  }>(),
  { kind: 'pack', nodes: 5, edges: 4, ctas: () => [] },
);

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey) as BlockContext | undefined;

const resolved = computed(() => {
  const catalog = ctx?.marketplace.value;
  if (!catalog || !props.slug) {
    return null;
  }
  if (props.kind === 'template') {
    const template = catalog.templates.find((row) => row.slug === props.slug);
    if (!template) {
      return null;
    }
    const def = (template.definition ?? null) as Record<string, unknown> | null;
    const nodes = Array.isArray(def?.nodes) ? (def?.nodes as unknown[]).length : props.nodes;
    const edges = Array.isArray(def?.edges) ? (def?.edges as unknown[]).length : props.edges;
    return {
      slug: template.slug,
      title: props.title ?? template.label,
      subtitle: props.subtitle ?? template.description,
      tier: template.tier,
      path: `/template/${template.slug}`,
      nodes,
      edges,
    };
  }
  const pack = catalog.packs.find((row) => row.slug === props.slug);
  if (!pack) {
    return null;
  }
  return {
    slug: pack.slug,
    title: props.title ?? pack.label,
    subtitle: props.subtitle ?? pack.description ?? '',
    tier: pack.tier,
    path: `/product/${pack.slug}`,
    nodes: props.nodes,
    edges: props.edges,
  };
});

// Map tier to SignalBadge tone with WCAG AA-compliant contrast in both
// light and dark mode. Default 'info' was failing WCAG AA in light mode
// (#0ea5e9 on #f0f9ff = 2.60:1). See docs/design-system.md → Marketplace
// tier badge contrast for the full ratio table.
const tierTone = computed<'neutral' | 'info' | 'success' | 'warning' | 'premium'>(() => {
  const tier = (resolved.value?.tier ?? '').toLowerCase();
  if (tier === 'pro' || tier === 'enterprise') return 'premium';
  if (tier === 'migration') return 'success';
  if (tier === 'beta' || tier === 'new') return 'warning';
  if (tier === 'core' || tier === 'free') return 'info';
  return 'neutral';
});

const canvasAccent = computed<'primary' | 'pro' | 'migration' | 'enterprise'>(() => {
  const slug = (props.slug ?? '').toLowerCase();
  if (slug.includes('migration')) return 'migration';
  if (slug.includes('enterprise')) return 'enterprise';
  if (slug.includes('pro')) return 'pro';
  if (resolved.value?.tier === 'pro') return 'pro';
  if (resolved.value?.tier === 'enterprise') return 'enterprise';
  return 'primary';
});

const ctaButtons = computed(() => {
  // Primary CTA is always an internal route to the product/template page.
  // Editorial ctas (from license) can only target external knot.tools URLs
  // because the EditorialValidator only allows https:// to allow-listed hosts.
  // So we always inject the internal "View details" first, then up to 1
  // editorial CTA as secondary.
  const primary = {
    label: t('marketplace.spotlightCta'),
    href: resolved.value?.path ?? '',
  };
  const secondary = props.ctas[0];
  return secondary ? [primary, secondary] : [primary];
});

function navigateCta(href?: string): void {
  if (!href) {
    return;
  }
  trackMarketplaceEvent('spotlight.click', {
    slug: props.slug ?? '',
    href,
    kind: props.kind,
  });
  if (href.startsWith('#') || href.startsWith('/')) {
    ctx?.navigate(href);
    return;
  }
  window.open(href, '_blank', 'noopener,noreferrer');
}
</script>

<style scoped>
.km-spotlight {
  min-height: 240px;
  padding: 1.5rem;
}
.km-spotlight--pro {
  background: linear-gradient(135deg, rgba(124, 58, 237, 0.12), rgba(99, 102, 241, 0.06) 60%, var(--knot-color-surface, #fff));
}
.km-spotlight--migration {
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(20, 184, 166, 0.06) 60%, var(--knot-color-surface, #fff));
}
.km-spotlight--enterprise {
  background: linear-gradient(135deg, rgba(212, 160, 23, 0.14), rgba(245, 158, 11, 0.05) 60%, var(--knot-color-surface, #fff));
}
.km-spotlight--primary {
  background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(14, 165, 233, 0.05) 60%, var(--knot-color-surface, #fff));
}
</style>

<template>
  <article
    v-if="resolved"
    :class="[
      'km-spotlight km-hero-mesh km-card k-relative k-overflow-hidden k-grid k-gap-5 lg:k-grid-cols-[1.15fr_0.85fr]',
      `km-spotlight--${canvasAccent}`,
      `km-card--tier-${resolved.tier}`,
    ]"
  >
    <div class="k-relative k-z-[1] k-flex k-flex-col k-justify-between k-gap-4">
      <div class="k-space-y-3">
        <div class="k-flex k-items-center k-gap-2">
          <ProductEmblem :slug="resolved.slug" :size="40" />
          <SignalBadge v-if="badge || resolved.tier" :label="badge ?? resolved.tier" :tone="tierTone" />
        </div>
        <h2 class="k-text-2xl k-font-semibold k-leading-tight k-text-knot-text">
          {{ resolved.title }}
        </h2>
        <p class="k-text-sm k-text-knot-text-muted k-line-clamp-3">
          {{ resolved.subtitle }}
        </p>
      </div>
      <div class="k-flex k-flex-wrap k-gap-2">
        <button
          v-for="(cta, idx) in ctaButtons"
          :key="`${cta.label ?? 'cta'}-${idx}`"
          type="button"
          :class="[
            'k-inline-flex k-items-center k-gap-2 k-rounded-knot-pill k-px-4 k-py-2 k-text-sm k-font-semibold k-transition-shadow focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary',
            idx === 0
              ? 'k-bg-knot-primary k-text-white hover:k-shadow-knot-md'
              : 'k-bg-knot-surface k-text-knot-text k-border k-border-knot-border hover:k-bg-knot-surface-soft',
          ]"
          @click="navigateCta(cta.href ?? resolved.path)"
        >
          {{ cta.label ?? t('marketplace.spotlightCta') }}
          <ArrowRight v-if="idx === 0" :size="14" aria-hidden="true" />
        </button>
      </div>
    </div>
    <WorkflowMiniCanvas
      :nodes="resolved.nodes"
      :edges="resolved.edges"
      :accent="canvasAccent"
      :height="170"
      class="k-relative k-z-[1] k-self-stretch"
    />
  </article>
</template>
