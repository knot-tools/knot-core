/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { createI18n } from 'vue-i18n';

import type { MarketplaceResponse } from '../../../lib/api';
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

/** CI runners vary; skip stress timing there — run locally only. */
const skipStressInCi = process.env.CI === 'true';

describe.skipIf(skipStressInCi)('Marketplace load stress (BlockRenderer)', () => {
  it('renders 100 lightweight blocks under 800ms', async () => {
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
            faqTitle: 'FAQ',
            faqEmpty: 'No items',
            editorialUntitled: 'Untitled',
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

    const blocks = Array.from({ length: 100 }, (_, idx) => ({
      type: 'faq' as const,
      id: `stress-faq-${idx}`,
    }));

    const t0 = typeof performance !== 'undefined' ? performance.now() : Date.now();
    const wrapper = mount(BlockRenderer, {
      global: { plugins: [i18n] },
      props: { blocks, context: ctx },
    });
    await wrapper.vm.$nextTick();
    await wrapper.vm.$nextTick();

    const t1 = typeof performance !== 'undefined' ? performance.now() : Date.now();

    expect(wrapper.findAll('[data-editorial-type="faq"]')).toHaveLength(100);
    expect(t1 - t0).toBeLessThan(800);
  });
});
