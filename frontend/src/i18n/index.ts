/**
 * Knot — Vue I18n setup.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Locale comes from `window.KNOT_LOCALE` injected by `workflows/preview.php`.
 * Knot ships with 6 locales as of V2.5: fr_FR, en_US, de_DE, es_ES, it_IT, pt_PT.
 * Fallback chain: requested locale → fr_FR → en_US.
 */
import { createI18n } from 'vue-i18n';
import frFR from './fr_FR.json';
import enUS from './en_US.json';
import deDE from './de_DE.json';
import esES from './es_ES.json';
import itIT from './it_IT.json';
import ptPT from './pt_PT.json';

export type LocaleCode =
  | 'fr_FR'
  | 'en_US'
  | 'de_DE'
  | 'es_ES'
  | 'it_IT'
  | 'pt_PT';

export const SUPPORTED_LOCALES: readonly LocaleCode[] = [
  'fr_FR',
  'en_US',
  'de_DE',
  'es_ES',
  'it_IT',
  'pt_PT',
] as const;

declare global {
  interface Window {
    KNOT_LOCALE?: string;
  }
}

function detectLocale(): LocaleCode {
  const raw = (typeof window !== 'undefined' && window.KNOT_LOCALE) || 'fr_FR';
  if (SUPPORTED_LOCALES.includes(raw as LocaleCode)) {
    return raw as LocaleCode;
  }
  // Accept short codes injected by Dolibarr (`fr`, `en`, `de`, …)
  const short = raw.split('_')[0]?.toLowerCase();
  switch (short) {
    case 'en':
      return 'en_US';
    case 'de':
      return 'de_DE';
    case 'es':
      return 'es_ES';
    case 'it':
      return 'it_IT';
    case 'pt':
      return 'pt_PT';
    case 'fr':
    default:
      return 'fr_FR';
  }
}

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: detectLocale(),
  fallbackLocale: ['fr_FR', 'en_US'],
  messages: {
    fr_FR: frFR,
    en_US: enUS,
    de_DE: deDE,
    es_ES: esES,
    it_IT: itIT,
    pt_PT: ptPT,
  },
});

export type KnotI18nMessages = typeof frFR;
