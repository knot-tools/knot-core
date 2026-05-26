/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';
import type { Ref } from 'vue';

import type { MarketplaceResponse } from '../../lib/api';
import type { BlockContext } from '../types';
import BlockRenderer from '../BlockRenderer.vue';

const stubMarketplace: MarketplaceResponse = {
  packs: [],
  templates: [],
  packsMeta: { fromCache: false, stale: false, error: null },
  templatesMeta: { fromCache: false, stale: false, refreshedAt: null, error: null },
  migration: {
    migratedConnectorIds: [],
    impacted: [],
    summary: {
      impactedWorkflows: 0,
      impactedNodesTotal: 0,
      connectorIdsImpacted: [],
    },
    proPackProductSlug: 'knot-pro-pack',
  },
  backendUrl: 'https://license.example.com',
};

describe('BlockRenderer', () => {
  it('ignores unknown block types without throwing', async () => {
    const marketplace = ref<MarketplaceResponse | null>(stubMarketplace);
    const route: Ref<{ kind: 'home'; slug: null }> = ref({ kind: 'home', slug: null });

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            title: 'Marketplace',
            subtitle: 'Sub',
            bannerLead: 'Lead',
            pricingCta: 'Pricing',
            catalogStalePacks: 'Stale packs',
            catalogStaleTemplates: 'Stale templates',
            ecosystemTitle: 'Eco',
            ecosystemCore: 'Core',
            ecosystemSite: 'Site',
          },
        },
      },
    });

    const loading = ref(false);
    const refreshing = ref(false);
    const error = ref<string | null>(null);
    const marketplaceLoadBlockedCode = ref<string | null>(null);

    const ctx: BlockContext = {
      route,
      marketplace,
      loading,
      error,
      marketplaceLoadBlockedCode,
      refreshing,
      navigate: (): void => {
        //
      },
      reloadMarketplace: async (): Promise<void> => Promise.resolve(),
    };

    const wrapper = mount(BlockRenderer, {
      global: { plugins: [i18n] },
      props: {
        blocks: [
          { type: '__totally-unknown-block__' },
          { type: 'hero', id: 'test-hero' },
        ],
        context: ctx,
      },
    });

    await wrapper.vm.$nextTick();

    expect(wrapper.find('[data-editorial-type="__totally-unknown-block__"]').exists()).toBe(false);
    expect(wrapper.find('[data-editorial-type="hero"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Marketplace');
  });

  it('renders a banner block with marketplace context', async () => {
    const marketplace = ref<MarketplaceResponse | null>(stubMarketplace);
    const route = ref<{ kind: 'home'; slug: null }>({ kind: 'home', slug: null });
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            title: 'Marketplace',
            subtitle: 'Sub',
            bannerLead: 'Lead',
            pricingCta: 'Pricing',
            catalogStalePacks: 'Stale packs',
            catalogStaleTemplates: 'Stale templates',
            ecosystemTitle: 'Eco',
            ecosystemCore: 'Core',
            ecosystemSite: 'Site',
          },
        },
      },
    });

    const ctx: BlockContext = {
      route,
      marketplace,
      loading: ref(false),
      error: ref<string | null>(null),
      marketplaceLoadBlockedCode: ref<string | null>(null),
      refreshing: ref(false),
      navigate: (): void => {
        //
      },
      reloadMarketplace: async (): Promise<void> => Promise.resolve(),
    };

    const wrapper = mount(BlockRenderer, {
      global: { plugins: [i18n] },
      props: {
        blocks: [{ type: 'banner', id: 'b1', props: { showStale: false, showPricingCta: true } }],
        context: ctx,
      },
    });

    await wrapper.vm.$nextTick();
    expect(wrapper.find('[data-editorial-type="banner"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Lead');
    expect(wrapper.text()).toContain('Pricing');
  });

  it('does not evaluate script markup in editorial hero titles (XSS hardening)', async () => {
    const marketplace = ref<MarketplaceResponse | null>(stubMarketplace);
    const route = ref<{ kind: 'home'; slug: null }>({ kind: 'home', slug: null });
    const nastyTitle =
      '<img src=x onerror="window.__xmur__=true">evil<script>document.body.dataset.xss ="1"</script>';

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            title: 'Marketplace',
            subtitle: 'Sub',
            bannerLead: 'Lead',
            pricingCta: 'Pricing',
            catalogStalePacks: 'Stale packs',
            catalogStaleTemplates: 'Stale templates',
            ecosystemTitle: 'Eco',
            ecosystemCore: 'Core',
            ecosystemSite: 'Site',
            ctaBlockButton: 'Pricing',
          },
        },
      },
    });

    const ctx: BlockContext = {
      route,
      marketplace,
      loading: ref(false),
      error: ref<string | null>(null),
      marketplaceLoadBlockedCode: ref<string | null>(null),
      refreshing: ref(false),
      navigate: (): void => {
        //
      },
      reloadMarketplace: async (): Promise<void> => Promise.resolve(),
    };

    const wrapper = mount(BlockRenderer, {
      global: { plugins: [i18n] },
      props: {
        blocks: [{ type: 'hero', id: 'xss-hero', title: nastyTitle }],
        context: ctx,
      },
    });

    await wrapper.vm.$nextTick();

    const h1 = wrapper.find('[data-editorial-type="hero"] h1');
    expect(h1.exists()).toBe(true);
    expect(h1.text()).toContain('<script>');
    expect(document.querySelector('[data-editorial-type="hero"] h1 img')).toBeNull();
    expect(document.body.getAttribute('data-xss')).toBeNull();
  });
});
