#!/usr/bin/env node
/**
 * Knot i18n loanword linter — ensures non-reference locales do not leave
 * English strings untranslated unless the key is whitelisted.
 *
 * Reference locale: en_US.json (Core frontend).
 * Whitelist: frontend/scripts/i18n-loanword-whitelist.json
 *
 * Run: npm run lint:i18n:loanwords
 *      npm run lint:i18n:loanwords -- --all   # all locales (post-translation waves)
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const localesDir = resolve(__dirname, '..', 'src', 'i18n');
const whitelistPath = resolve(__dirname, 'i18n-loanword-whitelist.json');
const reference = 'en_US.json';

function flatEntries(obj, prefix = '') {
  const out = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      out.push(...flatEntries(v, path));
    } else {
      out.push([path, v]);
    }
  }
  return out;
}

function loadJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

const whitelistRaw = loadJson(whitelistPath);
const whitelist = new Set(whitelistRaw.core ?? []);

function loadLocale(file) {
  return loadJson(join(localesDir, file));
}

const refMap = new Map(flatEntries(loadLocale(reference)));
let failed = false;

const checkAll = process.argv.includes('--all');
/** By default only fr_FR — DE/ES/IT/PT checked with --all after translation waves. */
const defaultTargets = ['fr_FR.json'];
const targetLocales = checkAll
  ? readdirSync(localesDir).filter((f) => f.endsWith('.json') && f !== reference)
  : defaultTargets.filter((f) => existsSync(join(localesDir, f)));

for (const file of targetLocales) {
  const localeMap = new Map(flatEntries(loadLocale(file)));
  const violations = [];

  for (const [key, refValue] of refMap) {
    if (typeof refValue !== 'string' || refValue.trim() === '') {
      continue;
    }
    const localeValue = localeMap.get(key);
    if (localeValue === undefined || localeValue !== refValue) {
      continue;
    }
    if (whitelist.has(key)) {
      continue;
    }
    violations.push({ key, value: refValue });
  }

  if (violations.length) {
    failed = true;
    console.error(`\n[i18n-loanwords] ${file} — ${violations.length} EN-identical string(s) not whitelisted:`);
    violations.slice(0, 40).forEach(({ key, value }) => {
      const preview = String(value).length > 60 ? `${String(value).slice(0, 57)}…` : value;
      console.error(`    - ${key} = "${preview}"`);
    });
    if (violations.length > 40) {
      console.error(`    … and ${violations.length - 40} more`);
    }
  } else {
    console.log(`[i18n-loanwords] ${file} OK`);
  }
}

if (failed) {
  console.error(
    '\n[i18n-loanwords] Add intentional loanwords to frontend/scripts/i18n-loanword-whitelist.json',
    'or translate the string in the target locale.',
  );
  process.exit(1);
}

console.log('\n[i18n-loanwords] All locales pass loanword policy.');
