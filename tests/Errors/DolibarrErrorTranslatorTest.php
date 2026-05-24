<?php

declare(strict_types=1);

namespace Knot\Tests\Errors;

use Knot\Errors\DolibarrPermissionError;
use Knot\Errors\DolibarrErrorTranslator;
use Knot\Errors\InvalidValueError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DolibarrErrorTranslatorTest extends TestCase
{
    public function testPassesThroughKnotError(): void
    {
        $original = new DolibarrPermissionError(
            'KNOT_PERM_DOLIBARR_DENIED',
            'User msg',
            'tech',
            null,
            [],
            null,
            'warning'
        );
        $out = (new DolibarrErrorTranslator())->translate($original, []);
        $this->assertSame($original, $out);
    }

    public function testMapsPermissionKeywords(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Permission denied on module'), []);
        $this->assertSame('KNOT_PERM_DOLIBARR_DENIED', $out->knotCode);
        $this->assertInstanceOf(DolibarrPermissionError::class, $out);
    }

    public function testMapsInvalidValue(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Value X is not a valid date'), []);
        $this->assertSame('KNOT_VALIDATION_INVALID_VALUE', $out->knotCode);
        $this->assertInstanceOf(InvalidValueError::class, $out);
    }

    public function testMapsRecordNotFound(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Record not found in database'), []);
        $this->assertSame('KNOT_DOLIBARR_RECORD_NOT_FOUND', $out->knotCode);
    }

    public function testMapsIntegrityConstraint(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Duplicate entry for key fk_soc'), []);
        $this->assertSame('KNOT_DOLIBARR_INTEGRITY', $out->knotCode);
    }

    public function testMapsAlreadyExists(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Reference already exists'), []);
        $this->assertSame('KNOT_STATE_ALREADY_EXISTS', $out->knotCode);
    }

    public function testMapsInvalidStatusTransition(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Invalid status for this action'), []);
        $this->assertSame('KNOT_STATE_INVALID_TRANSITION', $out->knotCode);
    }

    public function testMapsModuleNotActivated(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Module stock is not activated'), []);
        $this->assertSame('KNOT_MODULE_NOT_ACTIVATED', $out->knotCode);
    }

    public function testMapsMissingRequiredField(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Field email is required'), []);
        $this->assertSame('KNOT_VALIDATION_MISSING_FIELD', $out->knotCode);
    }

    public function testMergesContextWithOriginalClass(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(
            new RuntimeException('Permission denied'),
            ['nodeId' => 'n1']
        );
        $this->assertSame('n1', $out->context['nodeId']);
        $this->assertSame(RuntimeException::class, $out->context['original_class']);
    }

    public function testFallbackUnexpected(): void
    {
        $out = (new DolibarrErrorTranslator())->translate(new RuntimeException('Something obscure happened'), []);
        $this->assertSame('KNOT_DOLIBARR_UNEXPECTED', $out->knotCode);
    }
}
