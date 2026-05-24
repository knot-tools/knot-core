<?php

declare(strict_types=1);

namespace Knot\Tests\Marketplace;

use Knot\Marketplace\CatalogClient;
use Knot\Marketplace\CatalogClientFactory;
use Knot\Tests\Licensing\Support\InMemoryInstallationDb;
use PHPUnit\Framework\TestCase;

final class CatalogClientFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['knot_test_globals_string']);
        parent::tearDown();
    }

    public function testResolveBaseUrlFallsBackWhenConstantUnset(): void
    {
        self::assertSame(CatalogClient::DEFAULT_BASE_URL, CatalogClientFactory::resolveBaseUrl());
    }

    public function testResolveBaseUrlUsesDolibarrGlobal(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'MAIN_KNOT_LICENSE_BASE_URL' => 'https://mirror.example.test',
        ];

        self::assertSame('https://mirror.example.test', CatalogClientFactory::resolveBaseUrl());
    }

    public function testCreateWithExplicitBaseBypassesGlobals(): void
    {
        $GLOBALS['knot_test_globals_string'] = [
            'MAIN_KNOT_LICENSE_BASE_URL' => 'https://mirror.example.test',
        ];
        $client = CatalogClientFactory::create('https://direct.example.test');
        $reflection = new \ReflectionClass(CatalogClient::class);
        $prop = $reflection->getProperty('baseUrl');

        self::assertSame('https://direct.example.test', $prop->getValue($client));
    }

    public function testCreateWithDbInjectsInstallationIdentityIntoCatalogClient(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://catalog-factory.invalid/erp'];
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $db = new InMemoryInstallationDb('maria.catalog', 'dolibarr');
        try {
            $client = CatalogClientFactory::create('https://mirror.example.invalid/', $db);
            $ref = new \ReflectionClass(CatalogClient::class);
            foreach (['deploymentToken', 'deploymentNonce'] as $name) {
                $prop = $ref->getProperty($name);
                $v = (string) $prop->getValue($client);
                self::assertNotSame('', trim($v), $name);
            }
        } finally {
            unset($GLOBALS['conf']);
        }
    }

}
