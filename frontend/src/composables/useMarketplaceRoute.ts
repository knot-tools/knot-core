/**
 * Hash router for Knot Marketplace (`#/`, `#/product/slug`, …).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { onMounted, onUnmounted, readonly, ref, type DeepReadonly, type Ref } from 'vue';
import type { KnotMarketplaceEditorialDTO } from '../lib/api';

import type {
  MarketplaceQuery,
  MarketplaceRouteSnapshot,
} from '../views/marketplace/types';
import { MARKETPLACE_SLUG_PATTERN } from '../views/marketplace/types';

export type { MarketplaceRouteSnapshot, MarketplaceQuery } from '../views/marketplace/types';

export interface UseMarketplaceRouteOptions {
  /** Map normalized paths (`/segment/...`) → target paths (`/` or `/product/foo`). */
  redirects?: Record<string, string>;
  /** Editorial document from API (`redirects` merged under static `redirects`). */
  editorial?: Ref<KnotMarketplaceEditorialDTO | null | undefined>;
}

const QUERY_KEYS = ['q', 'tab', 'sort', 'category', 'tier', 'integration'] as const;

export function isValidMarketplaceSlug(segment: string | null | undefined): boolean {
  return typeof segment === 'string' && MARKETPLACE_SLUG_PATTERN.test(segment);
}

/**
 * `#/Foo/Bar?q=x` → `{ path: '/foo/bar', query: { q: 'x' } }`, `#/` → `{ path: '/', query: {} }`.
 */
export function splitMarketplaceHash(hash: string): { path: string; query: MarketplaceQuery } {
  let h = hash.trim();
  if (h.startsWith('#')) {
    h = h.slice(1).trim();
  }

  let pathPart = h;
  let queryPart = '';
  const qIdx = h.indexOf('?');
  if (qIdx >= 0) {
    pathPart = h.slice(0, qIdx);
    queryPart = h.slice(qIdx + 1);
  }

  const normalizedPath = normalizeMarketplaceHashPath(pathPart === '' ? '#/' : `#${pathPart}`);
  const query = parseMarketplaceQuery(queryPart);

  if (typeof window !== 'undefined' && window.location.search) {
    const pageParams = new URLSearchParams(window.location.search);
    for (const key of QUERY_KEYS) {
      const fromPage = pageParams.get(key);
      if (fromPage && !query[key]) {
        query[key] = fromPage;
      }
    }
  }

  return { path: normalizedPath, query };
}

export function parseMarketplaceQuery(raw: string): MarketplaceQuery {
  const query: MarketplaceQuery = {};
  if (!raw.trim()) {
    return query;
  }
  const params = new URLSearchParams(raw.startsWith('?') ? raw.slice(1) : raw);
  for (const key of QUERY_KEYS) {
    const value = params.get(key)?.trim();
    if (value) {
      query[key] = value;
    }
  }
  return query;
}

export function serializeMarketplaceQuery(query: MarketplaceQuery): string {
  const params = new URLSearchParams();
  for (const key of QUERY_KEYS) {
    const value = query[key]?.trim();
    if (value) {
      params.set(key, value);
    }
  }
  const serialized = params.toString();
  return serialized ? `?${serialized}` : '';
}

/**
 * `#/Foo/Bar` → `/foo/bar`, `#/` → `/`.
 */
export function normalizeMarketplaceHashPath(hash: string): string {
  let h = hash.trim();
  if (h.startsWith('#')) {
    h = h.slice(1).trim();
  }
  const qIdx = h.indexOf('?');
  if (qIdx >= 0) {
    h = h.slice(0, qIdx);
  }
  if (h === '' || h === '/') {
    return '/';
  }
  if (!h.startsWith('/')) {
    h = `/${h}`;
  }
  const segments = h.split('/').filter(Boolean).map((s) => s.toLowerCase());
  return segments.length === 0 ? '/' : `/${segments.join('/')}`;
}

export function hashFromNormalizedPath(
  normalizedPath: string,
  query: MarketplaceQuery = {},
): string {
  const qs = serializeMarketplaceQuery(query);
  if (normalizedPath === '/' || normalizedPath === '') {
    return `#/${qs}`;
  }
  const tail = normalizedPath.startsWith('/') ? normalizedPath.slice(1) : normalizedPath;
  return `#/${tail}${qs}`;
}

function editorialRedirectsToMap(
  redirects: KnotMarketplaceEditorialDTO['redirects'],
): Record<string, string> {
  if (!redirects) {
    return {};
  }
  if (Array.isArray(redirects)) {
    const out: Record<string, string> = {};
    for (const row of redirects) {
      const from = row.from?.trim();
      const to = row.to?.trim();
      if (!from || !to) {
        continue;
      }
      const view = row.view?.trim();
      const fromPath = view && view !== 'home'
        ? `/${view}/${from}`
        : from.startsWith('/') ? from : `/${from}`;
      const toPath = to.startsWith('/') || to.startsWith('#') ? to : `/${to}`;
      out[normalizeMarketplaceHashPath(fromPath.startsWith('#') ? fromPath : `#${fromPath}`)] =
        normalizeMarketplaceHashPath(toPath.startsWith('#') ? toPath : `#${toPath}`);
    }
    return out;
  }
  const out: Record<string, string> = {};
  for (const [from, to] of Object.entries(redirects)) {
    out[normalizeMarketplaceHashPath(from.startsWith('#') ? from : `#${from}`)] =
      normalizeMarketplaceHashPath(to.startsWith('#') ? to : `#${to}`);
  }
  return out;
}

function mergeRedirects(
  editorial: KnotMarketplaceEditorialDTO['redirects'],
  overrides: Record<string, string> | undefined,
): Record<string, string> {
  return { ...editorialRedirectsToMap(editorial), ...(overrides ?? {}) };
}

export function parseMarketplacePath(
  normalizedPath: string,
  query: MarketplaceQuery = {},
): MarketplaceRouteSnapshot | null {
  if (normalizedPath === '/') {
    return { kind: 'home', slug: null, query: { ...query } };
  }

  const segments = normalizedPath.split('/').filter(Boolean);
  const section = segments[0]!;

  if (segments.length === 1) {
    if (section === 'packs') {
      return { kind: 'packs', slug: null, query: { ...query } };
    }
    if (section === 'templates') {
      return { kind: 'templates', slug: null, query: { ...query } };
    }
    if (section === 'news') {
      return { kind: 'news', slug: null, query: { ...query } };
    }
    if (section === 'search') {
      return { kind: 'search', slug: null, query: { ...query } };
    }
    return null;
  }

  if (segments.length !== 2) {
    return null;
  }

  const sole = segments[1]!;
  if (!isValidMarketplaceSlug(sole)) {
    return null;
  }

  if (section === 'product') {
    return { kind: 'product', slug: sole, query: { ...query } };
  }
  if (section === 'template') {
    return { kind: 'template', slug: sole, query: { ...query } };
  }
  if (section === 'news') {
    return { kind: 'news', slug: sole, query: { ...query } };
  }
  if (section === 'category') {
    return { kind: 'category', slug: sole, query: { ...query } };
  }
  if (section === 'collection') {
    return { kind: 'collection', slug: sole, query: { ...query } };
  }

  return null;
}

function emitHash(normalizedTarget: string, query: MarketplaceQuery = {}): void {
  if (typeof window === 'undefined') return;
  window.location.hash = hashFromNormalizedPath(normalizedTarget, query);
}

/**
 * Parses `window.location.hash`, applies redirects (explicit + editorial),
 * and fixes invalid hashes by rewriting to `#/`.
 */
export function useMarketplaceRoute(options: UseMarketplaceRouteOptions = {}): {
  route: DeepReadonly<Ref<MarketplaceRouteSnapshot>>;
  navigate: (path: string) => void;
  syncFromWindow: () => void;
} {
  const route = ref<MarketplaceRouteSnapshot>({ kind: 'home', slug: null, query: {} });

  function mergedRedirects(): Record<string, string> {
    const ed = options.editorial?.value?.redirects;
    return mergeRedirects(ed ?? undefined, options.redirects ?? undefined);
  }

  function applyRedirect(norm: string): string | null {
    const table = mergedRedirects();
    const hit = table[norm];
    if (hit) {
      return normalizeMarketplaceHashPath(hit.startsWith('#') ? hit : `#${hit}`);
    }
    return null;
  }

  function syncFromWindow(): void {
    if (typeof window === 'undefined') return;

    const split = splitMarketplaceHash(window.location.hash || '#/');
    let norm = split.path;
    const query = split.query;

    let guard = 0;
    while (guard < 8) {
      const redirected = applyRedirect(norm);
      if (redirected === null || redirected === norm) {
        break;
      }
      norm = redirected;
      emitHash(norm, query);
      guard += 1;
    }

    const parsed = parseMarketplacePath(norm, query);
    if (!parsed) {
      if (norm !== '/') {
        emitHash('/', {});
      }
      route.value = { kind: 'home', slug: null, query: {} };
      return;
    }

    route.value = parsed;
  }

  function onHashChange(): void {
    syncFromWindow();
  }

  function navigate(path: string): void {
    const raw = path.trim();
    let pathPart = raw;
    let queryPart = '';
    const qIdx = raw.indexOf('?');
    if (qIdx >= 0) {
      pathPart = raw.slice(0, qIdx);
      queryPart = raw.slice(qIdx + 1);
    }

    const withHash = pathPart.startsWith('#') ? pathPart : `#/${pathPart.replace(/^\//, '')}`;
    let norm = normalizeMarketplaceHashPath(withHash);
    const query = parseMarketplaceQuery(queryPart);

    let guard = 0;
    while (guard < 8) {
      const redirected = applyRedirect(norm);
      if (redirected === null || redirected === norm) {
        break;
      }
      norm = redirected;
      guard += 1;
    }

    emitHash(norm, query);
    syncFromWindow();
  }

  onMounted(() => {
    syncFromWindow();
    window.addEventListener('hashchange', onHashChange);
  });

  onUnmounted(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('hashchange', onHashChange);
    }
  });

  return {
    route: readonly(route),
    navigate,
    syncFromWindow,
  };
}
