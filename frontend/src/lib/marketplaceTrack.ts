/**
 * Fire-and-forget Marketplace SPA telemetry (`POST /api/marketplace_track.php`).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { knotApi } from './api';

export type MarketplaceTrackEvent =
  | 'cta_click'
  | 'template_instantiated'
  | 'product_page_visit'
  | 'news_visit'
  | 'banner_dismissed'
  | 'spotlight.click'
  | 'tab.change'
  | 'drawer.open'
  | 'search.query'
  | 'detail.scroll_depth';

export function trackMarketplaceEvent(
  event: MarketplaceTrackEvent,
  context: Record<string, string | number | boolean> = {},
): void {
  void knotApi.marketplaceTrack(event, context).catch(() => {
    /* analytics must never break UX */
  });
}
