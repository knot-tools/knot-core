<?php

declare(strict_types=1);

namespace Knot\Repository;

final class ApprovalRepository extends AbstractRepository
{
    public function create(
        ?int $executionId,
        string $nodeId,
        string $message,
        ?int $requesterId,
        ?string $approverRole,
        int $entity
    ): int {
        $sql = 'INSERT INTO ' . $this->table('approval') . ' (';
        $sql .= 'fk_execution, node_id, message, requester_id, approver_role, status, entity, date_creation';
        $sql .= ') VALUES (';
        $sql .= ($executionId !== null ? (int) $executionId : 'NULL') . ',';
        $sql .= "'" . $this->db->escape(substr($nodeId, 0, 128)) . "',";
        $sql .= "'" . $this->db->escape($message) . "',";
        $sql .= ($requesterId !== null ? (int) $requesterId : 'NULL') . ',';
        $sql .= ($approverRole !== null ? "'" . $this->db->escape(substr($approverRole, 0, 128)) . "'" : 'NULL') . ',';
        $sql .= "'pending',";
        $sql .= (int) $entity . ',';
        $sql .= "'" . $this->db->idate(time()) . "')";

        if (!$this->db->query($sql)) {
            return 0;
        }
        return (int) $this->db->last_insert_id($this->table('approval'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPending(int $entity, int $limit = 100): array
    {
        $sql = 'SELECT rowid, fk_execution, node_id, message, requester_id, approver_role, status, date_creation ';
        $sql .= 'FROM ' . $this->table('approval') . " WHERE status = 'pending' AND entity = " . (int) $entity;
        $sql .= ' ORDER BY rowid ASC ' . $this->db->plimit($limit);
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'executionId' => $obj->fk_execution !== null ? (int) $obj->fk_execution : null,
                'nodeId' => (string) $obj->node_id,
                'message' => (string) $obj->message,
                'requesterId' => $obj->requester_id !== null ? (int) $obj->requester_id : null,
                'approverRole' => $obj->approver_role !== null ? (string) $obj->approver_role : null,
                'status' => (string) $obj->status,
                'createdAt' => (string) $obj->date_creation,
            ];
        }
        return $rows;
    }

    public function decide(int $id, string $status, int $userId, string $comment, int $entity): bool
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return false;
        }
        $sql = 'UPDATE ' . $this->table('approval') . ' SET ';
        $sql .= "status = '" . $this->db->escape($status) . "', ";
        $sql .= "decided_at = '" . $this->db->idate(time()) . "', ";
        $sql .= 'decided_by = ' . (int) $userId . ', ';
        $sql .= "comment = '" . $this->db->escape($comment) . "' ";
        $sql .= 'WHERE rowid = ' . (int) $id . " AND status = 'pending' AND entity = " . (int) $entity;
        return $this->db->query($sql) !== false;
    }
}
