<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

use Knot\Connectors\ConnectorRegistry;
use Knot\Security\SqlTableReferenceValidator;

/**
 * Validates Knot workflow definitions (structure + incremental semantic lint).
 */
final class WorkflowValidator
{
    /** @var list<string>|null */
    private ?array $connectorAllowlist;

    /**
     * @param list<string>|null $connectorAllowlist Known connector type ids (merged Core + extensions).
     *                                        Null defaults to Core-only registry keys.
     */
    public function __construct(?array $connectorAllowlist = null)
    {
        $this->connectorAllowlist = $connectorAllowlist;
    }

    /**
     * @return list<string>
     */
    private function resolvedConnectorAllowlist(): array
    {
        if ($this->connectorAllowlist !== null) {
            return $this->connectorAllowlist;
        }

        return array_keys((new ConnectorRegistry())->all());
    }

    /**
     * Legacy node types persisted in older workflows or bad template seeds.
     */
    private static function canonicalConnectorId(string $nodeType): string
    {
        static $legacy = [
            'communication.slack' => 'action.slack',
            'communication.discord' => 'action.discord',
            'communication.email' => 'action.email',
            'ai.openai_chat' => 'action.ai_openai',
            'http' => 'action.http',
            'trigger.dolibarr.event' => 'trigger.dolibarr_event',
            'dolibarr.event' => 'trigger.dolibarr_event',
            'loop' => 'logic.loop',
            'workflow.execute' => 'logic.execute_workflow',
        ];

        return $legacy[$nodeType] ?? $nodeType;
    }

    /**
     * Validate a workflow definition.
     *
     * @param array<string, mixed> $definition Workflow definition
     * @throws WorkflowValidationException
     */
    public function validate(array $definition): void
    {
        foreach ($this->validateAll($definition) as $issue) {
            if (($issue['severity'] ?? '') === 'error') {
                throw new WorkflowValidationException((string) ($issue['messageKey'] ?? $issue['code'] ?? 'validation_failed'));
            }
        }
    }

    /**
     * Validate but collect every issue instead of throwing on the first.
     *
     * User-visible copy uses Vue i18n `errors.validation.<messageKey>` plus `messageParams`.
     *
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    public function validateAll(array $definition): array
    {
        $issues = [];

        if (($definition['schemaVersion'] ?? null) !== '1.0') {
            $issues[] = $this->issue('error', 'KNOT_DSL_INVALID_STRUCTURE', 'schema_version');
        }

        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? null;
        if (!is_array($nodes)) {
            $issues[] = $this->issue('error', 'KNOT_DSL_INVALID_STRUCTURE', 'nodes_not_array');
            $nodes = [];
        }
        if (!is_array($edges)) {
            $issues[] = $this->issue('error', 'KNOT_DSL_INVALID_STRUCTURE', 'edges_not_array');
            $edges = [];
        }

        $nodeIds = [];
        $typesById = [];
        $hasTrigger = false;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = is_string($node['id'] ?? null) ? $node['id'] : null;
            $type = is_string($node['type'] ?? null) ? $node['type'] : null;
            if ($id === null || $id === '') {
                $issues[] = $this->issue('error', 'KNOT_DSL_INVALID_STRUCTURE', 'node_missing_id');
                continue;
            }
            if (isset($nodeIds[$id])) {
                $issues[] = $this->issue('error', 'KNOT_DSL_NODE_REFERENCE_INVALID', 'duplicate_node_id', ['nodeId' => $id], $id);
            }
            if ($type === null || $type === '') {
                $issues[] = $this->issue('error', 'KNOT_DSL_INVALID_STRUCTURE', 'node_missing_type', [], $id);
            }
            $nodeIds[$id] = true;
            if ($type !== null) {
                $typesById[$id] = $type;
                if (str_starts_with($type, 'trigger.')) {
                    $hasTrigger = true;
                }
            }
        }

        if (!$hasTrigger && $nodes !== []) {
            $issues[] = $this->issue('warning', 'no_trigger', 'no_trigger');
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if (!is_string($source) || !isset($nodeIds[$source])) {
                $issues[] = $this->issue('error', 'KNOT_DSL_EDGE_INVALID', 'edge_source_invalid');
            }
            if (!is_string($target) || !isset($nodeIds[$target])) {
                $issues[] = $this->issue('error', 'KNOT_DSL_EDGE_INVALID', 'edge_target_invalid');
            }
        }

        $allow = $this->resolvedConnectorAllowlist();
        $allowSet = array_fill_keys($allow, true);
        foreach ($typesById as $nid => $ctype) {
            if ($ctype === '') {
                continue;
            }
            $resolvedType = self::canonicalConnectorId($ctype);
            if (!isset($allowSet[$resolvedType])) {
                $issues[] = $this->issue(
                    'warning',
                    'KNOT_DSL_UNKNOWN_CONNECTOR',
                    'unknown_connector',
                    ['connectorType' => $ctype],
                    $nid,
                );
            }
        }

        $this->appendCycleAndOrphanIssues($typesById, $edges, $issues);
        $this->appendSqlQueryTableIssues($nodes, $issues);

        return $issues;
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, array<string, mixed>> $issues
     */
    private function appendSqlQueryTableIssues(array $nodes, array &$issues): void
    {
        global $db;

        $validator = new SqlTableReferenceValidator();
        $doliDb = isset($db) && $db instanceof \DoliDB ? $db : null;

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if ($type !== 'dolibarr.sql_query') {
                continue;
            }
            $nodeId = is_string($node['id'] ?? null) ? $node['id'] : null;
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $query = trim((string) ($config['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            foreach ($validator->lintIssues($query, $doliDb) as $lint) {
                $issues[] = $this->issue(
                    'warning',
                    'KNOT_SQL_UNKNOWN_TABLE',
                    $lint['messageKey'],
                    $lint['messageParams'],
                    $nodeId,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $messageParams
     * @return array{severity: string, code: string, messageKey: string, messageParams: array<string, mixed>, nodeId?: string}
     */
    private function issue(
        string $severity,
        string $code,
        string $messageKey,
        array $messageParams = [],
        ?string $nodeId = null,
    ): array {
        $row = [
            'severity' => $severity,
            'code' => $code,
            'messageKey' => $messageKey,
            'messageParams' => $messageParams,
        ];
        if ($nodeId !== null) {
            $row['nodeId'] = $nodeId;
        }

        return $row;
    }

    /**
     * @param array<string, string> $typesById
     * @param array<int, mixed> $edges
     * @param array<int, array<string, mixed>> $issues
     */
    private function appendCycleAndOrphanIssues(array $typesById, array $edges, array &$issues): void
    {
        if ($typesById === []) {
            return;
        }

        /** @var array<string, list<string>> $adj */
        $adj = [];
        foreach (array_keys($typesById) as $id) {
            $adj[$id] = [];
        }
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $s = $edge['source'] ?? null;
            $t = $edge['target'] ?? null;
            if (!is_string($s) || !is_string($t) || !isset($adj[$s]) || !isset($adj[$t])) {
                continue;
            }
            $adj[$s][] = $t;
        }

        $color = [];
        foreach (array_keys($typesById) as $id) {
            $color[$id] = 0;
        }
        $cycle = false;
        foreach (array_keys($typesById) as $id) {
            if (($color[$id] ?? 0) === 0 && $this->dfsHasCycle($id, $adj, $color)) {
                $cycle = true;
                break;
            }
        }
        if ($cycle) {
            $issues[] = $this->issue('warning', 'KNOT_DSL_GRAPH_CYCLE', 'graph_cycle');
        }

        $roots = [];
        foreach ($typesById as $id => $type) {
            if (str_starts_with($type, 'trigger.')) {
                $roots[] = $id;
            }
        }
        if ($roots === []) {
            return;
        }
        $reachable = [];
        $queue = $roots;
        foreach ($roots as $r) {
            $reachable[$r] = true;
        }
        while ($queue !== []) {
            $u = array_shift($queue);
            foreach ($adj[$u] ?? [] as $v) {
                if (!isset($reachable[$v])) {
                    $reachable[$v] = true;
                    $queue[] = $v;
                }
            }
        }
        foreach ($typesById as $id => $_type) {
            if (!isset($reachable[$id])) {
                $issues[] = $this->issue('warning', 'KNOT_DSL_ORPHAN_NODE', 'orphan_node', ['nodeId' => $id], $id);
            }
        }
    }

    /**
     * @param array<string, list<string>> $adj
     * @param array<string, int> $color 0=white,1=gray,2=black
     */
    private function dfsHasCycle(string $u, array $adj, array &$color): bool
    {
        $color[$u] = 1;
        foreach ($adj[$u] ?? [] as $v) {
            if (($color[$v] ?? 0) === 1) {
                return true;
            }
            if (($color[$v] ?? 0) === 0 && $this->dfsHasCycle($v, $adj, $color)) {
                return true;
            }
        }
        $color[$u] = 2;

        return false;
    }
}
