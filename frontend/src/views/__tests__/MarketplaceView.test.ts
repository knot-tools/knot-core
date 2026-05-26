/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import MarketplaceView from '../MarketplaceView.vue';
import MarketplaceUnavailable from '../marketplace/MarketplaceUnavailable.vue';

const marketplaceApi = vi.hoisted(() => vi.fn());

vi.mock('@/lib/api', () => ({
  knotApi: {
    marketplace: (...args: unknown[]) => marketplaceApi(...args),
    listBundledTemplates: vi.fn().mockResolvedValue([]),
    licenseStatus: vi.fn().mockResolvedValue({ extensions: [] }),
  },
}));

const marketplaceOk = {
  packs: [],
  templates: [],
  packsMeta: { fromCache: false, stale: false, error: null },
  templatesMeta: {
    fromCache: false,
    stale: false,
    refreshedAt: null,
    error: null,
  },
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
  editorial: {
    meta: { updatedAt: '2026-05-01T00:00:00.000Z' },
    sidebar: {},
  },
  backendUrl: 'https://license.example.com',
};

const messagesEn = {
  marketplace: {
    title: 'Marketplace',
    subtitle: 'Sub',
    bannerLead: 'Lead',
    catalogStalePacks: 'Stale packs',
    catalogStaleTemplates: 'Stale templates',
    refreshTitle: 'Refresh',
    refreshBusy: 'Busy',
    refreshIdle: 'Idle',
    ecosystemTitle: 'Ecosystem',
    ecosystemCore: 'Core text',
    ecosystemSite: 'Site',
    productBlockTitle: 'Products',
    productBlockEmpty: 'Empty',
    productBlockOpenDetail: 'Open',
    templateBlockTitle: 'Templates',
    templateBlockEmpty: 'Empty',
    miniPreviewLegend: '{nodes} nodes',
    miniPreviewHint: 'Hint',
    useTemplate: 'Use',
    bundledIntro: 'Intro',
    searchPack: 'Search',
    searchTemplate: 'Search',
    searchBundled: 'Search',
    allTiers: 'All',
    allCategories: 'All',
    tabPacks: 'Packs',
    tabTemplates: 'Templates',
    tabBundled: 'Bundled',
    tabMigration: 'Pro Pack migration',
    pricingCta: 'Pricing',
    searchPlaceholder: 'Search',
    loadFailed: 'Failed to load marketplace.',
    blockedTitle: 'Marketplace disabled',
    unavailableTitle: 'Marketplace unavailable',
    unavailableRetry: 'Try again',
    unavailableExternal: 'Pricing',
    ctaBlockButton: 'CTA',
    faqTitle: 'FAQ',
    faqEmpty: 'None',
    editorialUntitled: 'Untitled',
  },
};

function mountMarketplace(): ReturnType<typeof mount> {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: messagesEn },
  });

  return mount(MarketplaceView, {
    props: { workflowId: null, executionId: null },
    global: {
      plugins: [i18n],
      stubs: {
        BlockRenderer: { template: '<div class="block-renderer-stub">Marketplace</div>' },
        MarketplaceSkeleton: { template: '<div data-testid="skel">loading</div>' },
      },
    },
  });
}

describe('MarketplaceView', () => {
  beforeEach(() => {
    marketplaceApi.mockReset();
    marketplaceApi.mockResolvedValue(marketplaceOk);
    vi.stubGlobal(
      'localStorage',
      (() => {
        const store = new Map<string, string>();
        return {
          getItem: (k: string) => store.get(k) ?? null,
          setItem: (k: string, v: string) => {
            store.set(k, v);
          },
          removeItem: (k: string) => {
            store.delete(k);
          },
          clear: () => store.clear(),
        };
      })() as Storage,
    );
    document.documentElement.removeAttribute('data-marketplace-unread');
  });

  it('does not render a Migration Pro Pack tab', async () => {
    const wrapper = mountMarketplace();

    await vi.waitFor(() => {
      expect(wrapper.text()).toMatch(/Marketplace/i);
    });

    expect(wrapper.text()).not.toMatch(/Pro Pack migration/i);
    const tabLabels = wrapper.findAll('nav button').map((btn) => btn.text());
    expect(tabLabels.some((label) => /migration/i.test(label))).toBe(false);
  });

  it('renders MarketplaceUnavailable when the Marketplace API rejects', async () => {
    marketplaceApi.mockRejectedValueOnce(new Error('network down'));

    const wrapper = mountMarketplace();

    await flushPromises();
    await vi.waitFor(() => {
      expect(wrapper.findComponent(MarketplaceUnavailable).exists()).toBe(true);
    });

    expect(wrapper.text()).toMatch(/Marketplace unavailable/i);
    expect(wrapper.text()).toMatch(/network down/i);

    marketplaceApi.mockResolvedValue(marketplaceOk);
    wrapper.findComponent(MarketplaceUnavailable).vm.$emit('retry');

    await flushPromises();

    expect(marketplaceApi).toHaveBeenCalledTimes(2);
    expect(wrapper.findComponent(MarketplaceUnavailable).exists()).toBe(false);
  });
});
