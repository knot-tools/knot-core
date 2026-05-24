<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Tags and favorites for workflow organisation.
 */
final class WorkflowTagRepository extends AbstractRepository
{
    /**
     * @return array<int, string>
     */
    public function listForWorkflow(int $workflowId, int $entity): array
    {
        $sql = 'SELECT tag FROM ' . $this->table('workflow_tag');
        $sql .= ' WHERE fk_workflow = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;
        $sql .= ' ORDER BY tag ASC';

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $tags = [];
        while ($obj = $this->db->fetch_object($res)) {
            $tags[] = (string) $obj->tag;
        }
        return $tags;
    }

    /**
     * @param array<int, string> $tags
     */
    public function replaceForWorkflow(int $workflowId, array $tags, int $entity): bool
    {
        $delete = 'DELETE FROM ' . $this->table('workflow_tag');
        $delete .= ' WHERE fk_workflow = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;
        if (!$this->db->query($delete)) {
            return false;
        }

        foreach ($this->cleanTags($tags) as $tag) {
            $sql = 'INSERT INTO ' . $this->table('workflow_tag') . ' (fk_workflow, tag, entity, date_creation) VALUES (';
            $sql .= (int) $workflowId . ',';
            $sql .= "'" . $this->db->escape($tag) . "',";
            $sql .= (int) $entity . ',';
            $sql .= "'" . $this->db->idate(time()) . "')";
            if (!$this->db->query($sql)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, int> $workflowIds
     * @param array<int, string> $tags
     */
    public function addTagsToMany(array $workflowIds, array $tags, int $entity): void
    {
        foreach ($workflowIds as $workflowId) {
            $existing = $this->listForWorkflow((int) $workflowId, $entity);
            $this->replaceForWorkflow((int) $workflowId, array_values(array_unique(array_merge($existing, $tags))), $entity);
        }
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function cleanTags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            $tag = preg_replace('/[^a-z0-9_.:-]/', '-', $tag) ?: '';
            if ($tag !== '') {
                $clean[] = substr($tag, 0, 50);
            }
        }
        return array_values(array_unique($clean));
    }
}
