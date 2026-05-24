<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Dolibarr;

use Knot\Connectors\Dolibarr\ObjectAction;
use Knot\Errors\PreconditionError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Extended execute/simulate coverage for {@see ObjectAction}.
 */
final class ObjectActionExtendedTest extends TestCase
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
        self::$aliasesRegistered = true;
    }

    private function bootGlobals(?\FakeUser $user = null): \DoliDB
    {
        $db = new class extends \DoliDB {};
        $GLOBALS['db'] = $db;
        $GLOBALS['user'] = $user ?? new \FakeUser();
        return $db;
    }

    private function adminUser(): \FakeUser
    {
        $user = new \FakeUser();
        $user->admin = 1;

        return $user;
    }

    public function testMetadataSchemaAndTestHook(): void
    {
        $action = new ObjectAction();
        $meta = $action->getMetadata();
        self::assertSame('dolibarr.object', $meta['id']);
        self::assertSame('critical', $meta['riskLevel']);

        $schema = $action->getConfigSchema();
        self::assertContains('operation', $schema['required']);

        self::assertNull($action->getCredentialType());
        self::assertNotEmpty($action->getInputs());
        self::assertNotEmpty($action->getOutputs());

        $test = $action->test(['operation' => 'fetch', 'objectType' => 'facture']);
        self::assertTrue($test['valid']);
        self::assertTrue($test['success']);
    }

    public function testExecuteRequiresAuthenticatedUser(): void
    {
        $this->bootGlobals(null);
        unset($GLOBALS['user']);

        $action = new ObjectAction();
        $this->expectException(PreconditionError::class);
        $action->execute([
            'node' => ['config' => ['operation' => 'fetch', 'objectType' => 'facture', 'id' => '5']],
        ]);
    }

    public function testExecuteRejectsUnsupportedOperation(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Dolibarr operation');
        $action->execute([
            'node' => ['config' => ['operation' => 'archive', 'objectType' => 'facture', 'id' => '1']],
        ]);
    }

    public function testSimulateFetchReturnsObjectPreview(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->simulate([
            'node' => ['config' => ['operation' => 'fetch', 'objectType' => 'facture', 'id' => '42']],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertSame('fetch', $out['operation']);
        self::assertSame(42, $out['id']);
        self::assertArrayHasKey('object', $out);
        self::assertStringContainsString('[DRY-RUN]', $out['message']);
    }

    public function testSimulateCreateCoercesPayload(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->simulate([
            'node' => [
                'config' => [
                    'operation' => 'create',
                    'objectType' => 'facture',
                    'fields' => ['ref' => 'SIM-1', 'fk_soc' => '12', 'status' => '0'],
                ],
            ],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertSame('create', $out['operation']);
        self::assertSame('SIM-1', $out['simulatedFields']['ref']);
        self::assertSame(12, $out['simulatedFields']['fk_soc']);
    }

    public function testSimulateChangeStatusBuildsTransitionPreview(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->simulate([
            'node' => [
                'config' => [
                    'operation' => 'change_status',
                    'objectType' => 'facture',
                    'id' => '7',
                    'statusMethod' => 'setDraft',
                ],
            ],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertSame('setDraft', $out['resolvedMethod']);
        self::assertTrue($out['methodExists']);
        self::assertTrue($out['invocationReady']);
    }

    public function testSimulateDeleteUsesGenericMessage(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->simulate([
            'node' => ['config' => ['operation' => 'delete', 'objectType' => 'facture', 'id' => '3']],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertStringContainsString('would delete', $out['message']);
    }

    public function testUpdateDeleteAddNoteAndPdfMutations(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();
        $base = [
            'objectType' => 'facture',
            'id' => '42',
        ];

        $updated = $action->execute([
            'node' => ['config' => $base + ['operation' => 'update', 'fields' => ['ref' => 'F-UPDATED']]],
        ]);
        self::assertTrue($updated['updated']);

        $deleted = $action->execute([
            'node' => ['config' => $base + ['operation' => 'delete']],
        ]);
        self::assertTrue($deleted['deleted']);

        $noted = $action->execute([
            'node' => ['config' => $base + ['operation' => 'add_note', 'note' => 'Follow-up']],
        ]);
        self::assertTrue($noted['noteAdded']);

        $pdf = $action->execute([
            'node' => ['config' => $base + ['operation' => 'generate_pdf']],
        ]);
        self::assertTrue($pdf['pdfGenerated']);
    }

    public function testChangeStatusInvokesTransition(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->execute([
            'node' => [
                'config' => [
                    'operation' => 'change_status',
                    'objectType' => 'facture',
                    'id' => '42',
                    'statusMethod' => 'setDraft',
                ],
            ],
        ]);

        self::assertTrue($out['statusChanged']);
        self::assertSame('setDraft', $out['method']);
    }

    public function testCreateWithLinesReturnsLinesAdded(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->execute([
            'node' => [
                'config' => [
                    'operation' => 'create',
                    'objectType' => 'facture',
                    'fields' => ['ref' => 'F-NEW', 'fk_soc' => 1, 'status' => 0],
                    'lines' => [['desc' => 'Item', 'qty' => 2, 'subprice' => 9.99, 'tva_tx' => 20]],
                ],
            ],
        ]);

        self::assertTrue($out['created']);
        self::assertSame(9001, $out['id']);
        self::assertSame(1, $out['linesAdded']);
    }

    public function testResolveStatusMethodCustomInExpertMode(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('resolveStatusMethod');

        $resolved = $method->invoke($action, [
            'statusMethod' => 'setDraft',
            'statusMethodCustom' => 'validate',
            'objectRegistryMode' => 'discovery_unverified',
        ]);

        self::assertSame('validate', $resolved);
    }

    public function testCoerceHandlesDateBooleanAndNumericTypes(): void
    {
        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('coerce');

        self::assertSame(1704067200, $method->invoke($action, '2024-01-01', ['type' => 'string', 'format' => 'date']));
        self::assertSame(42, $method->invoke($action, '42', ['type' => 'integer']));
        self::assertSame(3.5, $method->invoke($action, '3.5', ['type' => 'number']));
        self::assertTrue($method->invoke($action, 'yes', ['type' => 'boolean']));
    }

    public function testValidateAndCoercePayloadFallsBackWhenSchemaMissing(): void
    {
        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('validateAndCoercePayload');

        $result = $method->invoke(
            $action,
            new \Knot\Dolibarr\ObjectFactory(),
            new class extends \DoliDB {},
            'unknown_object_type_xyz',
            'create',
            ['custom' => 'value'],
            [],
            null,
            false,
        );

        self::assertSame(['custom' => 'value'], $result['fields']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testSimulateCreateReportsCoercionFailure(): void
    {
        $this->bootGlobals($this->adminUser());
        $action = new ObjectAction();

        $out = $action->simulate([
            'node' => [
                'config' => [
                    'operation' => 'create',
                    'objectType' => 'facture',
                    'fields' => [],
                ],
            ],
        ]);

        self::assertTrue($out['_dryRun']);
        self::assertStringContainsString('coercion preview failed', $out['message']);
    }

    public function testAssignFieldsMirrorsFkSocAlias(): void
    {
        $action = new ObjectAction();
        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('assignFields');
        $object = new \FakeFacture();
        $method->invoke($action, $object, ['fk_soc' => 999]);
        self::assertSame(999, $object->socid);
    }
}
