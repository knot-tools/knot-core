<?php

declare(strict_types=1);

namespace Knot\Tests\Compatibility;

use Knot\Compatibility\Versioning\PilotDocuments;
use PHPUnit\Framework\TestCase;

final class PilotDocumentsTest extends TestCase
{
    public function testSnapshotSlugsRemainStable(): void
    {
        self::assertSame(['facture', 'commande', 'propal'], PilotDocuments::SCHEMA_SNAPSHOT_SLUGS);
        self::assertSame(PilotDocuments::SCHEMA_SNAPSHOT_SLUGS, PilotDocuments::SLUGS);
    }

    /** @dataProvider exclusionProvider */
    public function testStateMachineExclusions(string $slug, bool $excluded): void
    {
        self::assertSame($excluded, PilotDocuments::isStateMachineCapabilityExcluded($slug));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function exclusionProvider(): array
    {
        return [
            'empty' => ['', true],
            'user' => ['user', true],
            'facture' => ['facture', false],
            'propal' => ['propal', false],
        ];
    }
}
