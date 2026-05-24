<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Extension\ExtensionRegistry;
use Knot\Extension\LicenseValidator;
use Knot\Extension\ManifestSchema;
use Knot\Version;
use PHPUnit\Framework\TestCase;

/**
 * Guards Knot Migration floor Knot Core >= 2.12.0 (beta 0.21).
 */
final class ExtensionRegistryMigrationCompatibilityTest extends TestCase
{
    private string $tmpDir;
    private string $licenseDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-mig-compat-' . uniqid();
        $this->licenseDir = $this->tmpDir . '/_licenses';
        mkdir($this->tmpDir, 0o755, true);
        mkdir($this->licenseDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmtree($this->tmpDir);
    }

    public function testSemverFloorRejectsCore211(): void
    {
        self::assertFalse(ManifestSchema::satisfies('2.11.0', '>=2.12.0'));
        self::assertTrue(ManifestSchema::satisfies('2.12.0', '>=2.12.0'));
        self::assertTrue(ManifestSchema::satisfies('2.12.1', '>=2.12.0'));
    }

    public function testMigrationLikeManifestCompatibleWithCurrentCore(): void
    {
        $this->writeExtension('knotmigration', $this->migrationManifest());

        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = $registry->discover()['knot-migration'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(
            ExtensionRegistry::STATUS_LICENSE_INVALID,
            $entry['status'],
            'Commercial manifest without cached licence must not be STATUS_INCOMPATIBLE when Core satisfies >=2.12.0',
        );
        self::assertStringNotContainsString('requires Knot >=2.12.0 but current is', (string) ($entry['error'] ?? ''));
    }

    public function testMigrationLikeManifestIncompatibleWhenCoreBelow212(): void
    {
        $current = Version::current();
        if (ManifestSchema::satisfies($current, '>=2.12.0') && !ManifestSchema::satisfies($current, '>=2.13.0')) {
            $this->writeExtension('knotmigration', $this->migrationManifest('>=2.13.0'));
        } else {
            $this->writeExtension('knotmigration', $this->migrationManifest('>=99.0.0'));
        }

        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = $registry->discover()['knot-migration'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_INCOMPATIBLE, $entry['status']);
        self::assertStringContainsString('requires Knot', (string) $entry['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationManifest(string $knotRange = '>=2.12.0'): array
    {
        return [
            'id' => 'knot-migration',
            'label' => 'Knot Migration',
            'version' => '0.21.0',
            'author' => 'Knot Tools',
            'category' => 'premium',
            'license' => [
                'type' => 'commercial',
                'validation' => 'dolistore',
                'productId' => 'knot-migration',
            ],
            'requires' => [
                'knot' => $knotRange,
                'dolibarr' => '>=20.0',
            ],
            'connectors' => [],
            'ui' => [
                'menu' => [
                    'label' => 'Knot Migration',
                    'labelLang' => 'KnotMigrationMenuRoot@knotmigration',
                    'mode' => 'migration',
                    'section' => 'dashboard',
                    'placement' => 'start',
                    'icon' => 'route',
                    'position' => 10,
                ],
                'bundle' => [
                    'js' => 'dist/knot-migration-extension.js',
                    'css' => 'dist/knot-migration-extension.css',
                    'globalEntry' => 'KnotMigrationExtension',
                ],
                'requiredPermission' => 'knotmigration.use',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeExtension(string $folder, array $manifest): void
    {
        $dir = $this->tmpDir . '/' . $folder;
        mkdir($dir, 0o755, true);
        file_put_contents(
            $dir . '/knot-extension.json',
            (string) json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    private function rmtree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmtree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
