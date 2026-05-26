/**
 * Read curated collections from `editorial.home.collections`.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed, type Ref } from 'vue';
import type { KnotMarketplaceEditorialDTO } from '../lib/api';
import type { MarketplaceCollectionSpec } from '../views/marketplace/types';

type HomeSection = KnotMarketplaceEditorialDTO['home'];

function homeObject(home: HomeSection | undefined): Record<string, unknown> | null {
  if (!home || Array.isArray(home)) {
    return null;
  }
  return home as Record<string, unknown>;
}

function parseLimit(value: unknown): number | undefined {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : undefined;
  }
  return undefined;
}

function normalizeCollection(raw: unknown): MarketplaceCollectionSpec | null {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  const row = raw as Record<string, unknown>;
  const slug = typeof row.slug === 'string' ? row.slug.trim() : '';
  if (!slug) {
    return null;
  }
  const queryRaw = row.query;
  let query: MarketplaceCollectionSpec['query'];
  if (queryRaw && typeof queryRaw === 'object' && !Array.isArray(queryRaw)) {
    const q = queryRaw as Record<string, unknown>;
    query = {
      sort: typeof q.sort === 'string' ? q.sort : undefined,
      limit: parseLimit(q.limit),
      kind: typeof q.kind === 'string' ? q.kind : undefined,
      category: typeof q.category === 'string' ? q.category : undefined,
    };
  }
  return {
    slug,
    title: typeof row.title === 'string' ? row.title : undefined,
    label: typeof row.label === 'string' ? row.label : undefined,
    subtitle: typeof row.subtitle === 'string' ? row.subtitle : undefined,
    description: typeof row.description === 'string' ? row.description : undefined,
    href: typeof row.href === 'string' ? row.href : undefined,
    badge: typeof row.badge === 'string' ? row.badge : undefined,
    limit: parseLimit(row.limit),
    query,
    packSlugs: Array.isArray(row.packSlugs)
      ? row.packSlugs.filter((s): s is string => typeof s === 'string')
      : undefined,
    templateSlugs: Array.isArray(row.templateSlugs)
      ? row.templateSlugs.filter((s): s is string => typeof s === 'string')
      : undefined,
  };
}

export function useMarketplaceCollections(
  editorial: Ref<KnotMarketplaceEditorialDTO | null | undefined>,
) {
  const collections = computed<MarketplaceCollectionSpec[]>(() => {
    const home = homeObject(editorial.value?.home);
    const raw = home?.collections;
    if (!Array.isArray(raw)) {
      return [];
    }
    return raw
      .map((entry) => normalizeCollection(entry))
      .filter((entry): entry is MarketplaceCollectionSpec => entry !== null);
  });

  function collectionBySlug(slug: string | null | undefined): MarketplaceCollectionSpec | null {
    if (!slug) {
      return null;
    }
    return collections.value.find((c) => c.slug === slug) ?? null;
  }

  return { collections, collectionBySlug };
}
