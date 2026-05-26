/**
 * Canonical public URLs for Marketplace UI (knot.tools only — never license.knot.tools).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export const KNOT_TOOLS_ORIGIN = 'https://knot.tools';

/** Dolibarr locale tag → knot.tools path prefix (empty = FR default). */
const LOCALE_PREFIX: Record<string, string> = {
  fr_FR: '',
  en_US: '/en',
  es_ES: '/es',
  de_DE: '/de',
  it_IT: '/it',
  pt_PT: '/pt',
};

export function knotToolsLocalePrefix(dolibarrLocale?: string | null): string {
  if (!dolibarrLocale) {
    return '';
  }
  return LOCALE_PREFIX[dolibarrLocale] ?? '';
}

function normalizeCatalogSlug(slug: string): string {
  return slug.trim().toLowerCase();
}

/** Website product path segment (`/pro-pack/`, `/migration/`, …). */
export function catalogSlugToProductPath(catalogSlug: string): string {
  const s = normalizeCatalogSlug(catalogSlug);
  if (s.includes('pro-pack')) {
    return '/pro-pack/';
  }
  if (s.includes('migration')) {
    return '/migration/';
  }
  if (s.includes('enterprise')) {
    return '/contact/';
  }
  return '/pricing/';
}

/** Beta form product query value (`pro-pack`, `migration`, …). */
export function catalogSlugToBetaProduct(catalogSlug: string): string {
  const s = normalizeCatalogSlug(catalogSlug);
  if (s.includes('migration')) {
    return 'migration';
  }
  if (s.includes('enterprise')) {
    return 'enterprise';
  }
  if (s.includes('pro-pack') || s.includes('propack')) {
    return 'pro-pack';
  }
  return 'pro-pack';
}

export function knotToolsProductPage(catalogSlug: string, localePrefix = ''): string {
  return `${KNOT_TOOLS_ORIGIN}${localePrefix}${catalogSlugToProductPath(catalogSlug)}`;
}

export function knotToolsBetaUrl(catalogSlug: string, localePrefix = ''): string {
  const product = catalogSlugToBetaProduct(catalogSlug);
  return `${KNOT_TOOLS_ORIGIN}${localePrefix}/beta/?products=${encodeURIComponent(product)}`;
}

export function knotToolsPricing(localePrefix = ''): string {
  return `${KNOT_TOOLS_ORIGIN}${localePrefix}/pricing/`;
}

export function knotCoreDownloadUrl(): string {
  return `${KNOT_TOOLS_ORIGIN}/downloads/knot-core/latest`;
}

export function knotToolsDocs(localePrefix = ''): string {
  return `${KNOT_TOOLS_ORIGIN}${localePrefix}/docs/`;
}

/**
 * Purchase / renew CTA for packs and locked templates (beta programme, not license portal).
 */
export function knotToolsPurchaseUrl(catalogSlug: string, localePrefix = ''): string {
  return knotToolsBetaUrl(catalogSlug, localePrefix);
}

/**
 * Ignore catalog `buyUrl` when it targets the license portal — UI must stay on knot.tools.
 */
export function resolvePackBuyUrl(
  catalogSlug: string,
  buyUrl: string | null | undefined,
  localePrefix = '',
): string {
  if (buyUrl && !buyUrl.includes('license.knot.tools')) {
    return buyUrl;
  }
  return knotToolsPurchaseUrl(catalogSlug, localePrefix);
}

export type LockedTemplateStatus = 'expired' | 'not_installed' | 'invalid' | 'unknown' | string;

/**
 * External URL for a locked template CTA, or `null` when the action is in-app (activation modal).
 */
export function lockedTemplateExternalHref(
  status: LockedTemplateStatus,
  catalogSlug: string,
  localePrefix = '',
): string | null {
  if (status === 'not_installed') {
    return knotToolsProductPage(catalogSlug, localePrefix);
  }
  if (status === 'expired') {
    return knotToolsPurchaseUrl(catalogSlug, localePrefix);
  }
  return null;
}

/** In-app hash route for a catalog product slug. */
export function marketplaceProductHash(catalogSlug: string): string {
  return `#/product/${normalizeCatalogSlug(catalogSlug).replace(/[^a-z0-9-]/g, '')}`;
}

/**
 * Rewrites editorial or catalog hrefs so clickable Marketplace UI never opens license.knot.tools.
 */
export function sanitizeMarketplaceHref(
  href: string | undefined | null,
  localePrefix = '',
): string | undefined {
  if (href == null || href.trim() === '') {
    return undefined;
  }

  const trimmed = href.trim();
  if (trimmed.startsWith('#/')) {
    return trimmed;
  }

  const origin = `${KNOT_TOOLS_ORIGIN}${localePrefix}`;

  if (trimmed.includes('license.knot.tools')) {
    try {
      const parsed = new URL(trimmed);
      const product = parsed.searchParams.get('product') ?? '';
      if (parsed.pathname.includes('checkout') || product !== '') {
        return knotToolsBetaUrl(product || 'knot-pro-pack', localePrefix);
      }
    } catch {
      // Ignore malformed URLs and fall through to pricing.
    }
    return knotToolsProductPage('knot-pro-pack', localePrefix);
  }

  let out = trimmed
    .replace('https://www.knot.tools/products/migration', `${origin}/migration/`)
    .replace('https://knot.tools/products/migration', `${origin}/migration/`)
    .replace('https://www.knot.tools/products/pro-pack', `${origin}/pro-pack/`)
    .replace('https://knot.tools/products/pro-pack', `${origin}/pro-pack/`);

  if (out === 'https://knot.tools/downloads/' || out === 'https://knot.tools/downloads') {
    return knotCoreDownloadUrl();
  }

  return out;
}
