/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';

import {
  catalogSlugToBetaProduct,
  knotCoreDownloadUrl,
  knotToolsBetaUrl,
  knotToolsProductPage,
  lockedTemplateExternalHref,
  resolvePackBuyUrl,
  sanitizeMarketplaceHref,
} from '../marketplaceLinks';

describe('marketplaceLinks', () => {
  it('maps catalog slugs to knot.tools product pages', () => {
    expect(knotToolsProductPage('knot-pro-pack')).toBe('https://knot.tools/pro-pack/');
    expect(knotToolsProductPage('knot-migration', '/en')).toBe('https://knot.tools/en/migration/');
  });

  it('uses beta URL for purchases', () => {
    expect(knotToolsBetaUrl('knot-pro-pack')).toBe('https://knot.tools/beta/?products=pro-pack');
    expect(catalogSlugToBetaProduct('knot-migration')).toBe('migration');
  });

  it('points Core download to latest handler', () => {
    expect(knotCoreDownloadUrl()).toBe('https://knot.tools/downloads/knot-core/latest');
  });

  it('rejects license portal buyUrl overrides', () => {
    expect(
      resolvePackBuyUrl('knot-pro-pack', 'https://license.knot.tools/checkout?product=knot-pro-pack'),
    ).toBe('https://knot.tools/beta/?products=pro-pack');
  });

  it('returns null for in-app activation locked state', () => {
    expect(lockedTemplateExternalHref('invalid', 'knot-pro-pack')).toBeNull();
    expect(lockedTemplateExternalHref('not_installed', 'knot-pro-pack')).toContain('/pro-pack/');
  });

  it('sanitizes license portal and legacy editorial hrefs', () => {
    expect(sanitizeMarketplaceHref('https://license.knot.tools/checkout?product=knot-migration'))
      .toBe('https://knot.tools/beta/?products=migration');
    expect(sanitizeMarketplaceHref('https://knot.tools/products/migration'))
      .toBe('https://knot.tools/migration/');
    expect(sanitizeMarketplaceHref('https://knot.tools/downloads/'))
      .toBe('https://knot.tools/downloads/knot-core/latest');
  });
});
