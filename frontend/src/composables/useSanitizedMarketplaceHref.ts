/**
 * Locale-aware editorial href sanitizer for Marketplace UI links.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { knotToolsLocalePrefix, sanitizeMarketplaceHref } from '../lib/marketplaceLinks';

export function useSanitizedMarketplaceHref() {
  const { locale } = useI18n();
  const localePrefix = computed(() => knotToolsLocalePrefix(locale.value));

  function sanitize(href: string | undefined | null): string | undefined {
    return sanitizeMarketplaceHref(href, localePrefix.value);
  }

  return { sanitize, localePrefix };
}
