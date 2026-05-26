/**
 * Resolve editorial `home.collections` rows into navigable catalog cards.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { MarketplacePack, MarketplaceResponse, MarketplaceTemplate } from './api';
import type { MarketplaceCollectionSpec } from '../views/marketplace/types';

export interface MarketplaceCollectionCard {
  slug: string;
  label: string;
  description: string;
  path: string;
  kind: 'pack' | 'template';
  tier?: string;
  category?: string;
  nodes?: number;
  edges?: number;
  locked?: boolean;
}

function packCard(pack: MarketplacePack): MarketplaceCollectionCard {
  return {
    slug: pack.slug,
    label: pack.label,
    description: pack.description ?? '',
    path: `/product/${pack.slug}`,
    kind: 'pack',
    tier: pack.tier,
    category: pack.category ?? undefined,
  };
}

function templateCard(template: MarketplaceTemplate): MarketplaceCollectionCard {
  const def = (template.definition ?? null) as Record<string, unknown> | null;
  const nodes = Array.isArray(def?.nodes) ? (def?.nodes as unknown[]).length : undefined;
  const edges = Array.isArray(def?.edges) ? (def?.edges as unknown[]).length : undefined;
  return {
    slug: template.slug,
    label: template.label,
    description: template.description,
    path: `/template/${template.slug}`,
    kind: 'template',
    tier: template.tier,
    category: template.category,
    nodes,
    edges,
    locked: template.locked ?? false,
  };
}

function popularityScore(row: { popular?: boolean; featured?: boolean; installCount?: number }): number {
  let score = row.installCount ?? 0;
  if (row.popular) {
    score += 1000;
  }
  if (row.featured) {
    score += 500;
  }
  return score;
}

function sortCatalogRows<T extends { slug: string; label?: string; popular?: boolean; featured?: boolean; installCount?: number }>(
  rows: T[],
  sort: string | undefined,
): T[] {
  const copy = [...rows];
  if (sort === 'popular') {
    return copy.sort((a, b) => {
      const delta = popularityScore(b) - popularityScore(a);
      return delta !== 0 ? delta : a.slug.localeCompare(b.slug);
    });
  }
  if (sort === 'recent') {
    return copy.sort((a, b) => b.slug.localeCompare(a.slug));
  }
  return copy.sort((a, b) => (a.label ?? a.slug).localeCompare(b.label ?? b.slug));
}

/**
 * Maps a collection spec (explicit slugs or query-driven) to cards from the live catalog.
 */
export function resolveCollectionCards(
  collection: MarketplaceCollectionSpec,
  catalog: MarketplaceResponse,
  maxItems = 12,
): MarketplaceCollectionCard[] {
  const limit = collection.limit ?? collection.query?.limit ?? maxItems;

  if (collection.packSlugs?.length || collection.templateSlugs?.length) {
    const out: MarketplaceCollectionCard[] = [];
    for (const slug of collection.packSlugs ?? []) {
      const pack = catalog.packs.find((row) => row.slug === slug);
      if (pack) {
        out.push(packCard(pack));
      }
    }
    for (const slug of collection.templateSlugs ?? []) {
      const template = catalog.templates.find((row) => row.slug === slug);
      if (template) {
        out.push(templateCard(template));
      }
    }
    return out.slice(0, limit);
  }

  const query = collection.query ?? {};
  const sort = query.sort;
  const category = query.category ?? query.kind;
  const includeTemplates =
    category === 'templates'
    || category === 'template'
    || collection.slug.includes('template');
  const includePacks =
    category === 'packs'
    || category === 'pack'
    || category === 'extension'
    || (!includeTemplates && category !== 'templates' && category !== 'template');

  const out: MarketplaceCollectionCard[] = [];

  if (includePacks) {
    for (const pack of sortCatalogRows(catalog.packs, sort)) {
      out.push(packCard(pack));
    }
  }
  if (includeTemplates) {
    for (const template of sortCatalogRows(catalog.templates, sort)) {
      out.push(templateCard(template));
    }
  }

  return out.slice(0, limit);
}
