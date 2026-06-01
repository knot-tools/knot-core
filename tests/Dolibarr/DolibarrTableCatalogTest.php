<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\DolibarrTableCatalog;
use PHPUnit\Framework\TestCase;

final class DolibarrTableCatalogTest extends TestCase
{
    public function testHintForCommonPropalTypo(): void
    {
        $catalog = new DolibarrTableCatalog();

        self::assertSame('llx_propal', $catalog->hintForTable('llx_propale'));
        self::assertSame('llx_societe', $catalog->hintForTable('llx_thirdparty'));
    }

    public function testExtractTableReferencesIgnoresDuplicates(): void
    {
        $catalog = new DolibarrTableCatalog();
        $refs = $catalog->extractTableReferences(
            'SELECT * FROM llx_propal p JOIN llx_societe s ON s.rowid = p.fk_soc WHERE llx_propal.entity = 1'
        );

        self::assertSame(['llx_propal', 'llx_societe'], $refs);
    }

    public function testUnknownTablesReturnsTypoWithHint(): void
    {
        $catalog = new DolibarrTableCatalog();
        $unknown = $catalog->unknownTables('SELECT rowid FROM llx_propale WHERE fk_statut = 1');

        self::assertCount(1, $unknown);
        self::assertSame('llx_propale', $unknown[0]['table']);
        self::assertSame('llx_propal', $unknown[0]['hint']);
    }

    public function testKnownPropalTablesAreNotFlagged(): void
    {
        $catalog = new DolibarrTableCatalog();
        $unknown = $catalog->unknownTables('SELECT rowid FROM llx_propal JOIN llx_propaldet d ON d.fk_propal = llx_propal.rowid');

        self::assertSame([], $unknown);
    }
}
