<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for audit log entries.
 */
final class AuditLogRepository extends AbstractRepository
{
    /**
     * Record an audit event.
     *
     * @param array<string, mixed> $payload Event payload
     */
    public function record(
        string $actionType,
        string $entityType,
        ?int $entityId,
        ?int $userId,
        array $payload,
        int $entity
    ): bool {
        $sql = 'INSERT INTO ' . $this->table('audit_log') . ' (';
        $sql .= 'action_type, entity_type, entity_id, fk_user, ip_address, user_agent, payload, created_at, entity';
        $sql .= ') VALUES (';
        $sql .= "'" . $this->db->escape($actionType) . "',";
        $sql .= "'" . $this->db->escape($entityType) . "',";
        $sql .= ($entityId !== null ? (int) $entityId : 'NULL') . ',';
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= "'" . $this->db->escape($_SERVER['REMOTE_ADDR'] ?? '') . "',";
        $sql .= "'" . $this->db->escape(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)) . "',";
        $sql .= "'" . $this->db->escape(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}') . "',";
        $sql .= "'" . $this->db->idate(time()) . "',";
        $sql .= (int) $entity;
        $sql .= ')';

        return (bool) $this->db->query($sql);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $entity, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT rowid, action_type, entity_type, entity_id, fk_user, ip_address, payload, created_at'
            . ' FROM ' . $this->table('audit_log') . ' WHERE entity=' . (int) $entity;
        if (!empty($filters['actionType'])) {
            $sql .= " AND action_type='" . $this->db->escape((string) $filters['actionType']) . "'";
        }
        if (!empty($filters['entityType'])) {
            $sql .= " AND entity_type='" . $this->db->escape((string) $filters['entityType']) . "'";
        }
        if (isset($filters['userId'])) {
            $sql .= ' AND fk_user=' . (int) $filters['userId'];
        }
        if (!empty($filters['since'])) {
            $sql .= " AND created_at >= '" . $this->db->escape((string) $filters['since']) . "'";
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $q = mb_substr($q, 0, 200);
            $escaped = $this->db->escape($q);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $escaped);
            $sql .= ' AND (';
            $sql .= "LOWER(action_type) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR LOWER(entity_type) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR LOWER(payload) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR LOWER(ip_address) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR CAST(entity_id AS CHAR) LIKE '%" . $escaped . "%' ESCAPE '\\\\' ";
            $sql .= "OR CAST(fk_user AS CHAR) LIKE '%" . $escaped . "%' ESCAPE '\\\\'";
            $sql .= ')';
        }
        $sql .= ' ORDER BY rowid DESC LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) $offset);

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }
        $rows = [];
        while ($row = $this->db->fetch_object($resql)) {
            $rows[] = [
                'id' => (int) $row->rowid,
                'actionType' => (string) $row->action_type,
                'entityType' => (string) $row->entity_type,
                'entityId' => $row->entity_id !== null ? (int) $row->entity_id : null,
                'userId' => $row->fk_user !== null ? (int) $row->fk_user : null,
                'ip' => (string) $row->ip_address,
                'payload' => json_decode((string) $row->payload, true) ?: [],
                'createdAt' => (string) $row->created_at,
            ];
        }
        return $rows;
    }
}
