/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

import type { MarketplaceResponse } from '../../lib/api';
import type { BlockContext } from '../../types';
import { knotMarketplaceBlockContextKey } from '../../contextKey';
import BannerBlock from '../BannerBlock.vue';

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

function marketMessages(): Record<string, string> {
  return {
    title: 'Marketplace',
    subtitle: 'Sub',
    bannerLead: 'Lead',
    pricingCta: 'Pricing',
    catalogStalePacks: 'Stale packs',
    catalogStaleTemplates: 'Stale templates',
    ecosystemTitle: 'Eco',
    ecosystemCore: 'Core',
    ecosystemSite: 'Site',
  };
}

describe('BannerBlock', () => {
  it('renders pricing CTA and reacts to stale flags from context', async () => {
    const marketplace = ref<MarketplaceResponse | null>({
      ...stubMarketplace,
      packsMeta: { fromCache: false, stale: true, error: null },
      templatesMeta: { fromCache: false, stale: true, refreshedAt: null, error: null },
    });

    const route = ref<{ kind: 'home'; slug: null }>({ kind: 'home', slug: null });
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

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { marketplace: marketMessages() } },
    });

    const wrapper = mount(BannerBlock, {
      global: {
        plugins: [i18n],
        provide: {
          [knotMarketplaceBlockContextKey]: ctx,
        },
      },
      props: { showStale: true, showPricingCta: true },
    });

    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('Lead');
    expect(wrapper.text()).toContain('Pricing');
    expect(wrapper.text()).toContain('Stale packs');
    expect(wrapper.text()).toContain('Stale templates');
  });

  it('shows bundled promo art for editorial strip without remote image', async () => {
    const marketplace = ref<MarketplaceResponse | null>(stubMarketplace);
    const route = ref<{ kind: 'home'; slug: null }>({ kind: 'home', slug: null });
    const ctx: BlockContext = {
      route,
      marketplace,
      loading: ref(false),
      error: ref(null),
      marketplaceLoadBlockedCode: ref(null),
      refreshing: ref(false),
      navigate: (): void => {},
      reloadMarketplace: async (): Promise<void> => {},
    };

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { marketplace: marketMessages() } },
    });

    const wrapper = mount(BannerBlock, {
      global: {
        plugins: [i18n],
        provide: { [knotMarketplaceBlockContextKey]: ctx },
      },
      props: {
        kicker: 'Knot Marketplace',
        title: 'Verified extensions',
        body: 'Premium packs inside Dolibarr.',
        cta: { label: 'Pricing', href: 'https://knot.tools/pricing' },
        showStale: true,
        showPricingCta: true,
      },
    });

    expect(wrapper.find('.marketplace-promo-art').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('Lead');
  });
});
