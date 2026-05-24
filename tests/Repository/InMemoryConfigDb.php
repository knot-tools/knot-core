<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

/**
 * Minimal in-memory DoliDB stub backing the KnotConfigRepository.
 *
 * Parses the few SQL shapes the repository emits (SELECT v, INSERT ON
 * DUPLICATE KEY UPDATE, DELETE) — keeps the repository tested at the
 * SQL boundary without depending on a real MySQL.
 */
class InMemoryConfigDb extends \DoliDB
{
    /** @var array<int, array<string, string>> entity => key => value */
    private array $store = [];

    /** @var array<int, array<string, string>> */
    private array $lastFetchRow = [];
    private int $resultId = 0;

    public function query(string $sql)
    {
        if (preg_match("/^SELECT v FROM llx_knot_config WHERE entity = (\d+) AND k = '([^']*)' LIMIT 1$/", $sql, $m)) {
            $entity = (int) $m[1];
            $key = $m[2];
            $value = $this->store[$entity][$key] ?? null;
            $this->resultId++;
            $this->lastFetchRow[$this->resultId] = $value === null ? [] : ['v' => $value];

            return $this->resultId;
        }
        if (preg_match(
            "/^INSERT INTO llx_knot_config \(entity, k, v, created_at, updated_at\) VALUES \((\d+), '([^']*)', '([^']*)', '[^']*', '[^']*'\) ON DUPLICATE KEY UPDATE/",
            $sql,
            $m
        )) {
            $entity = (int) $m[1];
            $key = $m[2];
            $value = $m[3];
            $this->store[$entity][$key] = stripslashes($value);

            return ++$this->resultId;
        }
        if (preg_match("/^DELETE FROM llx_knot_config WHERE entity = (\d+) AND k = '([^']*)'$/", $sql, $m)) {
            unset($this->store[(int) $m[1]][$m[2]]);

            return ++$this->resultId;
        }

        return false;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function fetch_object($resource): ?object
    {
        $row = $this->lastFetchRow[$resource] ?? [];
        if ($row === []) {
            return null;
        }

        return (object) $row;
    }

    public function num_rows($resource): int
    {
        $row = $this->lastFetchRow[$resource] ?? [];

        return $row === [] ? 0 : 1;
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
