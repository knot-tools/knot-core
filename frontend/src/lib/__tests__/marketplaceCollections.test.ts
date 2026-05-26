/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';

import type { MarketplaceResponse } from '../api';
import { resolveCollectionCards } from '../marketplaceCollections';
import type { MarketplaceCollectionSpec } from '../../views/marketplace/types';

const catalog = {
  packs: [
    {
      slug: 'knot-pro-pack',
      label: 'Knot Pro Pack',
      description: 'Pro connectors',
      tier: 'pro',
      category: 'pro',
      popular: true,
      installCount: 42,
    },
    {
      slug: 'knot-migration',
      label: 'Knot Migration',
      description: 'Upgrade helper',
      tier: 'pro',
      category: 'migration',
      installCount: 3,
    },
  ],
  templates: [
    {
      slug: 'invoice-reminder',
      label: 'Invoice reminder',
      description: 'Billing workflow',
      tier: 'free',
      category: 'billing',
      featured: true,
    },
    {
      slug: 'stripe-sync',
      label: 'Stripe sync',
      description: 'Payments',
      tier: 'pro',
      category: 'billing',
    },
  ],
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
  backendUrl: 'https://license.knot.tools',
  editorial: null,
} satisfies MarketplaceResponse;

describe('resolveCollectionCards', () => {
  it('resolves explicit pack and template slugs', () => {
    const collection: MarketplaceCollectionSpec = {
      slug: 'featured',
      packSlugs: ['knot-pro-pack'],
      templateSlugs: ['invoice-reminder'],
    };
    const cards = resolveCollectionCards(collection, catalog);
    expect(cards).toHaveLength(2);
    expect(cards[0]?.path).toBe('/product/knot-pro-pack');
    expect(cards[1]?.path).toBe('/template/invoice-reminder');
  });

  it('resolves query-driven template collections', () => {
    const collection: MarketplaceCollectionSpec = {
      slug: 'recent-templates',
      label: 'Recent templates',
      query: { sort: 'recent', limit: 1 },
      limit: 1,
    };
    const cards = resolveCollectionCards(collection, catalog);
    expect(cards).toHaveLength(1);
    expect(cards[0]?.kind).toBe('template');
  });

  it('sorts popular packs for pro picks', () => {
    const collection: MarketplaceCollectionSpec = {
      slug: 'pro-picks',
      label: 'Pro picks',
      query: { sort: 'popular', category: 'packs' },
      limit: 2,
    };
    const cards = resolveCollectionCards(collection, catalog);
    expect(cards[0]?.slug).toBe('knot-pro-pack');
  });
});
