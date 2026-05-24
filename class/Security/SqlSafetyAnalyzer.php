<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Security;

/**
 * Defence-in-depth analyser for the {@see Knot\Connectors\Dolibarr\SqlQuery}
 * connector.
 *
 * Even though SqlQuery is admin-only and already gated to `SELECT`, an admin
 * account is still a perfectly viable foothold for an attacker. The analyser
 * adds a second layer of guarantees that survives expression resolution:
 *
 * - **Stacked queries**: rejects any `;` outside string literals so a
 *   resolved expression cannot smuggle a second statement.
 * - **Mutating keywords**: rejects DML / DDL / privilege keywords on token
 *   boundaries (so a column named `update_count` does not trip).
 * - **System catalogues**: rejects `information_schema.*`, `mysql.*`,
 *   `performance_schema.*`, `sys.*` to prevent metadata leakage.
 * - **Knot internal tables**: rejects credential / encryption-key tables so
 *   an admin running a workflow cannot exfiltrate other admins' secrets.
 * - **MySQL functions abusable for SSRF/exfiltration**: `LOAD_FILE`,
 *   `INTO OUTFILE`, `INTO DUMPFILE`, `BENCHMARK`, `SLEEP`.
 *
 * The analyser is purely heuristic — it intentionally errs on the side of
 * over-blocking; legitimate admin reports almost never hit any of these
 * patterns. When a pattern matches, an explicit reason is returned so the
 * UI can surface a useful error message.
 */
final class SqlSafetyAnalyzer
{
    /**
     * Forbidden keywords on word boundaries. Anything beyond `SELECT` is
     * blocked so an attacker cannot piggyback on a `WHERE id = {{$json.x}}`
     * binding.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create',
        'replace', 'grant', 'revoke', 'lock', 'unlock', 'rename',
        'load_file', 'load data', 'into outfile', 'into dumpfile',
        'benchmark', 'sleep', 'extractvalue', 'updatexml',
        'set @', 'set global', 'set session', 'show grants',
    ];

    /**
     * Schema / DB names that must stay invisible to userland workflows.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_SCHEMAS = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ];

    /**
     * Knot tables that contain bytes too sensitive to surface even to admins.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_TABLES = [
        'llx_knot_credential',
        'llx_user',
    ];

    /**
     * Run the full battery of checks on a (post-resolution) SQL string.
     *
     * @return array{valid: bool, reason: string|null}
     */
    public function analyse(string $query): array
    {
        $normalised = $this->stripStringLiterals($query);
        $lower = strtolower($normalised);

        if ($normalised === '') {
            return ['valid' => false, 'reason' => 'Empty query.'];
        }

        if (preg_match('/^\s*select\b/i', $normalised) !== 1) {
            return ['valid' => false, 'reason' => 'Only SELECT statements are allowed.'];
        }

        if (str_contains(rtrim(rtrim($normalised), ';'), ';')) {
            return ['valid' => false, 'reason' => 'Stacked queries (`;`) are not allowed.'];
        }

        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $lower) === 1) {
                return ['valid' => false, 'reason' => 'Forbidden keyword: ' . $kw];
            }
        }

        foreach (self::FORBIDDEN_SCHEMAS as $schema) {
            if (preg_match('/\b' . preg_quote($schema, '/') . '\s*\.\s*[a-z0-9_]+/i', $lower) === 1) {
                return ['valid' => false, 'reason' => 'Forbidden schema: ' . $schema];
            }
        }

        foreach (self::FORBIDDEN_TABLES as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $lower) === 1) {
                return ['valid' => false, 'reason' => 'Forbidden table: ' . $table];
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Replace contents of single / double-quoted string literals with empty
     * placeholders so checks for `;`, keywords, etc. are not confused by
     * legitimate content inside `WHERE name = 'DROP TABLE x'`. Comments are
     * also stripped (`--`, `#`, `/* … *\/`).
     *
     * Order matters: block comments first (they can contain quote-looking
     * characters), then string literals (which can contain `#` and `--`
     * sequences as part of HTML/CSS/URLs), then line comments. Stripping
     * `#` BEFORE the literals would corrupt any query that embeds CSS hex
     * colours (`color:#64748b`), URL fragments (`url#section`), Markdown
     * headings, etc., and surface a false-positive `;` from the leftover
     * literal fragment.
     */
    private function stripStringLiterals(string $query): string
    {
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $query) ?? $query;
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $stripped) ?? $stripped;
        $stripped = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $stripped) ?? $stripped;
        $stripped = preg_replace('/--[^\n]*/', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/#[^\n]*/', '', $stripped) ?? $stripped;

        return trim($stripped);
    }
}
