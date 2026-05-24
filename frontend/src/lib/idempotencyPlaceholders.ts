/**
 * Technical Knot expression examples for idempotency key placeholders.
 * Must stay out of vue-i18n JSON: double curly braces break intlify compilation.
 */
export const IDEMPOTENCY_PLACEHOLDER_GENERIC = '{{$json.id}}-create-invoice';
export const IDEMPOTENCY_PLACEHOLDER_DOLIBARR =
  '{{$nodes.trigger.json.ref}}-{{$json.id}}-update';
export const IDEMPOTENCY_PLACEHOLDER_HTTP =
  '{{$json.method}}-{{$json.url}}-{{sha256($json.body)}}';
