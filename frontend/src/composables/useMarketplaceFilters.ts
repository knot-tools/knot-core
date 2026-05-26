/**
 * Marketplace listing filters (category, tier, integration) synced to the hash query.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed, type ComputedRef, type Ref } from 'vue';
import type { MarketplacePack, MarketplaceResponse, MarketplaceTemplate } from '../lib/api';
import type { MarketplaceQuery } from '../views/marketplace/types';

export interface MarketplaceFilterState {
  category: string;
  tier: string;
  integration: string;
}

export interface UseMarketplaceFiltersOptions {
  query: Ref<MarketplaceQuery> | ComputedRef<MarketplaceQuery>;
  navigate: (path: string) => void;
  routePath: Ref<string> | ComputedRef<string>;
}

function readFilterState(query: MarketplaceQuery): MarketplaceFilterState {
  return {
    category: query.category?.trim() ?? '',
    tier: query.tier?.trim() ?? '',
    integration: query.integration?.trim() ?? '',
  };
}

function buildQueryString(filters: MarketplaceFilterState, base: MarketplaceQuery): string {
  const params = new URLSearchParams();
  const q = base.q?.trim();
  const tab = base.tab?.trim();
  const sort = base.sort?.trim();
  if (q) {
    params.set('q', q);
  }
  if (tab) {
    params.set('tab', tab);
  }
  if (sort) {
    params.set('sort', sort);
  }
  if (filters.category) {
    params.set('category', filters.category);
  }
  if (filters.tier) {
    params.set('tier', filters.tier);
  }
  if (filters.integration) {
    params.set('integration', filters.integration);
  }
  const serialized = params.toString();
  return serialized ? `?${serialized}` : '';
}

export function useMarketplaceFilters(options: UseMarketplaceFiltersOptions) {
  const filters = computed(() => readFilterState(options.query.value));

  function syncHash(next: Partial<MarketplaceFilterState>): void {
    const merged: MarketplaceFilterState = {
      ...filters.value,
      ...next,
    };
    const qs = buildQueryString(merged, options.query.value);
    options.navigate(`${options.routePath.value}${qs}`);
  }

  function clearFilters(): void {
    syncHash({ category: '', tier: '', integration: '' });
  }

  function applyPackFilters(packs: MarketplacePack[]): MarketplacePack[] {
    const { category, tier, integration } = filters.value;
    return packs.filter((pack) => {
      if (category && pack.category !== category) {
        return false;
      }
      if (tier && pack.tier !== tier) {
        return false;
      }
      if (integration && pack.slug !== integration && pack.category !== integration) {
        return false;
      }
      return true;
    });
  }

  function applyTemplateFilters(templates: MarketplaceTemplate[]): MarketplaceTemplate[] {
    const { category, tier, integration } = filters.value;
    return templates.filter((template) => {
      if (category && template.category !== category) {
        return false;
      }
      if (tier && template.tier !== tier) {
        return false;
      }
      if (integration && template.slug !== integration) {
        return false;
      }
      return true;
    });
  }

  function categoryOptions(marketplace: MarketplaceResponse | null | undefined): string[] {
    if (!marketplace) {
      return [];
    }
    const set = new Set<string>();
    for (const pack of marketplace.packs) {
      if (pack.category) {
        set.add(pack.category);
      }
    }
    for (const template of marketplace.templates) {
      if (template.category) {
        set.add(template.category);
      }
    }
    return [...set].sort((a, b) => a.localeCompare(b));
  }

  function tierOptions(marketplace: MarketplaceResponse | null | undefined): string[] {
    if (!marketplace) {
      return [];
    }
    const set = new Set<string>();
    for (const pack of marketplace.packs) {
      set.add(pack.tier);
    }
    for (const template of marketplace.templates) {
      set.add(template.tier);
    }
    return [...set].sort((a, b) => a.localeCompare(b));
  }

  return {
    filters,
    syncHash,
    clearFilters,
    applyPackFilters,
    applyTemplateFilters,
    categoryOptions,
    tierOptions,
  };
}
