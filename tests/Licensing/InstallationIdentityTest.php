<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing;

use Knot\Licensing\InstallationIdentity;
use Knot\Repository\KnotConfigRepository;
use Knot\Tests\Licensing\Support\InMemoryInstallationDb;
use PHPUnit\Framework\TestCase;

final class InstallationIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['conf'], $GLOBALS['knot_test_globals_string']);
        parent::tearDown();
    }

    public function testDeploymentTokenIsStablePerUrlAndDatabaseFingerprint(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://ERP.EXAMPLE.invalid/dolibarr/'];
        $db = new InMemoryInstallationDb('db-master.internal', 'mycompany');
        $config = new KnotConfigRepository($db);
        $GLOBALS['conf'] = (object) ['entity' => 1];

        $a = (new InstallationIdentity($config, $db))->deploymentToken();
        $b = (new InstallationIdentity($config, $db))->deploymentToken();
        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function testDeploymentTokenChangesWithDatabaseFingerprint(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://same.example.invalid/'];

        $db1 = new InMemoryInstallationDb('host-a', 'dbone');
        $GLOBALS['conf'] = (object) ['entity' => 1];

        $t1 = (new InstallationIdentity(new KnotConfigRepository($db1), $db1))->deploymentToken();

        $db2 = new InMemoryInstallationDb('host-a', 'dbtwo');
        $t2 = (new InstallationIdentity(new KnotConfigRepository($db2), $db2))->deploymentToken();

        self::assertNotSame($t1, $t2);
    }

    public function testDeploymentNonceIsDistinctAcrossEntitiesSharingDatabase(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://shared.invalid/'];

        $db = new InMemoryInstallationDb('db.internal', 'shared');

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $id1 = new InstallationIdentity(new KnotConfigRepository($db), $db);
        $nonce1 = $id1->deploymentNonce();

        $GLOBALS['conf'] = (object) ['entity' => 2];
        $id2 = new InstallationIdentity(new KnotConfigRepository($db), $db);
        $nonce2 = $id2->deploymentNonce();

        $GLOBALS['conf'] = (object) ['entity' => 1];
        self::assertSame($nonce1, (new InstallationIdentity(new KnotConfigRepository($db), $db))->deploymentNonce());

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $nonce1,
        );
        self::assertNotSame($nonce1, $nonce2);
        self::assertSame($id1->deploymentToken(), $id2->deploymentToken());
    }

    public function testDeploymentHeaderLinesIncludeTokenAndNonce(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://erp.example.invalid/'];
        $db = new InMemoryInstallationDb('db.internal', 'shared');
        $GLOBALS['conf'] = (object) ['entity' => 1];
        $identity = new InstallationIdentity(new KnotConfigRepository($db), $db);

        $headers = $identity->deploymentHeaderLines();
        self::assertSame('X-Knot-Deployment-Token: ' . $identity->deploymentToken(), $headers[0]);
        self::assertSame('X-Knot-Deployment-Nonce: ' . $identity->deploymentNonce(), $headers[1]);
    }

    public function testNormalizeDbHostStripsDefaultMysqlPort(): void
    {
        self::assertSame('db.example.invalid', InstallationIdentity::normalizeDbHost('DB.EXAMPLE.invalid:3306'));
        self::assertSame('127.0.0.1', InstallationIdentity::normalizeDbHost('localhost'));
        self::assertSame('/var/run/mysqld/mysqld.sock', InstallationIdentity::normalizeDbHost('/var/run/mysqld/mysqld.sock'));
        self::assertSame('127.0.0.1', InstallationIdentity::normalizeDbHost('[::1]'));
        self::assertSame('[2001:db8::1]', InstallationIdentity::normalizeDbHost('[2001:DB8::1]:3306'));
        self::assertSame('', InstallationIdentity::normalizeDbHost(''));
    }

    public function testNormalizeUrlRootStripsDefaultHttpPorts(): void
    {
        self::assertSame(
            'https://erp.example.invalid/dolibarr',
            InstallationIdentity::normalizeUrlRoot('HTTPS://ERP.example.invalid:443/dolibarr/')
        );
        self::assertSame(
            'http://erp.example.invalid',
            InstallationIdentity::normalizeUrlRoot('http://erp.example.invalid:80')
        );
    }

    public function testNormalizeUrlRootFallsBackWhenHostMissing(): void
    {
        self::assertSame('relative/path', InstallationIdentity::normalizeUrlRoot('relative/path/'));
    }

    public function testNormalizeDbHostKeepsNonDefaultMysqlPort(): void
    {
        self::assertSame('db.example.invalid:3307', InstallationIdentity::normalizeDbHost('db.example.invalid:3307'));
    }

    public function testNormalizeDbNameLowercasesTrimmedValue(): void
    {
        self::assertSame('example_db', InstallationIdentity::normalizeDbName(' Example_DB '));
        self::assertSame('', InstallationIdentity::normalizeDbName(''));
    }

    public function testNormalizeCanonicalPublicUrlUsesMainUrlPublicConstant(): void
    {
        $GLOBALS['knot_test_globals_string'] = ['MAIN_URL_PUBLIC' => 'https://ERP.EXAMPLE.invalid/dolibarr/'];
        self::assertSame(
            'https://erp.example.invalid/dolibarr',
            InstallationIdentity::normalizeCanonicalPublicUrl()
        );
        unset($GLOBALS['knot_test_globals_string']);
    }

    public function testKnotCoreUserAgentIncludesCurrentVersion(): void
    {
        self::assertMatchesRegularExpression(
            '/^Knot-CatalogClient\/\d+\.\d+\.\d+$/',
            InstallationIdentity::knotCoreUserAgent('CatalogClient')
        );
    }
}
