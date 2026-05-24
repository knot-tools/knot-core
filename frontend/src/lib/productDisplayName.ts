/**
 * Human-facing product labels (slug → name). Technical slugs stay in API payloads.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

const PRODUCT_I18N_KEYS: Record<string, string> = {
  knot: 'updatesPage.productNames.knot',
  'knot-pro-pack': 'updatesPage.productNames.knotProPack',
  'knot-migration': 'updatesPage.productNames.knotMigration',
};

export function productDisplayName(
  slug: string,
  t: (key: string, fallback?: Record<string, unknown>) => string,
): string {
  const key = PRODUCT_I18N_KEYS[slug];
  if (key === undefined) {
    return slug;
  }
  const label = t(key);
  return label === key ? slug : label;
}
