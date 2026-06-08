<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * In-memory DB stub for {@see \Knot\Repository\ExecutionRepository} lifecycle tests.
 */
final class InMemoryExecutionDb extends \DoliDB
{
    /** @var array<int, array<string, mixed>> */
    public array $executions = [];

    /** @var array<int, array<string, mixed>> */
    public array $workflows = [];

    private int $nextId = 1;

    /** @var array<int, mixed> */
    private array $resultSets = [];

    private int $cursor = 0;

    private int $lastAffectedRows = 0;

    public function query(string $sql)
    {
        $this->lastAffectedRows = 0;

        if (preg_match('/^INSERT INTO llx_knot_execution /s', $sql)) {
            $id = $this->nextId++;
            $this->executions[$id] = [
                'rowid' => $id,
                'fk_workflow' => (int) $this->extractInsertValue($sql, 1),
                'status' => $this->extractInsertValue($sql, 2) ?? 'queued',
                'trigger_type' => stripcslashes($this->extractInsertValue($sql, 3) ?? 'manual'),
                'trigger_data' => stripcslashes($this->extractInsertValue($sql, 4) ?? '{}'),
                'retry_count' => (int) ($this->extractInsertValue($sql, 5) ?? 0),
                'entity' => (int) ($this->extractInsertValue($sql, 6) ?? 1),
                'priority' => (int) ($this->extractInsertValue($sql, 7) ?? 5),
                'scheduled_at' => $this->extractInsertValue($sql, 8),
                'max_attempts' => (int) ($this->extractInsertValue($sql, 9) ?? 3),
                'backoff_strategy' => stripcslashes($this->extractInsertValue($sql, 10) ?? 'exponential'),
                'worker_id' => null,
                'started_at' => null,
                'ended_at' => null,
                'error_message' => null,
                'error_payload' => null,
                'next_retry_at' => null,
                'duration_ms' => null,
            ];
            $this->lastAffectedRows = 1;

            return ++$this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_execution SET /s', $sql) && preg_match('/WHERE rowid = (\d+)/', $sql, $m)) {
            $id = (int) $m[1];
            if (isset($this->executions[$id])) {
                if (str_contains($sql, "AND status = 'queued'") && (string) ($this->executions[$id]['status'] ?? '') !== 'queued') {
                    $this->lastAffectedRows = 0;

                    return ++$this->cursor;
                }
                if (preg_match("/status = '([^']+)'/", $sql, $status)) {
                    $this->executions[$id]['status'] = $status[1];
                }
                if (preg_match("/worker_id = '([^']*)'/", $sql, $worker)) {
                    $this->executions[$id]['worker_id'] = stripcslashes($worker[1]);
                }
                if (str_contains($sql, 'worker_id = NULL')) {
                    $this->executions[$id]['worker_id'] = null;
                }
                if (preg_match('/retry_count = (\d+)/', $sql, $retry)) {
                    $this->executions[$id]['retry_count'] = (int) $retry[1];
                }
                if (preg_match("/error_message = '([^']*)'/", $sql, $err)) {
                    $this->executions[$id]['error_message'] = stripcslashes($err[1]);
                }
                if (str_contains($sql, 'started_at = ')) {
                    if (str_contains($sql, 'started_at = NULL')) {
                        $this->executions[$id]['started_at'] = null;
                    } elseif (preg_match("/started_at = '([^']+)'/", $sql, $started)) {
                        $this->executions[$id]['started_at'] = $started[1];
                    }
                }
                if (str_contains($sql, 'ended_at = ')) {
                    if (str_contains($sql, 'ended_at = NULL')) {
                        $this->executions[$id]['ended_at'] = null;
                    } elseif (preg_match("/ended_at = '([^']+)'/", $sql, $ended)) {
                        $this->executions[$id]['ended_at'] = $ended[1];
                    }
                }
                if (preg_match('/duration_ms = (\d+)/', $sql, $duration)) {
                    $this->executions[$id]['duration_ms'] = (int) $duration[1];
                }
                if (preg_match('/next_retry_at = ([^,]+)/', $sql, $next)) {
                    $value = trim($next[1]);
                    $this->executions[$id]['next_retry_at'] = $value === 'NULL' ? null : trim($value, "'");
                }
                $this->lastAffectedRows = 1;
            }

            return ++$this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_execution e') && str_contains($sql, 'WHERE e.rowid = ')) {
            preg_match('/WHERE e.rowid = (\d+) AND e.entity = (\d+)/', $sql, $m);
            $row = $this->executions[(int) ($m[1] ?? 0)] ?? null;
            if ($row !== null && (int) ($row['entity'] ?? 0) === (int) ($m[2] ?? 0)) {
                $this->resultSets[++$this->cursor] = $this->hydrateWorkflowJoin($row);

                return $this->cursor;
            }
            $this->resultSets[++$this->cursor] = [];

            return $this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_execution') && str_contains($sql, "status = 'queued'")) {
            $entity = 1;
            if (preg_match('/entity = (\d+)/', $sql, $m)) {
                $entity = (int) $m[1];
            }
            $rows = array_values(array_filter(
                $this->executions,
                static fn (array $row): bool => (int) ($row['entity'] ?? 0) === $entity
                    && (string) ($row['status'] ?? '') === 'queued'
            ));
            $this->resultSets[++$this->cursor] = ['__list' => array_map(
                fn (array $row): array => $this->hydrateWorkflowJoin($row),
                $rows
            )];

            return $this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_execution') && str_contains($sql, 'GROUP BY status')) {
            $counts = [];
            foreach ($this->executions as $row) {
                $status = (string) ($row['status'] ?? '');
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $list = [];
            foreach ($counts as $status => $total) {
                $list[] = ['status' => $status, 'total' => $total];
            }
            $this->resultSets[++$this->cursor] = ['__list' => $list];

            return $this->cursor;
        }

        if (str_contains($sql, 'SELECT COUNT(*) AS total FROM llx_knot_execution') && str_contains($sql, 'started_at >=')) {
            $entity = 1;
            if (preg_match('/entity = (\d+)/', $sql, $m)) {
                $entity = (int) $m[1];
            }
            $since = '';
            if (preg_match("/started_at >= '([^']+)'/", $sql, $m)) {
                $since = $m[1];
            }
            $total = 0;
            foreach ($this->executions as $row) {
                if ((int) ($row['entity'] ?? 0) !== $entity) {
                    continue;
                }
                if ($since !== '' && (string) ($row['started_at'] ?? '') >= $since) {
                    $total++;
                }
            }
            $this->resultSets[++$this->cursor] = ['total' => $total];

            return $this->cursor;
        }

        if (preg_match('/^DELETE FROM llx_knot_execution /', $sql)) {
            $this->lastAffectedRows = 2;

            return ++$this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null || $set === []) {
            return null;
        }
        if (is_array($set) && isset($set['__list'])) {
            $row = array_shift($this->resultSets[$resource]['__list']);

            return $row === null ? null : (object) $row;
        }

        return (object) $set;
    }

    public function num_rows($resource): int
    {
        $set = $this->resultSets[$resource] ?? [];
        if ($set === []) {
            return 0;
        }
        if (isset($set['__list'])) {
            return count($set['__list']) + 1;
        }

        return 1;
    }

    public function affected_rows(mixed $resultset = null): int
    {
        return $this->lastAffectedRows;
    }

    public function last_insert_id(string $table = ''): int
    {
        return $this->nextId - 1;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function plimit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    /** @return array<string, mixed> */
    private function hydrateWorkflowJoin(array $row): array
    {
        $workflow = $this->workflows[(int) ($row['fk_workflow'] ?? 0)] ?? [];

        return array_merge($row, [
            'workflow_label' => (string) ($workflow['label'] ?? ''),
            'workflow_ref' => (string) ($workflow['ref'] ?? ''),
        ]);
    }

    private function extractInsertValue(string $sql, int $index): ?string
    {
        if (!preg_match('/VALUES \((.*)\)\s*$/s', $sql, $m)) {
            return null;
        }
        $parts = $this->splitSqlValues($m[1]);
        if (!isset($parts[$index - 1])) {
            return null;
        }
        $value = trim($parts[$index - 1]);
        if ($value === 'NULL') {
            return null;
        }

        return trim($value, "'");
    }

    /** @return list<string> */
    private function splitSqlValues(string $values): array
    {
        $parts = [];
        $current = '';
        $inString = false;
        $len = strlen($values);
        for ($i = 0; $i < $len; $i++) {
            $ch = $values[$i];
            if ($ch === "'" && ($i === 0 || $values[$i - 1] !== '\\')) {
                $inString = !$inString;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && !$inString) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
