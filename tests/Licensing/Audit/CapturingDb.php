<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Audit;

/**
 * Captures every SQL statement issued by AuditLogRepository so licensing
 * tests can assert audit emissions without a live database.
 */
final class CapturingDb extends \DoliDB
{
    /** @var string[] */
    public array $queries = [];

    public bool $forceFailure = false;

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        return !$this->forceFailure;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
