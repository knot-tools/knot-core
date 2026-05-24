<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Repository;

class WorkflowFolderRepository extends AbstractRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $entity): array
    {
        $sql = 'SELECT rowid, label, color, parent_id, entity, fk_user_creat, date_creation '
            . 'FROM ' . $this->table('workflow_folder') . ' '
            . 'WHERE entity = ' . (int) $entity . ' '
            . 'ORDER BY label ASC';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'label' => (string) $obj->label,
                'color' => $obj->color !== null ? (string) $obj->color : null,
                'parentId' => $obj->parent_id !== null ? (int) $obj->parent_id : null,
                'entity' => (int) $obj->entity,
            ];
        }
        return $rows;
    }

    public function create(string $label, ?string $color, ?int $parentId, int $entity, int $userId): int
    {
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . $this->table('workflow_folder') . ' (label, color, parent_id, entity, fk_user_creat, date_creation) VALUES ('
            . "'" . $this->db->escape(substr(trim($label), 0, 255)) . "', "
            . ($color !== null ? "'" . $this->db->escape(substr($color, 0, 16)) . "'" : 'NULL') . ', '
            . ($parentId !== null && $parentId > 0 ? (int) $parentId : 'NULL') . ', '
            . (int) $entity . ', '
            . (int) $userId . ", '" . $now . "')";
        if (!$this->db->query($sql)) {
            return 0;
        }
        return (int) $this->db->last_insert_id($this->table('workflow_folder'));
    }

    /** @param array<string, mixed> $patch */
    public function update(int $id, array $patch, int $entity): bool
    {
        $set = [];
        if (isset($patch['label'])) {
            $set[] = "label = '" . $this->db->escape(substr(trim((string) $patch['label']), 0, 255)) . "'";
        }
        if (array_key_exists('color', $patch)) {
            $set[] = $patch['color'] !== null
                ? "color = '" . $this->db->escape(substr((string) $patch['color'], 0, 16)) . "'"
                : 'color = NULL';
        }
        if (array_key_exists('parentId', $patch)) {
            $set[] = $patch['parentId'] !== null && (int) $patch['parentId'] > 0
                ? 'parent_id = ' . (int) $patch['parentId']
                : 'parent_id = NULL';
        }
        if ($set === []) {
            return true;
        }
        $sql = 'UPDATE ' . $this->table('workflow_folder') . ' SET ' . implode(', ', $set)
            . ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;
        return (bool) $this->db->query($sql);
    }

    public function delete(int $id, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('workflow_folder')
            . ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;
        return (bool) $this->db->query($sql);
    }

    public function assignWorkflow(int $workflowId, ?int $folderId, int $entity): bool
    {
        $sql = 'UPDATE ' . $this->table('workflow') . ' SET fk_folder = '
            . ($folderId !== null && $folderId > 0 ? (int) $folderId : 'NULL')
            . ' WHERE rowid = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;
        return (bool) $this->db->query($sql);
    }
}
