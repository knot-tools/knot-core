<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Stores workflow definition snapshots.
 */
final class WorkflowVersionRepository extends AbstractRepository
{
    /**
     * @param array<string, mixed> $definition
     */
    public function createSnapshot(
        int $workflowId,
        array $definition,
        int $entity,
        ?int $userId,
        ?string $label = null,
        bool $named = false,
        ?int $parentVersionId = null
    ): int {
        $sql = 'INSERT INTO ' . $this->table('workflow_version') . ' (';
        $sql .= 'fk_workflow, version_label, definition_json, is_named, parent_version_id, entity, fk_user_creat, date_creation';
        $sql .= ') VALUES (';
        $sql .= (int) $workflowId . ',';
        $sql .= ($label !== null && trim($label) !== '' ? "'" . $this->db->escape(substr(trim($label), 0, 255)) . "'" : 'NULL') . ',';
        $sql .= "'" . $this->db->escape(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . "',";
        $sql .= ($named ? '1' : '0') . ',';
        $sql .= ($parentVersionId !== null ? (int) $parentVersionId : 'NULL') . ',';
        $sql .= (int) $entity . ',';
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= "'" . $this->db->idate(time()) . "'";
        $sql .= ')';

        if (!$this->db->query($sql)) {
            return 0;
        }

        $id = (int) $this->db->last_insert_id($this->table('workflow_version'));
        $this->purgeOldSnapshots($workflowId, $entity);
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $workflowId, int $entity, int $limit = 100): array
    {
        $sql = 'SELECT rowid, fk_workflow, version_label, is_named, parent_version_id, fk_user_creat, date_creation ';
        $sql .= 'FROM ' . $this->table('workflow_version');
        $sql .= ' WHERE fk_workflow = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;
        $sql .= ' ORDER BY rowid DESC ' . $this->db->plimit($limit);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'workflowId' => (int) $obj->fk_workflow,
                'label' => $obj->version_label !== null ? (string) $obj->version_label : null,
                'named' => (bool) $obj->is_named,
                'parentVersionId' => $obj->parent_version_id !== null ? (int) $obj->parent_version_id : null,
                'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
                'createdAt' => (string) $obj->date_creation,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(int $versionId, int $workflowId, int $entity): ?array
    {
        $sql = 'SELECT rowid, fk_workflow, version_label, definition_json, is_named, parent_version_id, fk_user_creat, date_creation ';
        $sql .= 'FROM ' . $this->table('workflow_version');
        $sql .= ' WHERE rowid = ' . (int) $versionId;
        $sql .= ' AND fk_workflow = ' . (int) $workflowId;
        $sql .= ' AND entity = ' . (int) $entity;

        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        if (!$obj) {
            return null;
        }

        return [
            'id' => (int) $obj->rowid,
            'workflowId' => (int) $obj->fk_workflow,
            'label' => $obj->version_label !== null ? (string) $obj->version_label : null,
            'definition' => json_decode((string) $obj->definition_json, true) ?: [],
            'named' => (bool) $obj->is_named,
            'parentVersionId' => $obj->parent_version_id !== null ? (int) $obj->parent_version_id : null,
            'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
            'createdAt' => (string) $obj->date_creation,
        ];
    }

    public function nameVersion(int $versionId, int $workflowId, int $entity, string $label): bool
    {
        $sql = 'UPDATE ' . $this->table('workflow_version') . ' SET ';
        $sql .= "version_label = '" . $this->db->escape(substr(trim($label), 0, 255)) . "', ";
        $sql .= 'is_named = 1';
        $sql .= ' WHERE rowid = ' . (int) $versionId;
        $sql .= ' AND fk_workflow = ' . (int) $workflowId;
        $sql .= ' AND entity = ' . (int) $entity;

        return $this->db->query($sql) !== false;
    }

    private function purgeOldSnapshots(int $workflowId, int $entity): void
    {
        $sql = 'DELETE FROM ' . $this->table('workflow_version');
        $sql .= ' WHERE fk_workflow = ' . (int) $workflowId;
        $sql .= ' AND entity = ' . (int) $entity;
        $sql .= ' AND is_named = 0';
        $sql .= ' AND rowid NOT IN (';
        $sql .= 'SELECT rowid FROM (';
        $sql .= 'SELECT rowid FROM ' . $this->table('workflow_version');
        $sql .= ' WHERE fk_workflow = ' . (int) $workflowId . ' AND entity = ' . (int) $entity . ' AND is_named = 0';
        $sql .= ' ORDER BY rowid DESC LIMIT 50';
        $sql .= ') keep_versions)';

        $this->db->query($sql);
    }
}
