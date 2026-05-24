<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Resolves simple Knot expressions in strings.
 */
final class ExpressionResolver
{
    /**
     * Resolve expressions recursively.
     *
     * @param mixed $value Value containing expressions
     * @param array<string, mixed> $context Execution context
     * @return mixed
     */
    public function resolve(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item, $context);
            }
            return $resolved;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function (array $matches) use ($context): string {
            $resolved = $this->resolvePath(trim($matches[1]), $context);
            if (is_scalar($resolved) || $resolved === null) {
                return (string) $resolved;
            }

            return json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }, $value) ?? $value;
    }

    /**
     * Resolve a dot path from context.
     *
     * Built-in shortcuts:
     * - `$now`   → current Unix timestamp formatted as `Y-m-d H:i:s`
     * - `$now+<N>d` / `$now+<N>h` / `$now+<N>m` → relative date
     * - `$today` → `Y-m-d` of today
     * - `$timestamp` → raw Unix timestamp (integer)
     * - `uniqid` → 16-character hex suffix (collision-resistant refs for replays within the same second)
     *
     * Anything else falls back to a dot-path lookup in `$context`.
     *
     * @param array<string, mixed> $context Execution context
     * @return mixed
     */
    private function resolvePath(string $path, array $context): mixed
    {
        $path = ltrim($path, '$');
        $path = preg_replace('/^\./', '', $path) ?? $path;

        $builtin = $this->resolveBuiltin($path);
        if ($builtin !== null) {
            return $builtin;
        }

        $segments = explode('.', $path);
        $current = $context;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Recognise the small set of built-in shortcuts (`$now`, `$today`,
     * `$now+1d`…) before falling through to context lookup.
     */
    private function resolveBuiltin(string $path): mixed
    {
        if ($path === 'timestamp') {
            return time();
        }

        if ($path === 'uniqid') {
            return bin2hex(random_bytes(8));
        }

        if ($path === 'today') {
            return date('Y-m-d');
        }

        if ($path === 'now') {
            return date('Y-m-d H:i:s');
        }

        if (preg_match('/^now\s*([+\-])\s*(\d+)\s*([dhms])$/', $path, $m)) {
            $sign = $m[1] === '-' ? -1 : 1;
            $amount = (int) $m[2] * $sign;
            $unit = strtolower($m[3]);
            $multiplier = match ($unit) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400,
            };
            return date('Y-m-d H:i:s', time() + $amount * $multiplier);
        }

        return null;
    }
}
