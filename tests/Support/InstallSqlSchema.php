<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * Parses Knot install SQL (sql/llx_knot_*.sql) into table to column maps.
 *
 * Optionally merges sql/migrations version folders CREATE / ADD COLUMN so
 * audits can distinguish "missing everywhere" from "migration-only".
 */
final class InstallSqlSchema
{
    /**
     * @return array<string, array<string, true>> short table name → set of columns
     */
    public static function loadInstallTables(string $sqlDir): array
    {
        $tables = [];
        $files = glob(rtrim($sqlDir, '/') . '/llx_knot_*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            foreach (self::parseCreateTables($sql) as $fullName => $columns) {
                $short = self::shortName($fullName);
                $tables[$short] = ($tables[$short] ?? []) + $columns;
            }
        }

        return $tables;
    }

    /**
     * Install tables plus CREATE TABLE / ADD COLUMN from migrations.
     *
     * @return array<string, array<string, true>>
     */
    public static function loadEffectiveTables(string $sqlDir): array
    {
        $tables = self::loadInstallTables($sqlDir);
        $migrationRoot = rtrim($sqlDir, '/') . '/migrations';
        if (!is_dir($migrationRoot)) {
            return $tables;
        }
        $files = glob($migrationRoot . '/v*/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            foreach (self::parseCreateTables($sql) as $fullName => $columns) {
                $short = self::shortName($fullName);
                $tables[$short] = ($tables[$short] ?? []) + $columns;
            }
            foreach (self::parseAddColumns($sql) as $fullName => $columns) {
                $short = self::shortName($fullName);
                $tables[$short] = ($tables[$short] ?? []) + $columns;
            }
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, true>> full table name → columns
     */
    public static function parseCreateTables(string $sql): array
    {
        $out = [];
        if (
            !preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?[a-z0-9_]+`?)\s*\((.*?)\)\s*(?:ENGINE|;)/is',
                $sql,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            return $out;
        }
        foreach ($matches as $match) {
            $name = trim($match[1], '`');
            $body = $match[2];
            $columns = [];
            foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
                $line = trim($line);
                $line = rtrim($line, ',');
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|CONSTRAINT|INDEX)\b/i', $line) === 1) {
                    continue;
                }
                if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+/i', $line, $col) === 1) {
                    $columns[strtolower($col[1])] = true;
                }
            }
            $out[$name] = $columns;
        }

        return $out;
    }

    /**
     * @return array<string, array<string, true>>
     */
    public static function parseAddColumns(string $sql): array
    {
        $out = [];
        if (
            !preg_match_all(
                '/ALTER\s+TABLE\s+(`?[a-z0-9_]+`?)\s+(.*?);/is',
                $sql,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            return $out;
        }
        foreach ($matches as $match) {
            $name = trim($match[1], '`');
            $clause = $match[2];
            if (!preg_match_all('/ADD\s+COLUMN\s+`?([a-z_][a-z0-9_]*)`?/i', $clause, $cols)) {
                continue;
            }
            foreach ($cols[1] as $col) {
                $out[$name][strtolower((string) $col)] = true;
            }
        }

        return $out;
    }

    /**
     * Adapt a MySQL CREATE TABLE statement so SQLite can execute it.
     */
    public static function mysqlCreateToSqlite(string $createSql): string
    {
        $sql = $createSql;
        $sql = preg_replace('/\s+ENGINE=\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET=\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+COLLATE=\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/integer\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
        $sql = preg_replace('/INTEGER\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
        $sql = str_ireplace('longtext', 'TEXT', $sql);
        $sql = str_ireplace('tinyint', 'INTEGER', $sql);
        $sql = preg_replace(
            '/timestamp\s+DEFAULT\s+CURRENT_TIMESTAMP(?:\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP)?/i',
            'TEXT',
            $sql
        ) ?? $sql;
        // Drop secondary KEY / UNIQUE KEY definitions inside CREATE.
        $sql = preg_replace('/,\s*UNIQUE\s+KEY\s+\w+\s*\([^)]+\)/i', '', $sql) ?? $sql;
        $sql = preg_replace('/,\s*KEY\s+\w+\s*\([^)]+\)/i', '', $sql) ?? $sql;

        return trim($sql);
    }

    public static function shortName(string $fullTable): string
    {
        $name = strtolower($fullTable);
        if (str_starts_with($name, 'llx_knot_')) {
            return substr($name, strlen('llx_knot_'));
        }
        if (str_starts_with($name, 'knot_')) {
            return substr($name, strlen('knot_'));
        }

        return $name;
    }
}
