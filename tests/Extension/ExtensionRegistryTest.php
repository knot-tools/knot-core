<?php

declare(strict_types=1);

namespace Knot\Tests\Extension;

use Knot\Connectors\ConnectorInterface;
use Knot\Extension\ExtensionRegistry;
use Knot\Extension\LicenseValidator;
use PHPUnit\Framework\TestCase;

final class ExtensionRegistryTest extends TestCase
{
    private string $tmpDir;
    private string $licenseDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-ext-test-' . uniqid();
        $this->licenseDir = $this->tmpDir . '/_licenses';
        mkdir($this->tmpDir, 0o755, true);
        mkdir($this->licenseDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmtree($this->tmpDir);
    }

    public function testEmptyRootReturnsNoExtensions(): void
    {
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        self::assertSame([], $registry->discover());
    }

    public function testInvalidManifestSurfacedAsInvalid(): void
    {
        $this->writeExtension('modKnotBroken', ['id' => 'BadId']); // uppercase
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $extensions = $registry->discover();
        self::assertCount(1, $extensions);
        $entry = array_values($extensions)[0];
        self::assertSame(ExtensionRegistry::STATUS_INVALID_MANIFEST, $entry['status']);
        self::assertNotEmpty($entry['error']);
    }

    public function testCommercialExtensionMissingLicenseFlaggedLicenseInvalid(): void
    {
        $this->writeExtension('modKnotStripe', $this->commercialManifest('knot-stripe-pro'));
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $extensions = $registry->discover();
        $entry = $extensions['knot-stripe-pro'] ?? null;
        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_LICENSE_INVALID, $entry['status']);
    }

    public function testExtensionDiscoveredUnderStandardDolibarrModuleFolder(): void
    {
        $this->writeExtension('knotpropack', [
            'id' => 'knot-free-demo',
            'label' => 'Demo Free',
            'version' => '0.1.0',
            'author' => 'Acme',
            'license' => ['type' => 'free', 'validation' => 'none'],
            'connectors' => [DemoExtensionConnector::class],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = $registry->discover()['knot-free-demo'] ?? null;
        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_LOADED, $entry['status'], (string) ($entry['error'] ?? ''));
    }

    public function testFreeExtensionWithBuiltInConnectorLoads(): void
    {
        $this->writeExtension('modKnotFree', [
            'id' => 'knot-free-demo',
            'label' => 'Demo Free',
            'version' => '0.1.0',
            'author' => 'Acme',
            'category' => 'third-party',
            'license' => ['type' => 'free', 'validation' => 'none'],
            'connectors' => [DemoExtensionConnector::class],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $extensions = $registry->discover();
        $entry = $extensions['knot-free-demo'] ?? null;
        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_LOADED, $entry['status'], (string) ($entry['error'] ?? ''));
        self::assertCount(1, $entry['connectors']);
        self::assertInstanceOf(ConnectorInterface::class, $entry['connectors'][0]);
    }

    public function testIncompatibleManifestRejected(): void
    {
        $this->writeExtension('modKnotFuture', [
            'id' => 'knot-future',
            'label' => 'Future',
            'version' => '1.0.0',
            'author' => 'A',
            'requires' => ['knot' => '>=99.0.0', 'dolibarr' => '*'],
            'connectors' => [],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = $registry->discover()['knot-future'] ?? null;
        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_INCOMPATIBLE, $entry['status']);
        self::assertStringContainsString('requires Knot >=99.0.0', (string) $entry['error']);
    }

    public function testMissingConnectorClassRejected(): void
    {
        $this->writeExtension('modKnotMissing', [
            'id' => 'knot-missing',
            'label' => 'Missing',
            'version' => '1.0.0',
            'author' => 'A',
            'connectors' => ['Knot\\Extension\\NotInstalled\\Bogus'],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = $registry->discover()['knot-missing'] ?? null;
        self::assertNotNull($entry);
        self::assertSame(ExtensionRegistry::STATUS_CONNECTOR_MISSING, $entry['status']);
    }

    public function testCacheIsReusedAcrossCalls(): void
    {
        $this->writeExtension('modKnotFree', [
            'id' => 'knot-free-demo',
            'label' => 'Demo Free',
            'version' => '0.1.0',
            'author' => 'Acme',
            'connectors' => [],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $first = $registry->discover();

        // Mutate the manifest after discovery — second call must still use cache
        file_put_contents(
            $this->tmpDir . '/modKnotFree/knot-extension.json',
            json_encode(['id' => 'changed-id', 'label' => 'X'])
        );
        $second = $registry->discover();
        self::assertSame($first, $second);

        // After clearCache the new manifest is picked up.
        $registry->clearCache();
        $third = $registry->discover();
        self::assertNotSame($first, $third);
    }

    public function testLoadedConnectorsExposesIdAndExtension(): void
    {
        $this->writeExtension('modKnotFree', [
            'id' => 'knot-free-demo',
            'label' => 'Demo Free',
            'version' => '0.1.0',
            'author' => 'Acme',
            'connectors' => [DemoExtensionConnector::class],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $loaded = $registry->loadedConnectors();
        self::assertArrayHasKey('demo.extension', $loaded);
        self::assertInstanceOf(ConnectorInterface::class, $loaded['demo.extension']['connector']);
        self::assertSame('knot-free-demo', $loaded['demo.extension']['extension']['id']);
    }

    public function testActiveExposesUiSectionWhenManifestDeclaresOne(): void
    {
        $this->writeExtension('modKnotMigration', [
            'id' => 'knot-migration',
            'label' => 'Knot Migration',
            'version' => '0.11.0',
            'author' => 'Knot Tools',
            'license' => ['type' => 'free', 'validation' => 'none'],
            'connectors' => [],
            'ui' => [
                'menu' => [
                    'label' => 'Knot Tools Migration',
                    'labelLang' => 'KnotMigrationMenuRoot@knotmigration',
                    'mode' => 'migration',
                    'section' => 'operations',
                    'icon' => 'package',
                    'position' => 50,
                ],
                'bundle' => [
                    'js' => 'dist/knot-extension.js',
                    'css' => 'dist/knot-extension.css',
                    'globalEntry' => 'KnotMigrationExtension',
                ],
            ],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $active = $registry->active();
        self::assertArrayHasKey('knot-migration', $active);
        $entry = $active['knot-migration'];
        self::assertIsArray($entry['ui']);
        self::assertSame('migration', $entry['ui']['menu']['mode']);
        self::assertSame('operations', $entry['ui']['menu']['section']);
        self::assertSame('KnotMigrationExtension', $entry['ui']['bundle']['globalEntry']);
    }

    public function testActiveExposesUiNullWhenManifestHasNone(): void
    {
        $this->writeExtension('modKnotFree', [
            'id' => 'knot-free-demo',
            'label' => 'Demo Free',
            'version' => '0.1.0',
            'author' => 'Acme',
            'license' => ['type' => 'free', 'validation' => 'none'],
            'connectors' => [],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $active = $registry->active();
        self::assertArrayHasKey('knot-free-demo', $active);
        self::assertNull($active['knot-free-demo']['ui']);
    }

    public function testSkeletonForInvalidManifestStillCarriesUiKey(): void
    {
        $this->writeExtension('modKnotBroken', ['id' => 'BadId']);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $entry = array_values($registry->discover())[0];
        self::assertArrayHasKey('ui', $entry);
        self::assertNull($entry['ui']);
    }

    public function testLoadedConnectorsSkipsConnectorWhenGetMetadataThrows(): void
    {
        $this->writeExtension('modKnotMixed', [
            'id' => 'knot-mixed-meta',
            'label' => 'Mixed metadata',
            'version' => '0.1.0',
            'author' => 'Acme',
            'license' => ['type' => 'free', 'validation' => 'none'],
            'connectors' => [
                ThrowingMetadataConnector::class,
                DemoExtensionConnector::class,
            ],
        ]);
        $registry = new ExtensionRegistry([$this->tmpDir], new LicenseValidator($this->licenseDir));
        $loaded = $registry->loadedConnectors();
        self::assertArrayHasKey('demo.extension', $loaded);
        self::assertCount(1, $loaded);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeExtension(string $folder, array $manifest): void
    {
        $dir = $this->tmpDir . '/' . $folder;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/knot-extension.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    private function commercialManifest(string $id): array
    {
        return [
            'id' => $id,
            'label' => 'Stripe Pro',
            'version' => '1.0.0',
            'author' => 'Knot Team',
            'category' => 'pro',
            'license' => ['type' => 'commercial', 'validation' => 'local', 'productId' => '12345'],
            'connectors' => [DemoExtensionConnector::class],
        ];
    }

    private function rmtree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->rmtree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}

/**
 * Minimal in-suite connector used as a stand-in for real extension
 * connectors.
 */
/** Connector whose metadata raises — must not break {@see ExtensionRegistry::loadedConnectors()}. */
final class ThrowingMetadataConnector implements ConnectorInterface
{
    public function getMetadata(): array
    {
        throw new \RuntimeException('metadata unavailable');
    }

    public function getConfigSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [];
    }

    public function getOutputs(): array
    {
        return [];
    }

    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }

    public function execute(array $context): array
    {
        return ['ok' => true];
    }

    public function test(array $config): array
    {
        return ['ok' => true];
    }
}

final class DemoExtensionConnector implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'demo.extension',
            'label' => 'Demo Extension',
            'category' => 'other',
            'description' => 'Test only',
        ];
    }

    public function getConfigSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [];
    }

    public function getOutputs(): array
    {
        return [];
    }

    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }

    public function execute(array $context): array
    {
        return ['ok' => true];
    }

    public function test(array $config): array
    {
        return ['ok' => true];
    }
}
