<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for workflow variables.
 */
final class VariableRepository extends AbstractRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $entity, ?string $scope = null): array
    {
        $sql = 'SELECT rowid, ref, label, value, is_secret, scope, fk_workflow, fk_user_creat'
            . ' FROM ' . $this->table('variable') . ' WHERE entity=' . (int) $entity;
        if ($scope !== null && $scope !== '') {
            $sql .= " AND scope='" . $this->db->escape($scope) . "'";
        }
        $sql .= ' ORDER BY ref ASC';

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }

        $rows = [];
        while ($row = $this->db->fetch_object($resql)) {
            $rows[] = [
                'id' => (int) $row->rowid,
                'ref' => (string) $row->ref,
                'label' => (string) $row->label,
                'value' => $row->is_secret ? null : (string) $row->value,
                'isSecret' => (bool) $row->is_secret,
                'scope' => (string) $row->scope,
                'workflowId' => $row->fk_workflow !== null ? (int) $row->fk_workflow : null,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $entity, ?int $userId = null): ?int
    {
        $ref = (string) ($data['ref'] ?? '');
        if ($ref === '') {
            return null;
        }
        $label = (string) ($data['label'] ?? $ref);
        $value = (string) ($data['value'] ?? '');
        $isSecret = !empty($data['isSecret']) ? 1 : 0;
        $scope = (string) ($data['scope'] ?? 'global');
        $workflowId = isset($data['workflowId']) ? (int) $data['workflowId'] : null;

        $sql = 'INSERT INTO ' . $this->table('variable')
            . ' (ref, label, value, is_secret, scope, fk_workflow, entity, fk_user_creat)'
            . ' VALUES ('
            . "'" . $this->db->escape($ref) . "',"
            . "'" . $this->db->escape($label) . "',"
            . "'" . $this->db->escape($value) . "',"
            . $isSecret . ','
            . "'" . $this->db->escape($scope) . "',"
            . ($workflowId !== null ? (int) $workflowId : 'NULL') . ','
            . (int) $entity . ','
            . ($userId !== null ? (int) $userId : 'NULL')
            . ')';

        if ($this->db->query($sql) === false) {
            return null;
        }
        return (int) $this->db->last_insert_id($this->table('variable'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, int $entity): bool
    {
        $set = [];
        if (isset($data['label'])) {
            $set[] = "label='" . $this->db->escape((string) $data['label']) . "'";
        }
        if (isset($data['value'])) {
            $set[] = "value='" . $this->db->escape((string) $data['value']) . "'";
        }
        if (isset($data['isSecret'])) {
            $set[] = 'is_secret=' . (!empty($data['isSecret']) ? 1 : 0);
        }
        if (isset($data['scope'])) {
            $set[] = "scope='" . $this->db->escape((string) $data['scope']) . "'";
        }
        if (empty($set)) {
            return false;
        }
        $sql = 'UPDATE ' . $this->table('variable') . ' SET ' . implode(',', $set)
            . ' WHERE rowid=' . (int) $id . ' AND entity=' . (int) $entity;
        return $this->db->query($sql) !== false;
    }

    public function delete(int $id, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('variable') . ' WHERE rowid=' . (int) $id . ' AND entity=' . (int) $entity;
        return $this->db->query($sql) !== false;
    }
}
