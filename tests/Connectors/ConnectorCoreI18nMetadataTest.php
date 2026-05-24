<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Core connectors must expose vue-i18n keys in metadata and JSON-schema-like
 * config (no English prose in label/title/description/enumLabels fields).
 */
final class ConnectorCoreI18nMetadataTest extends TestCase
{
    /** @return iterable<string, array{object}>
     */
    public static function coreConnectors(): iterable
    {
        $registry = new ConnectorRegistry();
        foreach ($registry->all() as $id => $connector) {
            yield $id => [$connector];
        }
    }

    /**
     * @dataProvider coreConnectors
     */
    public function testMetadataUsesLabelKey(object $connector): void
    {
        self::assertTrue(method_exists($connector, 'getMetadata'));
        /** @var array<string, mixed> $meta */
        $meta = $connector->getMetadata();

        self::assertArrayHasKey('labelKey', $meta, 'Core connector must expose labelKey');
        self::assertStringStartsWith('connectors.', (string) $meta['labelKey']);
        self::assertArrayNotHasKey('label', $meta, 'Core connector must omit English label');

        if (isset($meta['descriptionKey'])) {
            self::assertStringStartsWith('connectors.', (string) $meta['descriptionKey']);
        }
        self::assertArrayNotHasKey('description', $meta, 'Core connector must omit English description');
    }

    /**
     * @dataProvider coreConnectors
     */
    public function testConfigSchemaUsesI18nKeys(object $connector): void
    {
        self::assertTrue(method_exists($connector, 'getConfigSchema'));
        /** @var array<string, mixed> $schema */
        $schema = $connector->getConfigSchema();
        $this->assertSchemaFragmentHasNoLegacyCopy($schema);
    }

    /**
     * Reject legacy English copy in schema fragments. Field names can be
     * `title`/`description` (e.g. notification.alert config); only the JSON-Schema
     * keywords `title`/`description` with string values are invalid.
     *
     * @param array<string, mixed>|list<mixed> $node
     */
    private function assertSchemaFragmentHasNoLegacyCopy(array $node): void
    {
        if (isset($node['title']) && is_string($node['title'])) {
            $this->fail('JSON schema fragment must use titleKey, not title');
        }
        if (isset($node['description']) && is_string($node['description'])) {
            $this->fail('JSON schema fragment must use descriptionKey, not description');
        }
        if (isset($node['enumLabels'])) {
            $this->fail('JSON schema fragment must use enumLabelKeys, not enumLabels');
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->assertSchemaFragmentHasNoLegacyCopy($value);
            }
        }
    }
}
