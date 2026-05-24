#!/usr/bin/env node
/**
 * Knot i18n linter — checks that every locale file under `src/i18n/`
 * exposes exactly the same key tree as `en_US.json`.
 *
 * Exits with code 1 if a key is missing in one locale or extra in another.
 * Run with: node frontend/scripts/lint-i18n.mjs
 *           OR npm run lint:i18n
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const localesDir = resolve(__dirname, '..', 'src', 'i18n');
const reference = 'en_US.json';

function flatKeys(obj, prefix = '') {
  const out = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      out.push(...flatKeys(v, path));
    } else {
      out.push(path);
    }
  }
  return out;
}

function loadLocale(file) {
  const raw = readFileSync(join(localesDir, file), 'utf8');
  return JSON.parse(raw);
}

const refKeys = new Set(flatKeys(loadLocale(reference)));
let failed = false;

for (const file of readdirSync(localesDir).filter((f) => f.endsWith('.json'))) {
  if (file === reference) continue;
  const keys = new Set(flatKeys(loadLocale(file)));
  const missing = [...refKeys].filter((k) => !keys.has(k));
  const extra = [...keys].filter((k) => !refKeys.has(k));
  if (missing.length || extra.length) {
    failed = true;
    console.error(`\n[i18n-lint] ${file}`);
    if (missing.length) {
      console.error(`  MISSING (${missing.length}):`);
      missing.forEach((k) => console.error(`    - ${k}`));
    }
    if (extra.length) {
      console.error(`  EXTRA (${extra.length}, not in ${reference}):`);
      extra.forEach((k) => console.error(`    + ${k}`));
    }
  } else {
    console.log(`[i18n-lint] ${file} OK (${keys.size} keys)`);
  }
}

if (failed) {
  console.error('\n[i18n-lint] Some locales are out of sync. Update them or update en_US.json.');
  process.exit(1);
}
console.log('\n[i18n-lint] All locales aligned.');
