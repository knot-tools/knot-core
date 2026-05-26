#!/usr/bin/env node
/**
 * Compare `marketplace.*` message keys across core storefront locales.
 * Exit 1 if any locale is missing keys present in en_US.
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const localesDir = resolve(__dirname, '..', 'src', 'i18n');

const REF = 'en_US.json';
const LOCALES = ['fr_FR.json', 'en_US.json', 'es_ES.json', 'de_DE.json', 'it_IT.json', 'pt_PT.json'];

/**
 * @param {Record<string, unknown>} messages Root JSON object ({ marketplace: { … } })
 * @returns {string[]} e.g. ['marketplace.title', …]
 */
function flatMarketplaceKeys(messages) {
  const root = messages.marketplace;
  if (!root || typeof root !== 'object' || Array.isArray(root)) {
    return [];
  }
  /** @type {string[]} */
  const out = [];
  /** @param {Record<string, unknown>} o */
  /** @param {string} prefix */
  function walk(o, prefix) {
    for (const [k, v] of Object.entries(o)) {
      const pathSeg = prefix ? `${prefix}.${k}` : k;
      if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
        walk(/** @type {Record<string, unknown>} */ (v), pathSeg);
      } else {
        out.push(`marketplace.${pathSeg}`);
      }
    }
  }
  walk(/** @type {Record<string, unknown>} */ (root), '');

  return out;
}

function loadLocale(file) {
  const raw = readFileSync(join(localesDir, file), 'utf8');

  return JSON.parse(raw);
}

const refBundle = loadLocale(REF);
const refMarketplaceKeys = new Set(flatMarketplaceKeys(refBundle));
if (refMarketplaceKeys.size === 0) {
  console.error('[i18n-check] Reference en_US.json has no marketplace.* keys.');
  process.exit(1);
}

let failed = false;

for (const file of LOCALES) {
  const bundle = loadLocale(file);
  const keys = new Set(flatMarketplaceKeys(bundle));
  const missing = [...refMarketplaceKeys].filter((k) => !keys.has(k));
  if (missing.length) {
    failed = true;
    console.error(`\n[i18n-check] ${file} — missing marketplace keys (${missing.length}):`);
    missing.slice(0, 80).forEach((k) => console.error(`  - ${k}`));
    if (missing.length > 80) {
      console.error(`  … and ${missing.length - 80} more`);
    }
  } else {
    console.log(`[i18n-check] ${file} OK (${keys.size} marketplace keys)`);
  }
}

if (failed) {
  console.error('\n[i18n-check] Align marketplace.* keys with en_US.json');
  process.exit(1);
}

console.log('\n[i18n-check] All marketplace locale keys aligned.');
