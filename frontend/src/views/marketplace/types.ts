/**
 * Marketplace editorial / block renderer types (Core P1a).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { Ref } from 'vue';
import type { KnotMarketplaceBlockSpecDTO, MarketplaceResponse } from '../../lib/api';

export interface MarketplaceDrawerTarget {
  kind: 'product' | 'template';
  slug: string;
}

export type BlockSpec = KnotMarketplaceBlockSpecDTO;

/** Slug/id segment aligned with Knot catalog slugs (`^[a-z0-9-]+$`). */
export const MARKETPLACE_SLUG_PATTERN = /^[a-z0-9-]+$/;

export type MarketplaceRouteKind =
  | 'home'
  | 'product'
  | 'template'
  | 'news'
  | 'packs'
  | 'templates'
  | 'category'
  | 'collection'
  | 'search';

/** Hash / search query filters synced with `#/…?q=&tab=&sort=` (and filter chips). */
export interface MarketplaceQuery {
  q?: string;
  tab?: string;
  sort?: string;
  category?: string;
  tier?: string;
  integration?: string;
}

export interface MarketplaceRouteSnapshot {
  kind: MarketplaceRouteKind;
  slug: string | null;
  query: MarketplaceQuery;
}

/** Curated row from `editorial.home.collections`. */
export interface MarketplaceCollectionSpec {
  slug: string;
  title?: string;
  label?: string;
  subtitle?: string;
  description?: string;
  packSlugs?: string[];
  templateSlugs?: string[];
  href?: string;
  badge?: string;
  limit?: number;
  query?: {
    sort?: string;
    limit?: number;
    kind?: string;
    category?: string;
  };
}

export interface BlockContext {
  /** Normalized hash router snapshot updated when `hashchange` fires. */
  route: Ref<MarketplaceRouteSnapshot>;
  marketplace: Ref<MarketplaceResponse | null>;
  loading: Ref<boolean>;
  error: Ref<string | null>;
  marketplaceLoadBlockedCode: Ref<string | null>;
  refreshing: Ref<boolean>;
  /** Push a path like `/product/foo` or `#/product/foo` — becomes `location.hash`. */
  navigate: (hashPath: string) => void;
  /** Reload marketplace envelope (supports admin refresh semantics). */
  reloadMarketplace: (opts?: { refresh?: boolean }) => Promise<void>;
  prefetchProductDetail?: (slug: string | null | undefined) => void;
  prefetchRouteFromHref?: (href?: string | null) => void;
  prefetchTemplateDetail?: (slug: string | null | undefined) => void;
  prefetchTemplateFromHref?: (href?: string | null) => void;
  openDrawerPreview?: (target: MarketplaceDrawerTarget) => void;
  trackEvent?: (event: string, context?: Record<string, string | number | boolean>) => void;
}
