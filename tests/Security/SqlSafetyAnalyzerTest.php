<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\SqlSafetyAnalyzer;
use PHPUnit\Framework\TestCase;

final class SqlSafetyAnalyzerTest extends TestCase
{
    private SqlSafetyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SqlSafetyAnalyzer();
    }

    public function testAllowsBenignSelect(): void
    {
        $verdict = $this->analyzer->analyse('SELECT rowid, ref FROM llx_facture WHERE fk_soc = 42');
        self::assertTrue($verdict['valid'], (string) $verdict['reason']);
    }

    public function testRejectsStackedQueries(): void
    {
        $verdict = $this->analyzer->analyse("SELECT 1; DROP TABLE llx_user;");
        self::assertFalse($verdict['valid']);
    }

    public function testRejectsMutatingKeywords(): void
    {
        foreach (
            [
                'INSERT INTO foo VALUES (1)',
                'UPDATE foo SET a=1',
                'DELETE FROM foo',
                'DROP TABLE foo',
                'ALTER TABLE foo',
                'TRUNCATE foo',
                'CREATE TABLE foo (id INT)',
            ] as $sql
        ) {
            $verdict = $this->analyzer->analyse($sql);
            self::assertFalse($verdict['valid'], "Expected '$sql' to be rejected");
        }
    }

    public function testRejectsSystemSchemas(): void
    {
        foreach (
            [
                'SELECT * FROM information_schema.tables',
                'SELECT * FROM mysql.user',
                'SELECT * FROM sys.dba_users',
            ] as $sql
        ) {
            $verdict = $this->analyzer->analyse($sql);
            self::assertFalse($verdict['valid'], "Expected '$sql' to be rejected");
        }
    }

    public function testRejectsSensitiveKnotTables(): void
    {
        foreach (
            [
                'SELECT * FROM llx_knot_credential',
                'SELECT * FROM llx_user WHERE rowid = 1',
            ] as $sql
        ) {
            $verdict = $this->analyzer->analyse($sql);
            self::assertFalse($verdict['valid'], "Expected '$sql' to be rejected");
        }
    }

    public function testRejectsAbusableFunctions(): void
    {
        foreach (
            [
                'SELECT LOAD_FILE("/etc/passwd")',
                'SELECT BENCHMARK(1000000, MD5("a"))',
                'SELECT SLEEP(10)',
            ] as $sql
        ) {
            $verdict = $this->analyzer->analyse($sql);
            self::assertFalse($verdict['valid'], "Expected '$sql' to be rejected");
        }
    }

    public function testIgnoresKeywordsInsideStringLiterals(): void
    {
        $verdict = $this->analyzer->analyse("SELECT 'DROP TABLE foo' AS msg FROM llx_facture");
        self::assertTrue($verdict['valid'], (string) $verdict['reason']);
    }

    public function testIgnoresKeywordsInsideComments(): void
    {
        $verdict = $this->analyzer->analyse("SELECT 1 FROM llx_facture -- DROP TABLE\n WHERE 1=1");
        self::assertTrue($verdict['valid'], (string) $verdict['reason']);
    }

    /**
     * Regression for E2E-002: HTML hex colours (`#xxxxxx`) and URL fragments
     * (`#section`) inside string literals must not be mistaken for SQL
     * line comments. Before the fix, `stripStringLiterals` stripped `#…`
     * BEFORE quotes were normalised, which corrupted the literal and
     * surfaced a phantom `;` from the leftover fragment.
     */
    public function testIgnoresHashInsideStringLiterals(): void
    {
        $sql = "SELECT '<td style=\"padding:12px;color:#64748b\">x</td>' AS body FROM llx_propal";
        $verdict = $this->analyzer->analyse($sql);
        self::assertTrue($verdict['valid'], (string) $verdict['reason']);

        $sql2 = "SELECT 'see https://example.com#section' AS link FROM llx_facture";
        $verdict2 = $this->analyzer->analyse($sql2);
        self::assertTrue($verdict2['valid'], (string) $verdict2['reason']);
    }

    /**
     * `--` sequences inside string literals (eg. typed dashes in a label)
     * must not silently truncate the query. Same root cause as the `#` case.
     */
    public function testIgnoresDashDashInsideStringLiterals(): void
    {
        $sql = "SELECT 'foo -- bar; DROP TABLE x' AS msg FROM llx_facture";
        $verdict = $this->analyzer->analyse($sql);
        self::assertTrue($verdict['valid'], (string) $verdict['reason']);
    }

    public function testRejectsNonSelect(): void
    {
        $verdict = $this->analyzer->analyse('SHOW DATABASES');
        self::assertFalse($verdict['valid']);
    }
}
