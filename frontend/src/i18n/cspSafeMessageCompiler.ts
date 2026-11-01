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

type Part = string | { key: string };

/**
 * `interpolate` / `normalize` are on every runtime MessageContext (intlify's
 * own compiled messages call them) but are excluded from the public typings.
 */
type RuntimeMessageContext = MessageContext & {
  interpolate: (value: unknown) => unknown;
  normalize: (values: unknown[]) => string;
};

function splitMessage(message: string): Part[] {
  const parts: Part[] = [];
  let last = 0;
  for (const match of message.matchAll(NAMED_PLACEHOLDER)) {
    if (match.index > last) parts.push(message.slice(last, match.index));
    parts.push({ key: match[1] });
    last = match.index + match[0].length;
  }
  if (last < message.length) parts.push(message.slice(last));
  return parts;
}

/**
 * Parts go through ctx.interpolate + ctx.normalize, like intlify's own
 * compiled messages: `t()` gets a joined string, while `<i18n-t>` gets an
 * array that keeps slot VNodes (e.g. a `{link}` slot) instead of failing
 * with "Unexpected return type in composer".
 */
export const cspSafeMessageCompiler: MessageCompiler = (message) => {
  if (typeof message !== 'string') {
    return () => '';
  }

  const parts = splitMessage(message);
  if (parts.every((part) => typeof part === 'string')) {
    const constant = message;
    return () => constant;
  }

  return (context: MessageContext) => {
    const ctx = context as RuntimeMessageContext;
    return ctx.normalize(
      parts.map((part) => {
        if (typeof part === 'string') return part;
        const value = ctx.named(part.key);
        return value != null && value !== '' ? ctx.interpolate(value) : `{${part.key}}`;
      }),
    );
  };
};
