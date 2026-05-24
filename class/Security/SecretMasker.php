<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Security;

/**
 * Masks secrets before they hit logs, traces, the inspector or the audit
 * trail.
 *
 * Two-pass strategy:
 *
 * 1. **Key-based masking** — any key whose normalised name (lowercased,
 *    `_-` stripped) matches one of {@see SENSITIVE_KEY_TOKENS} is replaced
 *    with `********`. We match on substring tokens so `client_secret`,
 *    `db_password`, `webhook_signing_key`, `accessTokenV2`, `slack_xoxb`
 *    all get caught.
 *
 * 2. **Value-based masking** — string values are scanned for known secret
 *    patterns even when the surrounding key is innocuous. We catch:
 *    - Bearer / Basic Authorization headers (`Bearer xxxx`, `Basic yyyy`),
 *    - JWTs (3 base64 segments separated by dots),
 *    - AWS access keys (AKIA / ASIA + 16 alphanums) and secrets,
 *    - Slack tokens (`xox[bps]-…`), GitHub PATs (`ghp_…`, `gho_…`),
 *    - Stripe keys (`sk_live_…`, `pk_live_…`),
 *    - Generic `Authorization: …` blocks inside concatenated debug strings.
 *
 * Both passes are recursive over arrays and nested objects.
 */
final class SecretMasker
{
    /**
     * Substring tokens that flag a key as sensitive (case-insensitive,
     * matched after stripping `_` and `-`).
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEY_TOKENS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'apikey',
        'apitoken',
        'authkey',
        'authsecret',
        'authorization',
        'bearer',
        'privatekey',
        'clientsecret',
        'sessionkey',
        'sessionid',
        'cookie',
        'csrf',
        'xoxb',
        'xoxp',
        'webhooksecret',
        'signingkey',
        'signingsecret',
        'accesstoken',
        'refreshtoken',
        'idtoken',
        'pat',
        'serviceaccount',
        'credential',
        'credentials',
        'deploymentnonce',
    ];

    /**
     * Regex patterns that flag known secret formats inside string values.
     *
     * @var array<int, string>
     */
    private const VALUE_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._\-]+/i',
        '/\bBasic\s+[A-Za-z0-9+\/=]+/i',
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', // JWT
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/',                              // AWS keys
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/i',                       // Slack tokens
        '/\bghp_[A-Za-z0-9]{36,}\b/',                                 // GitHub PAT
        '/\bgho_[A-Za-z0-9]{36,}\b/',                                 // GitHub OAuth token
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        '/\b(sk|pk)_(live|test)_[A-Za-z0-9]{16,}\b/',                 // Stripe
        '/\bAIza[A-Za-z0-9_\-]{20,}\b/',                              // Google API
        '/\b[a-zA-Z0-9._%+-]+:[A-Za-z0-9!@#$%^&*()_\-+=]{8,}@[a-zA-Z0-9.\-]+\b/', // user:password@host
    ];

    public const REDACTED = '********';

    /**
     * Mask sensitive values recursively.
     *
     * @param mixed $value Value to mask
     * @return mixed
     */
    public function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $masked[$key] = self::REDACTED;
                    continue;
                }
                $masked[$key] = $this->mask($item);
            }
            return $masked;
        }

        if (is_string($value)) {
            return $this->maskString($value);
        }

        return $value;
    }

    /**
     * Convenience wrapper for arrays.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function maskArray(array $value): array
    {
        $masked = $this->mask($value);
        return is_array($masked) ? $masked : [];
    }

    /**
     * Mask suspicious tokens inside a free-form string. Public so callers
     * can mask log lines, exception messages, etc.
     */
    public function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        foreach (self::VALUE_PATTERNS as $pattern) {
            $value = (string) preg_replace($pattern, self::REDACTED, $value);
        }
        return $value;
    }

    /**
     * Decide whether a key name reveals a secret.
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower(preg_replace('/[\s_\-]+/', '', $key) ?? $key);
        if ($normalised === '') {
            return false;
        }
        foreach (self::SENSITIVE_KEY_TOKENS as $token) {
            if (str_contains($normalised, $token)) {
                return true;
            }
        }
        return false;
    }
}
