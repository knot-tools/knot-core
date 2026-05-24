<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * In-memory {@see \DoliDB} stub for WorkflowRepository unit tests.
 */
final class InMemoryWorkflowDb extends \DoliDB
{
    /** @var array<int, array<string, mixed>> */
    public array $workflows = [];

    /** @var array<int, array<string, mixed>> */
    public array $definitions = [];

    /** @var array<string, int> */
    public array $statusCounts = [];

    /** @var list<array<string, mixed>> */
    public array $listRows = [];

    /** @var array<int, array{success:int,error:int,waiting:int,running:int,queued:int,total:int}> */
    public array $executionStats = [];

    private int $nextId = 1;

    /** @var array<int, mixed> */
    private array $resultSets = [];

    private int $cursor = 0;

    public function query(string $sql)
    {
        if (preg_match('/SELECT MAX\(CAST\(SUBSTRING_INDEX\(ref/', $sql)) {
            $this->resultSets[++$this->cursor] = ['m' => 0];

            return $this->cursor;
        }

        if (preg_match('/^INSERT INTO llx_knot_workflow /', $sql)) {
            if (!preg_match(
                "/VALUES \('([^']*)','([^']*)','([^']*)','([^']*)','(\{.*\})','([^']*)',(\d+|NULL),(\d+|NULL),'([^']*)',(\d+)\)$/",
                $sql,
                $m
            )) {
                return false;
            }
            $id = $this->nextId++;
            $this->workflows[$id] = [
                'rowid' => $id,
                'ref' => $m[1],
                'label' => $m[2],
                'description' => $m[3],
                'status' => $m[4],
                'json_definition' => stripcslashes($m[5]),
                'version_schema' => $m[6],
                'entity' => (int) $m[10],
                'single_instance' => 0,
                'activation_warning_dismissed' => 0,
                'fk_user_creat' => $m[7] === 'NULL' ? null : (int) $m[7],
                'fk_user_modif' => $m[8] === 'NULL' ? null : (int) $m[8],
                'date_creation' => $m[9],
                'tms' => $m[9],
            ];

            return ++$this->cursor;
        }

        if (preg_match('/^SELECT rowid, ref, label, description, status, json_definition, version_schema, entity/', $sql)) {
            if (!preg_match('/WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
                return false;
            }
            $id = (int) $m[1];
            $row = $this->workflows[$id] ?? null;
            if ($row === null) {
                $this->resultSets[++$this->cursor] = [];

                return $this->cursor;
            }
            if (preg_match("/ AND status IN \('([^']*)'\)/", $sql, $statusFilter)) {
                if ($row['status'] !== $statusFilter[1]) {
                    $this->resultSets[++$this->cursor] = [];

                    return $this->cursor;
                }
            }
            $this->resultSets[++$this->cursor] = $row;

            return $this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_workflow SET /', $sql)) {
            if (!preg_match('/WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m)) {
                return false;
            }
            $id = (int) $m[1];
            if (!isset($this->workflows[$id])) {
                return false;
            }
            if (preg_match("/label = '([^']*)'/", $sql, $label)) {
                $this->workflows[$id]['label'] = $label[1];
            }
            if (preg_match("/status = '([^']*)'/", $sql, $status)) {
                $this->workflows[$id]['status'] = $status[1];
            }

            return ++$this->cursor;
        }

        if (preg_match('/^DELETE FROM llx_knot_workflow/', $sql)) {
            if (preg_match('/WHERE rowid = (\d+)/', $sql, $m)) {
                unset($this->workflows[(int) $m[1]]);
            }

            return ++$this->cursor;
        }

        if (preg_match('/^SELECT status, COUNT\(\*\) AS total FROM llx_knot_workflow/', $sql)) {
            $list = [];
            foreach ($this->statusCounts as $status => $total) {
                $list[] = ['status' => $status, 'total' => $total];
            }
            $this->resultSets[++$this->cursor] = ['__list' => $list];

            return $this->cursor;
        }

        if (preg_match('/^SELECT rowid, json_definition FROM llx_knot_workflow/', $sql)) {
            $list = [];
            foreach ($this->definitions as $id => $def) {
                $list[] = ['rowid' => $id, 'json_definition' => json_encode($def)];
            }
            $this->resultSets[++$this->cursor] = ['__list' => $list];

            return $this->cursor;
        }

        if (preg_match('/^SELECT rowid, ref, label, description, status, version_schema,/', $sql)) {
            $this->resultSets[++$this->cursor] = ['__list' => $this->listRows];

            return $this->cursor;
        }

        if (preg_match('/^SELECT fk_workflow, status, COUNT\(\*\) AS n FROM llx_knot_execution/', $sql)) {
            $list = [];
            foreach ($this->executionStats as $wfId => $bucket) {
                if ($bucket['success'] > 0) {
                    $list[] = ['fk_workflow' => $wfId, 'status' => 'success', 'n' => $bucket['success']];
                }
                if ($bucket['error'] > 0) {
                    $list[] = ['fk_workflow' => $wfId, 'status' => 'error', 'n' => $bucket['error']];
                }
            }
            $this->resultSets[++$this->cursor] = ['__list' => $list];

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

    public function last_insert_id(string $tableName): int
    {
        return $this->nextId - 1;
    }
}
