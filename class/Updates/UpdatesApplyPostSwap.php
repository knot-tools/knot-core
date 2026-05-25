<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use Knot\Extension\ExtensionRegistry;
use Knot\Migration\Migrator;
use Knot\Repository\AuditLogRepository;
use RuntimeException;

/**
 * Shared post-swap migration + rollback workflow for updates_apply.php.
 */
final class UpdatesApplyPostSwap
{
    /**
     * @return array{
     *   migrations: array<int, array{version: string, file: string, status: string, durationMs: int}>,
     *   rollback: 'restored'|'failed'|'none'
     * }
     */
    public static function migrateCore(
        object $db,
        Installer $installer,
        string $liveTarget,
        AuditLogRepository $audit,
        int $entityId,
        ?int $userId,
        string $slug,
    ): array {
        if (!class_exists(Migrator::class)) {
            $installer->commitSwap();

            return ['migrations' => [], 'rollback' => 'none'];
        }

        try {
            $migrationLog = (new Migrator($db, $liveTarget))->run();
            self::audit($audit, $entityId, $userId, 'updates.apply.migrated', $slug, [
                'path' => $liveTarget,
                'count' => count($migrationLog),
            ]);
            $installer->commitSwap();

            return ['migrations' => $migrationLog, 'rollback' => 'none'];
        } catch (\Throwable $e) {
            return self::failMigration($installer, $audit, $entityId, $userId, $slug, $liveTarget, [], $e);
        }
    }

    /**
     * @return array{
     *   migrations: array<int, array{version: string, file: string, status: string, durationMs: int}>,
     *   rollback: 'restored'|'failed'|'none'
     * }
     */
    public static function migrateExtension(
        object $db,
        Installer $installer,
        string $liveTarget,
        AuditLogRepository $audit,
        int $entityId,
        ?int $userId,
        string $slug,
    ): array {
        try {
            $migrationLog = (new ExtensionPostApplyRunner())->run($db, $liveTarget);
            if ($migrationLog !== []) {
                self::audit($audit, $entityId, $userId, 'updates.apply.migrated', $slug, [
                    'path' => $liveTarget,
                    'count' => count($migrationLog),
                ]);
            }
            $installer->commitSwap();

            return ['migrations' => $migrationLog, 'rollback' => 'none'];
        } catch (\Throwable $e) {
            return self::failMigration($installer, $audit, $entityId, $userId, $slug, $liveTarget, [], $e);
        }
    }

    public static function clearExtensionRegistryCache(): void
    {
        (new ExtensionRegistry())->clearCache();
    }

    /**
     * @param array<int, array{version: string, file: string, status: string, durationMs: int}> $migrationLog
     * @return array{
     *   migrations: array<int, array{version: string, file: string, status: string, durationMs: int}>,
     *   rollback: 'restored'|'failed'|'none',
     *   message: string
     * }
     */
    private static function failMigration(
        Installer $installer,
        AuditLogRepository $audit,
        int $entityId,
        ?int $userId,
        string $slug,
        string $liveTarget,
        array $migrationLog,
        \Throwable $e,
    ): array {
        $rollback = 'failed';
        if ($installer->canRollback()) {
            $rollback = $installer->rollback() ? 'restored' : 'failed';
        }

        self::audit($audit, $entityId, $userId, 'updates.apply.failed', $slug, [
            'path' => $liveTarget,
            'error' => $e->getMessage(),
            'rollback' => $rollback,
        ]);
        if ($rollback === 'restored') {
            self::audit($audit, $entityId, $userId, 'updates.apply.rolled_back', $slug, [
                'path' => $liveTarget,
            ]);
        }

        return [
            'migrations' => $migrationLog,
            'rollback' => $rollback,
            'message' => 'Module files were updated but database migration failed: ' . $e->getMessage(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function audit(
        AuditLogRepository $audit,
        int $entityId,
        ?int $userId,
        string $action,
        string $slug,
        array $payload,
    ): void {
        $payload['slug'] = $slug;
        $audit->record($action, 'updates', null, $userId, $payload, $entityId);
    }

    public static function migrationFailureHttpStatus(string $rollback): int
    {
        return $rollback === 'restored' ? 422 : 500;
    }
}
