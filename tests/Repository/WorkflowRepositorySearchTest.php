<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\WorkflowRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SQL contract of {@see WorkflowRepository::list()} for the
 * `$searchTerm` parameter introduced in V2.5.0b-ux-ops.
 *
 * Uses an in-memory `DoliDB` stub that captures the rendered SQL
 * statements so we can assert:
 *  - search is wrapped in `LOWER()` for case-insensitive matching
 *  - LIKE wildcards `%` and `_` in the user input are escaped so
 *    the search term cannot match unrelated rows
 *  - long search strings are clamped to 200 chars
 *  - empty search term keeps the original SQL unchanged
 */
final class WorkflowRepositorySearchTest extends TestCase
{
    public function testEmptySearchTermDoesNotAddLikeClause(): void
    {
        $db = new SearchSqlCapturingDb();
        $repo = new WorkflowRepository($db);

        $repo->list(1, [], 50, 0, '');

        self::assertNotEmpty($db->capturedQueries);
        $listSql = $db->capturedQueries[0];
        self::assertStringContainsString('WHERE entity = 1', $listSql);
        self::assertStringNotContainsString('LIKE', $listSql);
    }

    public function testSearchTermProducesLowerLikeClauseAcrossRefLabelDescription(): void
    {
        $db = new SearchSqlCapturingDb();
        $repo = new WorkflowRepository($db);

        $repo->list(1, [], 50, 0, 'Stripe');

        $listSql = $db->capturedQueries[0];
        self::assertStringContainsString("LOWER(ref) LIKE LOWER('%Stripe%')", $listSql);
        self::assertStringContainsString("LOWER(label) LIKE LOWER('%Stripe%')", $listSql);
        self::assertStringContainsString("LOWER(description) LIKE LOWER('%Stripe%')", $listSql);
    }

    public function testSearchTermEscapesLikeWildcards(): void
    {
        $db = new SearchSqlCapturingDb();
        $repo = new WorkflowRepository($db);

        $repo->list(1, [], 50, 0, '50%_off');

        $listSql = $db->capturedQueries[0];
        // The literal % and _ from the user input MUST be escaped with
        // a backslash so they cannot act as LIKE wildcards.
        self::assertStringContainsString('50\\%\\_off', $listSql);
        self::assertStringContainsString("ESCAPE '\\\\'", $listSql);
    }

    public function testSearchTermIsClampedTo200Characters(): void
    {
        $db = new SearchSqlCapturingDb();
        $repo = new WorkflowRepository($db);

        $term = str_repeat('a', 1000);
        $repo->list(1, [], 50, 0, $term);

        $listSql = $db->capturedQueries[0];
        self::assertStringContainsString(str_repeat('a', 200), $listSql);
        self::assertStringNotContainsString(str_repeat('a', 201), $listSql);
    }

    public function testSearchPreservesEntityFilter(): void
    {
        $db = new SearchSqlCapturingDb();
        $repo = new WorkflowRepository($db);

        $repo->list(7, [], 50, 0, 'foo');

        $listSql = $db->capturedQueries[0];
        // The entity guard MUST stay the very first WHERE predicate so
        // a malformed search term can never escape multi-entity isolation.
        self::assertMatchesRegularExpression(
            '/WHERE entity = 7\s+AND \(/',
            $listSql
        );
    }
}

/**
 * Minimal DoliDB stub that records every SQL statement and
 * returns an empty result set so list() short-circuits cleanly.
 */
final class SearchSqlCapturingDb extends \DoliDB
{
    /** @var list<string> */
    public array $capturedQueries = [];

    public function query(string $sql)
    {
        $this->capturedQueries[] = $sql;
        return false;
    }

    public function fetch_object($resource): ?object
    {
        return null;
    }

    public function num_rows($resource): int
    {
        return 0;
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}
