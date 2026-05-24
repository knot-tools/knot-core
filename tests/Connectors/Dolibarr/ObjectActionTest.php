<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Dolibarr;

use Knot\Connectors\Dolibarr\ObjectAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Connectors\Dolibarr\ObjectAction
 */
final class ObjectActionTest extends TestCase
{
    private static bool $aliasesRegistered = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$aliasesRegistered) {
            return;
        }
        if (!class_exists('Facture', false)) {
            class_alias(\FakeFacture::class, 'Facture');
        }
        if (!class_exists('FactureLigne', false)) {
            class_alias(\FakeFactureLigne::class, 'FactureLigne');
        }
        self::$aliasesRegistered = true;
    }

    /**
     * Make `$db` and `$user` globals available to the connector since it
     * uses them with `global` declarations.
     */
    private function bootGlobals(\FakeUser $user): \DoliDB
    {
        $db = new class extends \DoliDB {};
        $GLOBALS['db'] = $db;
        $GLOBALS['user'] = $user;
        return $db;
    }

    public function testValidateRequiresOperationAndObjectType(): void
    {
        $action = new ObjectAction();
        $report = $action->validate([]);

        self::assertFalse($report['valid']);
        self::assertContains('operation is required', $report['errors']);
        self::assertContains('objectType is required', $report['errors']);
    }

    public function testFetchOperationSkipsPermissionCheck(): void
    {
        $user = new \FakeUser();
        // No rights granted at all — fetch should still proceed because
        // it's a read-only operation in our gating model.
        $this->bootGlobals($user);

        $action = new ObjectAction();

        $context = [
            'node' => [
                'config' => [
                    'operation' => 'fetch',
                    'objectType' => 'facture',
                    'id' => '0',
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/object id is required/i');
        $action->execute($context);
    }

    public function testFetchExportsDescribedFieldsWithFkSocAliasAndLines(): void
    {
        $user = new \FakeUser();
        $this->bootGlobals($user);

        $action = new ObjectAction();
        $out = $action->execute([
            'node' => [
                'config' => [
                    'operation' => 'fetch',
                    'objectType' => 'facture',
                    'id' => '42',
                ],
            ],
        ]);

        self::assertSame(771, $out['object']['fk_soc'] ?? null, 'fk_soc must be readable via socid alias');
        self::assertArrayHasKey('rowid', $out['object']);
        self::assertSame(42, $out['object']['rowid'] ?? null);
        self::assertArrayHasKey('lines', $out['object']);
        self::assertCount(1, $out['object']['lines']);
        self::assertSame(2.5, $out['object']['lines'][0]['qty'] ?? null);
        self::assertSame(20.0, $out['object']['lines'][0]['tva_tx'] ?? null);
    }

    public function testCreateRefusesUserWithoutPermission(): void
    {
        $user = new \FakeUser(); // no rights
        $this->bootGlobals($user);

        $action = new ObjectAction();
        $context = [
            'node' => [
                'config' => [
                    'operation' => 'create',
                    'objectType' => 'facture',
                    'fields' => ['ref' => 'TEST', 'fk_soc' => 1, 'status' => 0],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Permission denied for facture\.create/');
        $action->execute($context);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $user = new \FakeUser();
        $user->rightsMap = ['facture' => ['creer' => true]];
        $this->bootGlobals($user);

        $action = new ObjectAction();
        $context = [
            'node' => [
                'config' => [
                    'operation' => 'create',
                    'objectType' => 'facture',
                    'fields' => [], // missing ref, fk_soc, status
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing required field\(s\) for facture\.create/');
        $action->execute($context);
    }

    public function testCreateDropsUnknownFieldsWithWarning(): void
    {
        $user = new \FakeUser();
        $user->rightsMap = ['facture' => ['creer' => true]];
        $this->bootGlobals($user);

        // We can't run the actual `->create($user)` path against our stub
        // (Facture stub has no create()), so we exercise the validation step
        // by invoking the private validateAndCoercePayload via reflection.
        $action = new ObjectAction();

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('validateAndCoercePayload');

        $factory = new \Knot\Dolibarr\ObjectFactory();
        $db = new class extends \DoliDB {};

        $result = $method->invoke(
            $action,
            $factory,
            $db,
            'facture',
            'create',
            ['ref' => 'F-001', 'fk_soc' => 12, 'status' => 1, 'unknown_field' => 'oops'],
            [],
            null,
            false
        );

        $matches = array_filter($result['warnings'], static fn (string $w) => str_contains($w, 'unknown_field'));
        self::assertNotEmpty($matches, 'unknown_field should be dropped with a warning');
        self::assertArrayNotHasKey('unknown_field', $result['fields']);
        self::assertSame('F-001', $result['fields']['ref']);
        self::assertSame(12, $result['fields']['fk_soc']);
    }

    public function testCoerceTurnsStringNumberIntoInteger(): void
    {
        $user = new \FakeUser();
        $user->rightsMap = ['facture' => ['creer' => true]];
        $this->bootGlobals($user);

        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('validateAndCoercePayload');

        $factory = new \Knot\Dolibarr\ObjectFactory();
        $db = new class extends \DoliDB {};

        $result = $method->invoke(
            $action,
            $factory,
            $db,
            'facture',
            'create',
            // fk_soc is integer in schema, sending it as string '42' should coerce.
            ['ref' => 'F-002', 'fk_soc' => '42', 'status' => '1'],
            [],
            null,
            false
        );

        self::assertSame(42, $result['fields']['fk_soc']);
        self::assertSame(1, $result['fields']['status']);
    }

    public function testValidateAndCoercePayloadAcceptsLinesFromSchema(): void
    {
        $user = new \FakeUser();
        $user->rightsMap = ['facture' => ['creer' => true]];
        $this->bootGlobals($user);

        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('validateAndCoercePayload');

        $factory = new \Knot\Dolibarr\ObjectFactory();
        $db = new class extends \DoliDB {};

        $result = $method->invoke(
            $action,
            $factory,
            $db,
            'facture',
            'create',
            ['ref' => 'F-003', 'fk_soc' => 1, 'status' => 0],
            [['desc' => 'Item A', 'qty' => '2', 'subprice' => '99.50'], ['desc' => 'Item B', 'qty' => 1, 'extra_unknown' => 'x']],
            null,
            false
        );

        self::assertCount(2, $result['lines']);
        self::assertSame('Item A', $result['lines'][0]['desc']);
        self::assertSame(2.0, $result['lines'][0]['qty']);
        self::assertArrayNotHasKey('extra_unknown', $result['lines'][1]);
    }
}
