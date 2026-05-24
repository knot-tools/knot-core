<?php

declare(strict_types=1);

namespace Knot\Tests\Security;

use Knot\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Security\RateLimiter
 */
final class RateLimiterTest extends TestCase
{
    public function testConsumeAllowsAllWhenLimitDisabled(): void
    {
        $db = new RateLimiterDb();
        $limiter = new RateLimiter($db);

        self::assertTrue($limiter->consume('bucket-a', 0));
        self::assertTrue($limiter->consume('bucket-a', -1));
        self::assertSame([], $db->rows);
    }

    /**
     * @runInSeparateProcess
     */
    public function testConsumeAllowsAllWhenTableCannotBeCreated(): void
    {
        $db = new RateLimiterDb();
        $db->tableOk = false;
        $limiter = new RateLimiter($db);

        self::assertTrue($limiter->consume('bucket-a', 2));
        self::assertSame([], $db->rows);
    }

    public function testConsumeInsertsFirstHit(): void
    {
        $db = new RateLimiterDb();
        $limiter = new RateLimiter($db);

        self::assertTrue($limiter->consume('bucket-a', 2));
        self::assertCount(1, $db->rows);
        self::assertSame(1, $db->rows[array_key_first($db->rows)]['hits']);
    }

    public function testConsumeBlocksWhenWindowLimitReached(): void
    {
        $db = new RateLimiterDb();
        $limiter = new RateLimiter($db);

        self::assertTrue($limiter->consume('bucket-a', 2));
        self::assertTrue($limiter->consume('bucket-a', 2));
        self::assertFalse($limiter->consume('bucket-a', 2));
    }

    public function testConsumeResetsCounterOnNewWindow(): void
    {
        $db = new RateLimiterDb();
        $limiter = new RateLimiter($db);
        $window = (int) floor(time() / 60);

        self::assertTrue($limiter->consume('bucket-a', 1));
        self::assertFalse($limiter->consume('bucket-a', 1));

        $key = array_key_first($db->rows);
        self::assertIsString($key);
        $db->rows[$key]['window_start'] = $window - 1;

        self::assertTrue($limiter->consume('bucket-a', 1));
        self::assertSame(1, $db->rows[$key]['hits']);
    }

    public function testRetryAfterSecondsWithinMinute(): void
    {
        $limiter = new RateLimiter(new RateLimiterDb());
        $retry = $limiter->retryAfterSeconds();

        self::assertGreaterThan(0, $retry);
        self::assertLessThanOrEqual(60, $retry);
    }
}

/**
 * Minimal in-memory DoliDB stub for RateLimiter SQL paths.
 */
final class RateLimiterDb extends \DoliDB
{
    /** @var array<string, array{window_start: int, hits: int}> */
    public array $rows = [];

    public bool $tableOk = true;

    private ?string $lastSelectBucket = null;

    public function escape($string): string
    {
        return addslashes((string) $string);
    }

    public function query($query, $usesavepoint = 0)
    {
        $sql = (string) $query;
        if (stripos($sql, 'CREATE TABLE') !== false) {
            return $this->tableOk;
        }
        if (preg_match("/SELECT.*FROM.*knot_rate_limit.*WHERE bucket = '([^']+)'/i", $sql, $matches) === 1) {
            $bucket = stripcslashes($matches[1]);
            if (isset($this->rows[$bucket])) {
                $this->lastSelectBucket = $bucket;
                return 1;
            }
            $this->lastSelectBucket = null;
            return false;
        }
        if (preg_match("/UPDATE.*knot_rate_limit SET hits = hits \+ 1 WHERE bucket = '([^']+)'/i", $sql, $matches) === 1) {
            $bucket = stripcslashes($matches[1]);
            if (isset($this->rows[$bucket])) {
                ++$this->rows[$bucket]['hits'];
            }
            return true;
        }
        if (preg_match("/UPDATE.*knot_rate_limit SET window_start = (\d+), hits = 1 WHERE bucket = '([^']+)'/i", $sql, $matches) === 1) {
            $bucket = stripcslashes($matches[2]);
            $this->rows[$bucket] = ['window_start' => (int) $matches[1], 'hits' => 1];
            return true;
        }
        if (preg_match("/INSERT INTO.*knot_rate_limit/i", $sql) === 1
            && preg_match("/VALUES \('([^']+)', (\d+), 1\)/i", $sql, $matches) === 1) {
            $bucket = stripcslashes($matches[1]);
            $this->rows[$bucket] = ['window_start' => (int) $matches[2], 'hits' => 1];
            return true;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        if (!$resource || $this->lastSelectBucket === null || !isset($this->rows[$this->lastSelectBucket])) {
            return null;
        }

        return (object) $this->rows[$this->lastSelectBucket];
    }
}
