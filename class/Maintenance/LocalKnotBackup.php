<?php

declare(strict_types=1);

/* Copyright (C) 2026 Knot */

namespace Knot\Maintenance;

use DoliDB;

/**
 * PHP-only Knot backup for shared hosting: SQL INSERT dump for all `llx_knot_*`
 * tables and a ZIP of `documents/knot` (excluding `backups/` to avoid recursion).
 */
final class LocalKnotBackup
{
    /** @var list<string> Table suffixes (after llx_knot_) — keep in sync with admin/setup.php */
    private const TABLE_SUFFIXES = [
        'workflow', 'credential', 'execution', 'execution_log',
        'webhook', 'schedule', 'template', 'variable', 'audit_log',
        'workflow_version', 'idempotency', 'approval', 'workflow_tag',
        'workflow_folder',
    ];

    /**
     * @return array{dir: string, zip: string}
     *
     * @throws \RuntimeException when filesystem or DB operations fail
     */
    public static function run(DoliDB $db, string $label = 'backup'): array
    {
        if (!defined('DOL_DATA_ROOT') || !defined('MAIN_DB_PREFIX')) {
            throw new \RuntimeException('DOL_DATA_ROOT / MAIN_DB_PREFIX not defined');
        }

        $stamp = gmdate('Y-m-d\TH-i-s') . '-' . preg_replace('/[^a-z0-9_-]+/i', '', $label);
        $backupsRoot = rtrim((string) constant('DOL_DATA_ROOT'), '/\\') . '/knot/backups';
        $targetDir = $backupsRoot . '/knot-local-' . $stamp;

        dol_mkdir($backupsRoot);
        if (!is_dir($backupsRoot)) {
            throw new \RuntimeException('Cannot create backups root: ' . $backupsRoot);
        }
        dol_mkdir($targetDir);
        if (!is_dir($targetDir)) {
            throw new \RuntimeException('Cannot create backup folder: ' . $targetDir);
        }

        $sqlFile = $targetDir . '/knot_tables.sql';
        $fp = fopen($sqlFile, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot write SQL file');
        }

        fwrite($fp, "-- Knot local backup " . $stamp . "\n");
        fwrite($fp, 'SET FOREIGN_KEY_CHECKS=0;' . "\n");
        fwrite($fp, 'SET NAMES utf8mb4;' . "\n");

        foreach (self::TABLE_SUFFIXES as $suffix) {
            $table = MAIN_DB_PREFIX . 'knot_' . $suffix;
            $check = $db->query("SHOW TABLES LIKE '" . $db->escape($table) . "'");
            if (!$check || $db->num_rows($check) === 0) {
                continue;
            }

            $res = $db->query('SELECT * FROM `' . $table . '`');
            if (!$res) {
                fclose($fp);
                throw new \RuntimeException('SELECT failed for ' . $table);
            }

            fwrite($fp, "\n-- " . $table . "\n");
            if ($db->num_rows($res) === 0) {
                continue;
            }

            while ($row = $db->fetch_object($res)) {
                $arr = get_object_vars($row);
                if ($arr === []) {
                    continue;
                }
                $cols = [];
                $vals = [];
                foreach ($arr as $k => $v) {
                    $cols[] = '`' . str_replace('`', '``', (string) $k) . '`';
                    $vals[] = self::sqlLiteral($db, $v);
                }
                fwrite(
                    $fp,
                    'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n"
                );
            }
        }

        fwrite($fp, 'SET FOREIGN_KEY_CHECKS=1;' . "\n");
        fclose($fp);

        $zipPath = $targetDir . '.zip';
        if (!class_exists(\ZipArchive::class)) {
            return ['dir' => $targetDir, 'zip' => $sqlFile];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ZIP archive');
        }

        $zip->addFile($sqlFile, 'knot_tables.sql');

        $knotDocs = rtrim((string) constant('DOL_DATA_ROOT'), '/\\') . '/knot';
        self::zipTree($zip, $knotDocs, 'documents_knot/', $knotDocs . '/backups');

        $zip->close();

        return ['dir' => $targetDir, 'zip' => $zipPath];
    }

    /**
     * @param mixed $value
     */
    private static function sqlLiteral(DoliDB $db, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($value instanceof \DateTimeInterface) {
            return "'" . $db->escape($value->format('Y-m-d H:i:s')) . "'";
        }
        if ($value === true || $value === false) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && $value !== '' && is_numeric($value) && str_contains($value, 'e')) {
            return "'" . $db->escape($value) . "'";
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (string) (0 + $value);
        }

        return "'" . $db->escape((string) $value) . "'";
    }

    private static function zipTree(\ZipArchive $zip, string $dir, string $zipPrefix, string $skipDir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $skipReal = realpath($skipDir);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $real = realpath($path);
            if ($skipReal !== false && $real !== false && str_starts_with($real, $skipReal)) {
                continue;
            }
            $rel = substr($path, strlen($dir) + 1);
            $zip->addFile($path, $zipPrefix . str_replace('\\', '/', $rel));
        }
    }
}
