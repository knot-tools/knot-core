<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\SqlTableReferenceValidator;
use PHPUnit\Framework\TestCase;

final class SqlTableReferenceValidatorTest extends TestCase
{
    public function testLintIssuesForTypoTableIncludesHintMessageKey(): void
    {
        $issues = (new SqlTableReferenceValidator())->lintIssues(
            'SELECT rowid FROM llx_propale LIMIT 10'
        );

        self::assertCount(1, $issues);
        self::assertSame('sql_unknown_table_hint', $issues[0]['messageKey']);
        self::assertSame('llx_propale', $issues[0]['messageParams']['table']);
        self::assertSame('llx_propal', $issues[0]['messageParams']['hint']);
    }

    public function testLintIssuesEmptyForKnownTables(): void
    {
        $issues = (new SqlTableReferenceValidator())->lintIssues(
            'SELECT p.rowid FROM llx_propal p WHERE p.fk_statut = 1'
        );

        self::assertSame([], $issues);
    }
}
