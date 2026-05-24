<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for workflows.
 */
/**
 * V2.5.0b — `final` removed so PHPUnit can mock the repo when testing
 * higher-level services such as {@see \Knot\Migration\ConnectorMigration}.
 * The class is still designed to be the canonical repository: subclassing
 * it in production code is discouraged.
 */
class WorkflowRepository extends AbstractRepository
{
    private const ALLOWED_STATUSES = ['draft', 'active', 'disabled', 'error', 'archived'];

    /**
     * Fetch an active workflow by id and entity.
     *
     * @return array<string, mixed>|null
     */
    public function fetchActive(int $workflowId, int $entity): ?array
    {
        return $this->fetchByIdInternal($workflowId, $entity, ['active']);
    }

    /**
     * Fetch any workflow regardless of status (used by editor and admin).
     *
     * @return array<string, mixed>|null
     */
    public function fetch(int $workflowId, int $entity): ?array
    {
        return $this->fetchByIdInternal($workflowId, $entity, self::ALLOWED_STATUSES);
    }

    /**
     * List workflows for an entity ordered by tms desc.
     *
     * V2.5.0b-ux-ops — accepts an optional `$searchTerm`. When set,
     * the query restricts results to workflows whose `ref`, `label`
     * or `description` contains the term (case-insensitive LIKE on
     * the database side, escaped to prevent SQL injection).
     *
     * @param array<int, string> $statuses
     * @return array<int, array<string, mixed>>
     */
    public function list(
        int $entity,
        array $statuses = [],
        int $limit = 100,
        int $offset = 0,
        string $searchTerm = ''
    ): array {
        $sql = 'SELECT rowid, ref, label, description, status, version_schema, ';
        $sql .= 'fk_user_creat, fk_user_modif, date_creation, tms, fk_folder, json_definition ';
        $sql .= 'FROM ' . $this->table('workflow') . ' ';
        $sql .= 'WHERE entity = ' . (int) $entity;

        if ($statuses !== []) {
            $clean = array_values(array_filter(array_map(
                fn (string $s): string => $this->db->escape(strtolower(trim($s))),
                $statuses
            )));
            if ($clean !== []) {
                $sql .= " AND status IN ('" . implode("','", $clean) . "')";
            }
        }

        $term = trim($searchTerm);
        if ($term !== '') {
            // Cap term length to avoid pathological LIKE patterns.
            $term = mb_substr($term, 0, 200);
            $escaped = $this->db->escape($term);
            // Escape SQL LIKE wildcards (%, _) so users searching for a
            // literal underscore in a workflow ref do not match everything.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $escaped);
            $sql .= " AND (";
            $sql .= "LOWER(ref) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR LOWER(label) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\' ";
            $sql .= "OR LOWER(description) LIKE LOWER('%" . $escaped . "%') ESCAPE '\\\\'";
            $sql .= ")";
        }

        $sql .= ' ORDER BY tms DESC ';
        $sql .= $this->db->plimit($limit, $offset);

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'ref' => (string) $obj->ref,
                'label' => (string) $obj->label,
                'description' => $obj->description !== null ? (string) $obj->description : '',
                'status' => (string) $obj->status,
                'schemaVersion' => (string) $obj->version_schema,
                'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
                'modifiedBy' => $obj->fk_user_modif !== null ? (int) $obj->fk_user_modif : null,
                'createdAt' => (string) $obj->date_creation,
                'updatedAt' => (string) $obj->tms,
                'folderId' => isset($obj->fk_folder) && (int) $obj->fk_folder > 0
                    ? (int) $obj->fk_folder
                    : null,
                'triggerType' => $this->extractFirstTrigger((string) ($obj->json_definition ?? '')),
            ];
        }

        if ($rows !== []) {
            $stats = $this->fetchExecutionStats(array_column($rows, 'id'), $entity);
            foreach ($rows as &$row) {
                $bucket = $stats[$row['id']] ?? ['success' => 0, 'error' => 0, 'waiting' => 0, 'running' => 0, 'queued' => 0, 'total' => 0];
                $row['successCount'] = $bucket['success'];
                $row['errorCount'] = $bucket['error'];
                $row['waitingCount'] = $bucket['waiting'];
                $row['runningCount'] = $bucket['running'];
                $row['queuedCount'] = $bucket['queued'];
                $row['runsTotal'] = $bucket['total'];
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Batch-load workflow definitions for list risk aggregation.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function fetchDefinitionsByIds(array $ids, int $entity): array
    {
        $cleanIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($cleanIds === []) {
            return [];
        }

        $sql = 'SELECT rowid, json_definition FROM ' . $this->table('workflow') . ' ';
        $sql .= 'WHERE entity = ' . (int) $entity;
        $sql .= ' AND rowid IN (' . implode(',', $cleanIds) . ')';

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $out = [];
        while ($obj = $this->db->fetch_object($res)) {
            $definition = json_decode((string) ($obj->json_definition ?? ''), true);
            if (!is_array($definition)) {
                $definition = ['schemaVersion' => '1.0', 'workflow' => [], 'nodes' => [], 'edges' => []];
            }
            $out[(int) $obj->rowid] = $definition;
        }

        return $out;
    }

    /**
     * Aggregate execution counts per workflow in a single query.
     *
     * Used by the workflows list and the Workflow Book to surface
     * cron firings vs. failures right on the workflow card without
     * forcing the UI to fetch every execution row separately.
     *
     * @param array<int, int> $workflowIds
     * @return array<int, array{success:int, error:int, waiting:int, running:int, queued:int, total:int}>
     */
    private function fetchExecutionStats(array $workflowIds, int $entity): array
    {
        $ids = array_values(array_filter(array_map('intval', $workflowIds)));
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', $ids);
        $sql = 'SELECT fk_workflow, status, COUNT(*) AS n FROM ' . $this->table('execution')
            . " WHERE fk_workflow IN ($idList) AND entity = " . (int) $entity
            . ' GROUP BY fk_workflow, status';

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $byWorkflow = [];
        while ($obj = $this->db->fetch_object($res)) {
            $wf = (int) $obj->fk_workflow;
            $byWorkflow[$wf] = $byWorkflow[$wf] ?? ['success' => 0, 'error' => 0, 'waiting' => 0, 'running' => 0, 'queued' => 0, 'total' => 0];
            $count = (int) $obj->n;
            $byWorkflow[$wf]['total'] += $count;
            switch ((string) $obj->status) {
                case 'success':
                case 'completed':
                    $byWorkflow[$wf]['success'] += $count;
                    break;
                case 'error':
                case 'failed':
                    $byWorkflow[$wf]['error'] += $count;
                    break;
                case 'waiting':
                    $byWorkflow[$wf]['waiting'] += $count;
                    break;
                case 'running':
                    $byWorkflow[$wf]['running'] += $count;
                    break;
                case 'queued':
                    $byWorkflow[$wf]['queued'] += $count;
                    break;
            }
        }
        return $byWorkflow;
    }

    /**
     * Extract the first trigger node type from a JSON-encoded workflow
     * definition. Returns 'manual' as a safe default.
     */
    private function extractFirstTrigger(string $json): string
    {
        if ($json === '') {
            return 'manual';
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return 'manual';
        }
        $nodes = is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if (str_starts_with($type, 'trigger.')) {
                return $type;
            }
        }
        return 'manual';
    }

    /**
     * Count workflows by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(int $entity): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM ' . $this->table('workflow');
        $sql .= ' WHERE entity = ' . (int) $entity . ' GROUP BY status';

        $res = $this->db->query($sql);
        $counts = array_fill_keys(self::ALLOWED_STATUSES, 0);
        if (!$res) {
            return $counts;
        }
        while ($obj = $this->db->fetch_object($res)) {
            $counts[(string) $obj->status] = (int) $obj->total;
        }

        return $counts;
    }

    /**
     * Insert a new workflow and return its id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $entity, ?int $userId): int
    {
        $ref = $this->generateRef($entity);
        $label = $this->cleanLabel((string) ($data['label'] ?? ''));
        $description = (string) ($data['description'] ?? '');
        $status = $this->normaliseStatus((string) ($data['status'] ?? 'draft'));
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : ['schemaVersion' => '1.0', 'workflow' => [], 'nodes' => [], 'edges' => []];
        $schemaVersion = (string) ($definition['schemaVersion'] ?? '1.0');
        $now = $this->db->idate(time());

        $sql = 'INSERT INTO ' . $this->table('workflow') . ' (';
        $sql .= 'ref, label, description, status, json_definition, version_schema, ';
        $sql .= 'fk_user_creat, fk_user_modif, date_creation, entity';
        $sql .= ') VALUES (';
        $sql .= "'" . $this->db->escape($ref) . "',";
        $sql .= "'" . $this->db->escape($label) . "',";
        $sql .= "'" . $this->db->escape($description) . "',";
        $sql .= "'" . $this->db->escape($status) . "',";
        $sql .= "'" . $this->db->escape($this->encodeDefinition($definition)) . "',";
        $sql .= "'" . $this->db->escape($schemaVersion) . "',";
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= "'" . $now . "',";
        $sql .= (int) $entity;
        $sql .= ')';

        if (!$this->db->query($sql)) {
            return 0;
        }

        return (int) $this->db->last_insert_id($this->table('workflow'));
    }

    /**
     * Update an existing workflow.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $workflowId, array $data, int $entity, ?int $userId): bool
    {
        $existing = $this->fetch($workflowId, $entity);
        if ($existing === null) {
            return false;
        }

        $sets = [];
        if (array_key_exists('label', $data)) {
            $sets[] = "label = '" . $this->db->escape($this->cleanLabel((string) $data['label'])) . "'";
        }
        if (array_key_exists('description', $data)) {
            $sets[] = "description = '" . $this->db->escape((string) $data['description']) . "'";
        }
        if (array_key_exists('status', $data)) {
            $status = $this->normaliseStatus((string) $data['status']);
            $sets[] = "status = '" . $this->db->escape($status) . "'";
        }
        if (array_key_exists('definition', $data) && is_array($data['definition'])) {
            $definition = $data['definition'];
            $sets[] = "json_definition = '" . $this->db->escape($this->encodeDefinition($definition)) . "'";
            $sets[] = "version_schema = '" . $this->db->escape((string) ($definition['schemaVersion'] ?? '1.0')) . "'";
        }
        if (array_key_exists('activation_warning_dismissed', $data)) {
            $sets[] = 'activation_warning_dismissed = ' . ((bool) $data['activation_warning_dismissed'] ? 1 : 0);
        }
        if ($userId !== null) {
            $sets[] = 'fk_user_modif = ' . (int) $userId;
        }

        if ($sets === []) {
            return true;
        }

        $sql = 'UPDATE ' . $this->table('workflow') . ' SET ' . implode(', ', $sets);
        $sql .= ' WHERE rowid = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;

        return (bool) $this->db->query($sql);
    }

    /**
     * Delete a workflow row (hard delete; soft delete handled via status archived).
     */
    public function delete(int $workflowId, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('workflow');
        $sql .= ' WHERE rowid = ' . (int) $workflowId . ' AND entity = ' . (int) $entity;

        return (bool) $this->db->query($sql);
    }

    /**
     * @param array<int, string> $statuses
     * @return array<string, mixed>|null
     */
    private function fetchByIdInternal(int $workflowId, int $entity, array $statuses): ?array
    {
        $sql = 'SELECT rowid, ref, label, description, status, json_definition, version_schema, entity, single_instance, ';
        $sql .= 'activation_warning_dismissed, fk_user_creat, fk_user_modif, date_creation, tms ';
        $sql .= 'FROM ' . $this->table('workflow') . ' ';
        $sql .= 'WHERE rowid = ' . (int) $workflowId . ' ';
        $sql .= 'AND entity = ' . (int) $entity;

        if ($statuses !== []) {
            $clean = array_values(array_filter(array_map(
                fn (string $s): string => $this->db->escape(strtolower(trim($s))),
                $statuses
            )));
            if ($clean !== []) {
                $sql .= " AND status IN ('" . implode("','", $clean) . "')";
            }
        }

        $res = $this->db->query($sql);
        if (!$res || $this->db->num_rows($res) === 0) {
            return null;
        }

        $obj = $this->db->fetch_object($res);
        $definition = json_decode((string) $obj->json_definition, true);
        if (!is_array($definition)) {
            $definition = ['schemaVersion' => '1.0', 'workflow' => [], 'nodes' => [], 'edges' => []];
        }

        return [
            'id' => (int) $obj->rowid,
            'ref' => (string) $obj->ref,
            'label' => (string) $obj->label,
            'description' => $obj->description !== null ? (string) $obj->description : '',
            'status' => (string) $obj->status,
            'definition' => $definition,
            'schemaVersion' => (string) $obj->version_schema,
            'entity' => (int) $obj->entity,
            'singleInstance' => (int) ($obj->single_instance ?? 0) === 1,
            'activationWarningDismissed' => (int) ($obj->activation_warning_dismissed ?? 0) === 1,
            'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
            'modifiedBy' => $obj->fk_user_modif !== null ? (int) $obj->fk_user_modif : null,
            'createdAt' => (string) $obj->date_creation,
            'updatedAt' => (string) $obj->tms,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function encodeDefinition(array $definition): string
    {
        $encoded = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '{}';
    }

    private function normaliseStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'draft';
    }

    private function cleanLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($label === '') {
            $label = 'Untitled workflow';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($label, 0, 255);
        }
        return substr($label, 0, 255);
    }

    private function generateRef(int $entity): string
    {
        $prefix = 'KW' . date('Ymd') . sprintf('%03d', $entity);
        // COUNT+1 collides after deletes or non-contiguous refs; take MAX numeric suffix.
        $sql = 'SELECT MAX(CAST(SUBSTRING_INDEX(ref, \'-\', -1) AS UNSIGNED)) AS m FROM ' . $this->table('workflow');
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= " AND ref LIKE '" . $this->db->escape($prefix) . "-%'";
        $sql .= " AND SUBSTRING_INDEX(ref, '-', -1) REGEXP '^[0-9]+$'";

        $max = 0;
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res)) && $obj->m !== null) {
            $max = (int) $obj->m;
        }

        return $prefix . '-' . sprintf('%04d', $max + 1);
    }
}
