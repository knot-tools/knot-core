/**
 * Warm editorial assets adjacent to Marketplace routes without extra API calls.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { type ComputedRef } from 'vue';

import type { KnotMarketplaceBlockSpecDTO, KnotMarketplaceEditorialDTO } from '../lib/api';

function collectHttpsImageHints(layout?: Record<string, unknown>[]): string[] {
  if (!layout?.length) {
    return [];
  }
  const urls: string[] = [];
  for (const blk of layout) {
    const image = blk?.image as { src?: string } | undefined;
    const src = image?.src;
    if (typeof src === 'string' && /^https:\/\//i.test(src)) {
      urls.push(src);
    }
  }
  return urls.slice(0, 8);
}

export function useMarketplacePrefetch(editorial: ComputedRef<KnotMarketplaceEditorialDTO | null | undefined>) {
  const warmedLayouts = new Set<string>();

  function warmLayoutImages(slug: string | null | undefined, kind: 'product' | 'template'): void {
    const safe = slug?.trim();
    if (!safe) {
      return;
    }
    const key = `${kind}:${safe}`;
    if (warmedLayouts.has(key)) {
      return;
    }
    const root = editorial.value;
    if (!root) {
      return;
    }

    let layoutUnknown: KnotMarketplaceBlockSpecDTO[] | undefined;
    if (kind === 'product') {
      layoutUnknown = root.productPages?.[safe]?.layout;
    } else {
      layoutUnknown = root.templatePages?.[safe]?.layout;
    }
    const layout = layoutUnknown as Record<string, unknown>[] | undefined;
    if (!layout?.length) {
      return;
    }

    warmedLayouts.add(key);

    const hints = collectHttpsImageHints(layout);
    const win = typeof window !== 'undefined' ? window : undefined;

    /**
     * `Image` prefetch keeps the CDN connection warm ahead of navigating into
     * the detail chrome while the SPA still serves the aggregated marketplace payload.
     */
    hints.forEach((src) => {
      if (!win) {
        return;
      }

      try {
        const img = new win.Image();

        img.decoding = 'async';
        img.referrerPolicy = 'no-referrer';
        img.src = src;
      } catch {
        // Ignore warmup failures — prefetch is purely opportunistic.
      }
    });
  }

  function warmProductDetail(slug: string | undefined | null): void {
    warmLayoutImages(slug, 'product');
  }

  function warmRouteFromHref(href: string | undefined | null): void {
    if (!href?.includes('#/product/')) {
      return;
    }
    const segment = href.split('#/product/')[1]?.split(/[?#]/)[0];
    warmProductDetail(segment);
  }

  function warmTemplateDetail(slug: string | undefined | null): void {
    warmLayoutImages(slug, 'template');
  }

  function warmTemplateFromHref(href: string | undefined | null): void {
    if (!href?.includes('#/template/')) {
      return;
    }
    const segment = href.split('#/template/')[1]?.split(/[?#]/)[0];
    warmTemplateDetail(segment);
  }

  return {
    warmProductDetail,
    warmRouteFromHref,
    warmTemplateDetail,
    warmTemplateFromHref,
  };
}
