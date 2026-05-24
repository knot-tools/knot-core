<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Static audit of Knot internal HTTP endpoints under {@see api/}.
 *
 * Full anonymous HTTP assertions require a bootstrapped Dolibarr web stack; this
 * test keeps a documented registry and enforces CSRF markers on POST mutators.
 */
final class InternalApiAuthMatrixTest extends TestCase
{
    private const CORE_ROOT = __DIR__ . '/../..';

    /** Basenames that intentionally define NOLOGIN (public or Prometheus scrape). */
    private const NOLOGIN_ALLOWED = [
        'metrics.php',
        'webhook.php',
    ];

    /**
     * Registry of every {@see api/*.php} script excluding *.doc.php companions.
     *
     * Keys:
     * - expect_csrf_verify: endpoint handles POST (or other mutation) that must call
     *   {@see \Knot\Api\CsrfGuard::verify()} or {@see \Knot\Api\ApiAuth::requireCsrf()}.
     * - allows_nologin: NOLOGIN define is expected (see NOLOGIN_ALLOWED).
     *
     * @var array<string, array{expect_csrf_verify?: bool, allows_nologin?: bool, note?: string}>
     */
    private const ENDPOINT_META = [
        'approvals.php' => ['expect_csrf_verify' => true],
        'assistant.php' => ['note' => 'POST returns prompt JSON only; session UI origin — CSRF not enforced here.'],
        'audit.php' => [],
        'bundled_templates.php' => [],
        'capabilities.php' => [],
        'compatibility.php' => ['expect_csrf_verify' => true],
        'conflicts.php' => [],
        'connectors.php' => [],
        'credentials.php' => ['expect_csrf_verify' => true],
        'dolibarr_picker.php' => [],
        'dolibarr_schemas.php' => ['expect_csrf_verify' => true],
        'execute.php' => ['expect_csrf_verify' => true],
        'executions.php' => ['expect_csrf_verify' => true],
        'extension_state.php' => ['expect_csrf_verify' => true],
        'folders.php' => ['expect_csrf_verify' => true],
        'gallery.php' => [],
        'health.php' => [],
        'knot_cron_tick.php' => ['expect_csrf_verify' => true],
        'license_activate.php' => ['expect_csrf_verify' => true],
        'license_deactivate.php' => ['expect_csrf_verify' => true],
        'license_download_token.php' => ['expect_csrf_verify' => true],
        'license_status.php' => [],
        'marketplace.php' => [],
        'metrics.php' => ['allows_nologin' => true],
        'migration_scan.php' => [],
        'oauth.php' => ['note' => 'OAuth start/callback uses state secret and GETPOST; CSRF guard differs from Knot JSON POST mutators.'],
        'observability.php' => [],
        'onboarding.php' => ['expect_csrf_verify' => true],
        'schedules.php' => ['expect_csrf_verify' => true],
        'state_machine.php' => ['expect_csrf_verify' => true],
        'templates.php' => ['expect_csrf_verify' => true],
        'updates.php' => [],
        'updates_apply.php' => ['expect_csrf_verify' => true],
        'variables.php' => ['expect_csrf_verify' => true],
        'webhook.php' => ['allows_nologin' => true],
        'webhooks.php' => ['expect_csrf_verify' => true],
        'workflows.php' => ['expect_csrf_verify' => true],
    ];

    public function testDiscoveredEndpointsMatchRegistry(): void
    {
        $discovered = $this->discoverEndpointBasenames();
        $registered = array_keys(self::ENDPOINT_META);
        sort($discovered);
        sort($registered);
        self::assertSame(
            $discovered,
            $registered,
            'Update InternalApiAuthMatrixTest::ENDPOINT_META when adding or removing api/*.php endpoints.',
        );
    }

    public function testCsrfMarkersOnMutators(): void
    {
        foreach (self::ENDPOINT_META as $basename => $meta) {
            if (!($meta['expect_csrf_verify'] ?? false)) {
                continue;
            }
            $src = $this->readApiSource($basename);
            self::assertTrue(
                str_contains($src, 'CsrfGuard::verify(') || str_contains($src, 'ApiAuth::requireCsrf('),
                sprintf('%s must call CsrfGuard::verify() or ApiAuth::requireCsrf() before mutating state.', $basename),
            );
        }
    }

    public function testNologinOnlyOnAllowlistedEndpoints(): void
    {
        foreach ($this->discoverEndpointBasenames() as $basename) {
            $src = $this->readApiSource($basename);
            if (!preg_match("/define\\(\\s*['\"]NOLOGIN['\"]/", $src)) {
                continue;
            }
            self::assertContains(
                $basename,
                self::NOLOGIN_ALLOWED,
                sprintf(
                    '%s defines NOLOGIN — document why in InternalApiAuthMatrixTest::NOLOGIN_ALLOWED or remove it.',
                    $basename,
                ),
            );
            self::assertTrue(
                (bool) (self::ENDPOINT_META[$basename]['allows_nologin'] ?? false),
                sprintf('%s must set allows_nologin in ENDPOINT_META.', $basename),
            );
        }
    }

    /** @return list<string> */
    private function discoverEndpointBasenames(): array
    {
        $apiDir = self::CORE_ROOT . '/api';
        $out = [];
        foreach (glob($apiDir . '/*.php') ?: [] as $path) {
            $base = basename((string) $path);
            if (str_ends_with((string) $base, '.doc.php')) {
                continue;
            }
            $out[] = $base;
        }

        return $out;
    }

    private function readApiSource(string $basename): string
    {
        $path = self::CORE_ROOT . '/api/' . $basename;
        self::assertFileExists($path);

        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }
}
