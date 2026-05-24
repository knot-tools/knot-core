<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for execution logs.
 */
final class ExecutionLogRepository extends AbstractRepository
{
    /**
     * Add an execution log entry.
     *
     * @param array<string, mixed> $inputData Input payload
     * @param array<string, mixed> $outputData Output payload
     */
    public function add(
        int $executionId,
        string $nodeId,
        string $nodeType,
        string $status,
        array $inputData,
        array $outputData,
        int $sequenceOrder,
        int $entity,
        ?string $errorMessage = null,
        ?int $durationMs = null
    ): bool {
        $sql = 'INSERT INTO ' . $this->table('execution_log') . ' (';
        $sql .= 'fk_execution, node_id, node_type, status, input_data, output_data, duration_ms, error_message, executed_at, sequence_order, entity';
        $sql .= ') VALUES (';
        $sql .= (int) $executionId . ',';
        $sql .= "'" . $this->db->escape($nodeId) . "',";
        $sql .= "'" . $this->db->escape($nodeType) . "',";
        $sql .= "'" . $this->db->escape($status) . "',";
        $sql .= "'" . $this->db->escape(json_encode($inputData, JSON_UNESCAPED_SLASHES) ?: '{}') . "',";
        $sql .= "'" . $this->db->escape(json_encode($outputData, JSON_UNESCAPED_SLASHES) ?: '{}') . "',";
        $sql .= ($durationMs !== null ? (int) $durationMs : 'NULL') . ',';
        $sql .= ($errorMessage !== null ? "'" . $this->db->escape($errorMessage) . "'" : 'NULL') . ',';
        $sql .= "'" . $this->db->idate(time()) . "',";
        $sql .= (int) $sequenceOrder . ',';
        $sql .= (int) $entity;
        $sql .= ')';

        return (bool) $this->db->query($sql);
    }

    /**
     * Fetch logs for an execution ordered by sequence.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchByExecution(int $executionId, int $entity, int $limit = 500): array
    {
        $sql = 'SELECT rowid, node_id, node_type, status, input_data, output_data, ';
        $sql .= 'duration_ms, error_message, executed_at, sequence_order ';
        $sql .= 'FROM ' . $this->table('execution_log') . ' ';
        $sql .= 'WHERE fk_execution = ' . (int) $executionId . ' ';
        $sql .= 'AND entity = ' . (int) $entity . ' ';
        $sql .= 'ORDER BY sequence_order ASC ';
        $sql .= 'LIMIT ' . max(1, (int) $limit);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'nodeId' => (string) $obj->node_id,
                'nodeType' => (string) $obj->node_type,
                'status' => (string) $obj->status,
                'input' => json_decode((string) $obj->input_data, true) ?: [],
                'output' => json_decode((string) $obj->output_data, true) ?: [],
                'durationMs' => $obj->duration_ms !== null ? (int) $obj->duration_ms : null,
                'errorMessage' => $obj->error_message !== null ? (string) $obj->error_message : null,
                'executedAt' => (string) $obj->executed_at,
                'sequenceOrder' => (int) $obj->sequence_order,
            ];
        }

        return $rows;
    }

    /**
     * Count logs for an execution. Used by API to flag truncation in the UI.
     */
    public function countByExecution(int $executionId, int $entity): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . $this->table('execution_log') . ' ';
        $sql .= 'WHERE fk_execution = ' . (int) $executionId . ' ';
        $sql .= 'AND entity = ' . (int) $entity;

        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (int) $obj->cnt : 0;
    }
}
