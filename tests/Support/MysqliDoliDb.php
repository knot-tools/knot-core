<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * Thin DoliDB-shaped adapter over a real MariaDB connection (mysqli), with the same
 * nested-transaction counter semantics as Dolibarr's DoliDB::begin/commit/rollback.
 * Used by opt-in integration tests (KNOT_TEST_MARIADB_DSN). Not final: the purge script
 * harness (tests/fixtures/demo-purge-dolibarr/master.inc.php) exposes it as DoliDB.
 */
class MysqliDoliDb
{
    public int $transaction_opened = 0;

    private string $lastError = '';

    public function __construct(public readonly \mysqli $link)
    {
    }

    public function query(string $sql): \mysqli_result|bool
    {
        try {
            $res = $this->link->query($sql);
        } catch (\mysqli_sql_exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
        if ($res === false) {
            $this->lastError = $this->link->error;
        }

        return $res;
    }

    public function fetch_object(mixed $res): ?object
    {
        if (!$res instanceof \mysqli_result) {
            return null;
        }
        $row = $res->fetch_object();

        return $row === false ? null : $row;
    }

    public function escape(string $value): string
    {
        return $this->link->real_escape_string($value);
    }

    /** Same output as DoliDB::idate() for a MySQL datetime column (server local time). */
    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function lasterror(): string
    {
        return $this->lastError;
    }

    public function affected_rows(mixed $resultset): int
    {
        return (int) $this->link->affected_rows;
    }

    public function begin(): int
    {
        if ($this->transaction_opened === 0) {
            if (!$this->query('BEGIN')) {
                return 0;
            }
        }
        ++$this->transaction_opened;

        return 1;
    }

    public function commit(): int
    {
        if ($this->transaction_opened <= 1) {
            $ok = (bool) $this->query('COMMIT');
            $this->transaction_opened = 0;

            return $ok ? 1 : 0;
        }
        --$this->transaction_opened;

        return 1;
    }

    public function rollback(): int
    {
        if ($this->transaction_opened <= 1) {
            $this->query('ROLLBACK');
            $this->transaction_opened = 0;

            return 1;
        }
        --$this->transaction_opened;

        return 1;
    }
}
