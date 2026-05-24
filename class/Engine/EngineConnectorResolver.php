<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

use Knot\Connectors\ConnectorRegistry;
use Knot\Extension\ExtensionRegistry;
use Knot\Licensing\Bootstrap;
use Throwable;

/**
 * Resolves the connector map and validator allowlist for workflow execution.
 *
 * Merges Core connectors with licensed extension connectors when
 * {@see Bootstrap::buildExtensionRegistry()} succeeds. On failure, falls
 * back to Core-only connectors so cron ticks never abort entirely.
 *
 * Results are memoized per entity for the lifetime of the PHP process
 * (one cron worker invocation typically calls {@see resolve()} once).
 */
final class EngineConnectorResolver
{
    /** @var array<int, EngineConnectorBundle> */
    private static array $cacheByEntity = [];

    /**
     * When true, {@see buildBundle()} throws before resolving extensions (PHPUnit only).
     */
    private static bool $simulateRegistryFailureForTests = false;

    /**
     * @return array{
     *     connectors: array<string, mixed>,
     *     allowlist: list<string>,
     *     degraded: bool
     * }
     */
    public static function resolve(\DoliDB $db, int $entity): array
    {
        if (isset(self::$cacheByEntity[$entity])) {
            return self::$cacheByEntity[$entity]->toArray();
        }

        $bundle = self::buildBundle($db);
        self::$cacheByEntity[$entity] = $bundle;

        return $bundle->toArray();
    }

    /**
     * Clears the process-wide memoization cache (PHPUnit only).
     */
    public static function resetCacheForTests(): void
    {
        self::$cacheByEntity = [];
        self::$simulateRegistryFailureForTests = false;
    }

    /**
     * Force the next {@see resolve()} call(s) to exercise the Core-only fallback path.
     */
    public static function simulateRegistryFailureForTests(bool $simulate): void
    {
        self::$simulateRegistryFailureForTests = $simulate;
        self::$cacheByEntity = [];
    }

    private static function buildBundle(\DoliDB $db): EngineConnectorBundle
    {
        $coreRegistry = new ConnectorRegistry();

        try {
            if (self::$simulateRegistryFailureForTests) {
                throw new \RuntimeException('Simulated extension registry failure for tests.');
            }

            $extensions = Bootstrap::buildExtensionRegistry($db);
            $connectors = $coreRegistry->allWithExtensions($extensions);

            return new EngineConnectorBundle(
                $connectors,
                array_keys($connectors),
                false,
            );
        } catch (Throwable $throwable) {
            error_log(
                '[knot engine] extension registry unavailable, using core-only connectors: '
                . $throwable->getMessage()
            );
            $connectors = $coreRegistry->all();

            return new EngineConnectorBundle(
                $connectors,
                array_keys($connectors),
                true,
            );
        }
    }
}
