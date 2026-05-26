/**
 * Local fuzzy search across marketplace packs and templates (no network).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed, type Ref } from 'vue';
import type { MarketplacePack, MarketplaceResponse, MarketplaceTemplate } from '../lib/api';

export interface MarketplaceSearchHit {
  kind: 'pack' | 'template';
  slug: string;
  label: string;
  description: string;
  tier: string;
  category: string;
  score: number;
  pack?: MarketplacePack;
  template?: MarketplaceTemplate;
}

function normalizeTerm(value: string): string {
  return value
    .normalize('NFD')
    .replace(/\p{M}/gu, '')
    .toLowerCase()
    .trim();
}

function fuzzyScore(needle: string, haystack: string): number {
  if (!needle) {
    return 0;
  }
  if (haystack === needle) {
    return 100;
  }
  if (haystack.startsWith(needle)) {
    return 80;
  }
  if (haystack.includes(needle)) {
    return 60;
  }

  let hi = 0;
  let score = 0;
  for (const ch of needle) {
    const idx = haystack.indexOf(ch, hi);
    if (idx === -1) {
      return 0;
    }
    score += Math.max(1, 20 - (idx - hi));
    hi = idx + 1;
  }
  return Math.min(40, score);
}

function packHaystack(pack: MarketplacePack): string {
  return normalizeTerm(
    [pack.slug, pack.label, pack.description ?? '', pack.category, pack.tier].join(' '),
  );
}

function templateHaystack(template: MarketplaceTemplate): string {
  return normalizeTerm(
    [template.slug, template.label, template.description, template.category, template.tier].join(
      ' ',
    ),
  );
}

export function useMarketplaceSearch(
  marketplace: Ref<MarketplaceResponse | null | undefined>,
  query: Ref<string>,
  limit = 24,
) {
  const normalizedQuery = computed(() => normalizeTerm(query.value));

  const hits = computed<MarketplaceSearchHit[]>(() => {
    const q = normalizedQuery.value;
    if (!q || q.length < 2) {
      return [];
    }

    const catalog = marketplace.value;
    if (!catalog) {
      return [];
    }

    const out: MarketplaceSearchHit[] = [];

    for (const pack of catalog.packs) {
      const score = fuzzyScore(q, packHaystack(pack));
      if (score <= 0) {
        continue;
      }
      out.push({
        kind: 'pack',
        slug: pack.slug,
        label: pack.label,
        description: pack.description ?? '',
        tier: pack.tier,
        category: pack.category,
        score,
        pack,
      });
    }

    for (const template of catalog.templates) {
      const score = fuzzyScore(q, templateHaystack(template));
      if (score <= 0) {
        continue;
      }
      out.push({
        kind: 'template',
        slug: template.slug,
        label: template.label,
        description: template.description,
        tier: template.tier,
        category: template.category,
        score,
        template,
      });
    }

    return out.sort((a, b) => b.score - a.score).slice(0, limit);
  });

  return { hits, normalizedQuery };
}
