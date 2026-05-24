<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * Minimal DB stub for {@see \Knot\Capabilities\CapabilitiesBuilder}.
 */
final class CapabilitiesProbeDb extends \DoliDB
{
    /** @var array<int, array{__list: list<object>}> */
    private array $resultSets = [];

    private int $cursor = 0;

    public function query(string $sql)
    {
        if (str_contains($sql, 'FROM llx_extrafields')) {
            $this->resultSets[++$this->cursor] = [
                '__list' => [(object) ['elementtype' => 'facture', 'nb' => 2]],
            ];

            return $this->cursor;
        }
        if (str_contains($sql, 'FROM INFORMATION_SCHEMA.COLUMNS')) {
            $this->resultSets[++$this->cursor] = [
                '__list' => [(object) ['n' => 1]],
            ];

            return $this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null || $set === []) {
            return null;
        }
        if (isset($set['__list'])) {
            $row = array_shift($this->resultSets[$resource]['__list']);

            return $row === null ? null : $row;
        }

        return null;
    }
}
