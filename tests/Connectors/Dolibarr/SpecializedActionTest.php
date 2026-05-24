<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors\Dolibarr;

use Knot\Connectors\Dolibarr\SpecializedAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SpecializedActionTest extends TestCase
{
    private static bool $booted = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$booted) {
            return;
        }
        if (!class_exists('Facture', false)) {
            class_alias(\FakeFacture::class, 'Facture');
        }
        if (!class_exists('Propal', false)) {
            class_alias(\FakePropal::class, 'Propal');
        }
        if (!class_exists('Commande', false)) {
            class_alias(\FakeCommande::class, 'Commande');
        }
        self::$booted = true;
    }

    private function bootGlobals(): void
    {
        $GLOBALS['db'] = new class extends \DoliDB {};
        $GLOBALS['user'] = new \FakeUser();
    }

    public function testMetadataValidateAndSimulate(): void
    {
        $action = new SpecializedAction();
        self::assertSame('dolibarr.specialized', $action->getMetadata()['id']);

        self::assertFalse($action->validate(['operation' => 'validate_invoice'])['valid']);
        self::assertTrue($action->validate(['operation' => 'validate_invoice', 'id' => 5])['valid']);

        $dry = $action->simulate([
            'node' => ['config' => ['operation' => 'validate_invoice', 'id' => 5]],
        ]);
        self::assertTrue($dry['_dryRun']);
        self::assertStringContainsString('validate_invoice', $dry['message']);
    }

    public function testValidateInvoiceCallsFactureValidate(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $out = $action->execute([
            'node' => ['config' => ['operation' => 'validate_invoice', 'id' => 12]],
        ]);

        self::assertSame('validate', $out['operation']);
        self::assertTrue($out['success']);
        self::assertSame(12, $out['id']);
    }

    public function testConvertPropalToOrderUsesFirstCompatibleMethod(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $out = $action->execute([
            'node' => ['config' => ['operation' => 'convert_propal_to_order', 'id' => 3]],
        ]);

        self::assertSame('createFromProposal', $out['operation']);
        self::assertTrue($out['success']);
    }

    public function testShipOrderUsesClassifyShipped(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $out = $action->execute([
            'node' => ['config' => ['operation' => 'ship_order', 'id' => 8]],
        ]);

        self::assertSame('classifyShipped', $out['operation']);
        self::assertTrue($out['success']);
    }

    public function testWarningOnlyOperationsDoNotMutate(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $payment = $action->execute([
            'node' => ['config' => ['operation' => 'record_payment', 'id' => 1]],
        ]);
        self::assertArrayHasKey('warning', $payment);

        $relance = $action->execute([
            'node' => ['config' => ['operation' => 'create_relance', 'id' => 1]],
        ]);
        self::assertArrayHasKey('warning', $relance);
    }

    public function testExecuteRequiresPositiveId(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id is required');
        $action->execute(['node' => ['config' => ['operation' => 'validate_invoice', 'id' => 0]]]);
    }

    public function testUnsupportedOperationThrows(): void
    {
        $this->bootGlobals();
        $action = new SpecializedAction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported specialized Dolibarr operation');
        $action->execute(['node' => ['config' => ['operation' => 'unknown_op', 'id' => 1]]]);
    }
}
