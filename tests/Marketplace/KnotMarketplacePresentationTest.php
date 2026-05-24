<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\KnotMarketplacePresentation;
use PHPUnit\Framework\TestCase;

final class KnotMarketplacePresentationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['knot_test_globals_string']);
        parent::tearDown();
    }

    public function testMarketplaceUiEnabledDefaultsToTrue(): void
    {
        unset($GLOBALS['knot_test_globals_string']);
        self::assertTrue(KnotMarketplacePresentation::marketplaceUiEnabled());
    }

    public function testMarketplaceUiDisabledWhenConstZero(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_MARKETPLACE_UI_ENABLED' => '0',
        ];
        self::assertFalse(KnotMarketplacePresentation::marketplaceUiEnabled());
    }

    public function testMarketplaceUiDisabledWhenConstOffSynonym(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_MARKETPLACE_UI_ENABLED' => 'off',
        ];
        self::assertFalse(KnotMarketplacePresentation::marketplaceUiEnabled());
    }

    public function testMarketplaceUiEnabledWhenExplicitOne(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_MARKETPLACE_UI_ENABLED' => '1',
        ];
        self::assertTrue(KnotMarketplacePresentation::marketplaceUiEnabled());
    }

    public function testConnectorMetadataFetchEnabledDefaultsToTrue(): void
    {
        unset($GLOBALS['knot_test_globals_string']);
        self::assertTrue(KnotMarketplacePresentation::connectorMetadataFetchEnabled());
    }

    public function testConnectorMetadataFetchDisabledWhenConstZero(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_CONNECTOR_METADATA_FETCH' => '0',
        ];
        self::assertFalse(KnotMarketplacePresentation::connectorMetadataFetchEnabled());
    }

    public function testConnectorMetadataFetchDisabledWhenConstEmpty(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_CONNECTOR_METADATA_FETCH' => '',
        ];
        self::assertFalse(KnotMarketplacePresentation::connectorMetadataFetchEnabled());
    }

    public function testConnectorMetadataFetchDisabledWhenConstFalseSynonym(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'KNOT_CONNECTOR_METADATA_FETCH' => 'false',
        ];
        self::assertFalse(KnotMarketplacePresentation::connectorMetadataFetchEnabled());
    }
}
