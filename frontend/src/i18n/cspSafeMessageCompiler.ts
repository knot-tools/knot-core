/**
 * CSP-safe vue-i18n message compiler (no `eval` / `new Function`).
 *
 * Knot Marketplace mode injects a strict Content-Security-Policy in
 * `workflows/preview.php`. The default intlify compiler uses
 * `compileToFunction`, which violates `script-src` without `'unsafe-eval'`.
 *
 * Knot locale JSON uses simple named placeholders only (`{name}`, `{count}`).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import type { MessageCompiler, MessageContext } from '@intlify/core-base';

const NAMED_PLACEHOLDER = /\{(\w+)\}/g;

function interpolateNamed(message: string, ctx: MessageContext): string {
  return message.replace(NAMED_PLACEHOLDER, (_, key: string) => {
    const value = ctx.named(key);
    return value != null && value !== '' ? String(value) : `{${key}}`;
  });
}

export const cspSafeMessageCompiler: MessageCompiler = (message) => {
  if (typeof message !== 'string') {
    return () => '';
  }

  if (!NAMED_PLACEHOLDER.test(message)) {
    NAMED_PLACEHOLDER.lastIndex = 0;
    const constant = message;
    return () => constant;
  }

  NAMED_PLACEHOLDER.lastIndex = 0;
  return (ctx: MessageContext) => interpolateNamed(message, ctx);
};
