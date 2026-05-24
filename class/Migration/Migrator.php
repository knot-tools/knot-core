<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Migration;

/**
 * Idempotent migration runner for Knot V2.0+.
 *
 * Pattern:
 *   - Each version lives in `sql/migrations/{version}/NN_*.sql`.
 *   - Before running each statement, we resolve idempotency:
 *       * `CREATE TABLE IF NOT EXISTS` → MySQL native idempotence.
 *       * `ALTER TABLE … ADD COLUMN` → wrapped in INFORMATION_SCHEMA check.
 *       * `ADD KEY` → ditto on indexes.
 *   - Applied migrations are recorded in `llx_knot_migration_history`.
 */
final class Migrator
{
    public function __construct(private readonly \DoliDB $db, private readonly string $rootDir)
    {
    }

    /**
     * Apply every migration version found under `sql/migrations/`.
     *
     * @return array<int, array{version: string, file: string, status: string, durationMs: int}>
     */
    public function run(): array
    {
        $this->ensureHistoryTable();
        $applied = [];
        $base = rtrim($this->rootDir, '/') . '/sql/migrations';
        if (!is_dir($base)) {
            return $applied;
        }
        $versions = glob($base . '/v*.*.*', GLOB_ONLYDIR) ?: [];
        usort(
            $versions,
            static function (string $a, string $b): int {
                return version_compare(
                    ltrim(basename($a), 'v'),
                    ltrim(basename($b), 'v'),
                );
            },
        );
        foreach ($versions as $versionDir) {
            $version = basename($versionDir);
            $files = glob($versionDir . '/*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = basename($file);
                if ($this->alreadyApplied($version, $name)) {
                    continue;
                }
                $startedAt = microtime(true);
                $status = 'applied';
                try {
                    $this->applyFile($file);
                } catch (\Throwable $e) {
                    $status = 'error: ' . substr($e->getMessage(), 0, 220);
                }
                $duration = (int) ((microtime(true) - $startedAt) * 1000);
                $this->recordHistory($version, $name, $status, $duration);
                $applied[] = [
                    'version' => $version,
                    'file' => $name,
                    'status' => $status,
                    'durationMs' => $duration,
                ];
            }
        }
        return $applied;
    }

    private function applyFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $statements = $this->splitStatements($sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $idempotent = $this->makeIdempotent($stmt);
            foreach ($idempotent as $finalStmt) {
                if ($finalStmt === '') {
                    continue;
                }
                $ok = $this->db->query($finalStmt);
                if (!$ok) {
                    $err = (string) $this->db->lasterror();
                    if ($this->isHarmlessError($err)) {
                        continue;
                    }
                    throw new \RuntimeException('SQL failed: ' . substr($err, 0, 200));
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        $cleaned = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = explode(';', $cleaned);
        return array_filter(array_map('trim', $parts));
    }

    /**
     * Returns one or more guarded statements equivalent to `$stmt`.
     *
     * @return string[]
     */
    private function makeIdempotent(string $stmt): array
    {
        $upper = strtoupper($stmt);
        if (preg_match('/^ALTER\s+TABLE\s+([a-zA-Z0-9_]+)\s+/i', $stmt, $m)) {
            $table = $m[1];
            $tail = trim(substr($stmt, strlen($m[0])));
            $clauses = $this->splitAlterClauses($tail);
            $kept = [];
            foreach ($clauses as $clause) {
                if (preg_match('/^ADD\s+COLUMN\s+([a-zA-Z0-9_]+)/i', $clause, $cm)) {
                    if ($this->columnExists($table, $cm[1])) {
                        continue;
                    }
                } elseif (preg_match('/^ADD\s+KEY\s+([a-zA-Z0-9_]+)/i', $clause, $km)) {
                    if ($this->indexExists($table, $km[1])) {
                        continue;
                    }
                } elseif (preg_match('/^ADD\s+UNIQUE\s+KEY\s+([a-zA-Z0-9_]+)/i', $clause, $um)) {
                    if ($this->indexExists($table, $um[1])) {
                        continue;
                    }
                }
                $kept[] = $clause;
            }
            if ($kept === []) {
                return [];
            }
            return ['ALTER TABLE ' . $table . ' ' . implode(', ', $kept)];
        }
        return [$stmt];
    }

    /**
     * @return string[]
     */
    private function splitAlterClauses(string $tail): array
    {
        $depth = 0;
        $buf = '';
        $out = [];
        $len = strlen($tail);
        for ($i = 0; $i < $len; $i++) {
            $c = $tail[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    private function columnExists(string $table, string $column): bool
    {
        $sql = sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $table, $this->db->escape($column));
        $res = $this->db->query($sql);
        return $res && $this->db->num_rows($res) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $sql = sprintf("SHOW INDEX FROM %s WHERE Key_name = '%s'", $table, $this->db->escape($index));
        $res = $this->db->query($sql);
        return $res && $this->db->num_rows($res) > 0;
    }

    private function isHarmlessError(string $error): bool
    {
        $needles = ['Duplicate column name', 'Duplicate key name', 'already exists'];
        foreach ($needles as $n) {
            if (stripos($error, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private function ensureHistoryTable(): void
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS llx_knot_migration_history ('
            . 'rowid INTEGER AUTO_INCREMENT PRIMARY KEY, '
            . 'version VARCHAR(32) NOT NULL, '
            . 'migration_file VARCHAR(255) NOT NULL, '
            . 'applied_at DATETIME NOT NULL, '
            . 'duration_ms INT NULL, '
            . 'status VARCHAR(255) NOT NULL DEFAULT "applied", '
            . 'UNIQUE KEY uniq_migration (version, migration_file)'
            . ') ENGINE=InnoDB',
        );
    }

    private function alreadyApplied(string $version, string $file): bool
    {
        $sql = sprintf(
            "SELECT rowid FROM llx_knot_migration_history WHERE version = '%s' AND migration_file = '%s' AND status = 'applied'",
            $this->db->escape($version),
            $this->db->escape($file),
        );
        $res = $this->db->query($sql);
        return $res && $this->db->fetch_object($res) !== null;
    }

    private function recordHistory(string $version, string $file, string $status, int $durationMs): void
    {
        $sql = sprintf(
            "INSERT INTO llx_knot_migration_history (version, migration_file, applied_at, duration_ms, status) "
            . "VALUES ('%s', '%s', '%s', %d, '%s') "
            . "ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at), duration_ms=VALUES(duration_ms), status=VALUES(status)",
            $this->db->escape($version),
            $this->db->escape($file),
            $this->db->idate(time()),
            $durationMs,
            $this->db->escape(substr($status, 0, 255)),
        );
        $this->db->query($sql);
    }
}
