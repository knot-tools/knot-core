#!/usr/bin/env node
/**
 * Knot i18n compile linter — ensures every locale string parses with @intlify/message-compiler.
 *
 * Catches ICU placeholder bugs (e.g. "{ }", "{{ }}", "{{$json.name}}") before runtime crashes.
 *
 * Run: npm run lint:i18n:compile
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { baseCompile } = require('@intlify/message-compiler');

const __dirname = dirname(fileURLToPath(import.meta.url));
const localesDir = resolve(__dirname, '..', 'src', 'i18n');

function flatLeaves(obj, prefix = '') {
  const out = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      out.push(...flatLeaves(v, path));
    } else {
      out.push([path, String(v)]);
    }
  }
  return out;
}

function compileErrors(message) {
  const errors = [];
  const onError = (err) => errors.push(err.message || String(err));
  try {
    baseCompile(message, { location: false, jit: true, onError });
  } catch (err) {
    errors.push(err.message || String(err));
  }
  return [...new Set(errors)];
}

let failed = false;

for (const file of readdirSync(localesDir).filter((f) => f.endsWith('.json'))) {
  const data = JSON.parse(readFileSync(join(localesDir, file), 'utf8'));
  const hits = [];
  for (const [key, msg] of flatLeaves(data)) {
    const errors = compileErrors(msg);
    if (errors.length > 0) {
      hits.push({ key, msg, errors });
    }
  }
  if (hits.length > 0) {
    failed = true;
    console.error(`\n[i18n-compile] ${file} — ${hits.length} invalid string(s)`);
    for (const hit of hits) {
      console.error(`  ${hit.key}`);
      console.error(`    errors: ${hit.errors.join('; ')}`);
      console.error(`    value: ${JSON.stringify(hit.msg.slice(0, 120))}`);
    }
  } else {
    console.log(`[i18n-compile] ${file} OK`);
  }
}

if (failed) {
  console.error('\n[i18n-compile] Fix invalid ICU placeholders (use {\'{\'} / {\'}\'} for literal braces).');
  process.exit(1);
}

console.log('\n[i18n-compile] All locale strings compile cleanly.');
