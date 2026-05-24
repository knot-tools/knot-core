<?php

declare(strict_types=1);

namespace Knot\Reporting;

use Knot\Dolibarr\NativeWorkflowScanner;
use Knot\Repository\WorkflowRepository;

final class ConflictAnalyzer
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly NativeWorkflowScanner $scanner
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(int $entity): array
    {
        $activeWorkflows = $this->workflows->list($entity, ['active'], 500);
        $sameTrigger = [];
        $nativeConflicts = [];

        foreach ($activeWorkflows as $workflow) {
            $full = $this->workflows->fetch((int) $workflow['id'], $entity);
            $definition = is_array($full['definition'] ?? null) ? $full['definition'] : [];
            foreach ($this->triggerKeys($definition) as $triggerKey) {
                $sameTrigger[$triggerKey][] = [
                    'workflowId' => (int) $workflow['id'],
                    'label' => (string) ($workflow['label'] ?? ''),
                ];
            }
            foreach ($this->scanner->conflictsForDefinition($definition, $entity) as $conflict) {
                $conflict['workflowId'] = (int) $workflow['id'];
                $conflict['workflowLabel'] = (string) ($workflow['label'] ?? '');
                $nativeConflicts[] = $conflict;
            }
        }

        $triggerConflicts = [];
        foreach ($sameTrigger as $trigger => $items) {
            if (count($items) > 1) {
                $triggerConflicts[] = [
                    'trigger' => $trigger,
                    'severity' => count($items) > 3 ? 'warning' : 'info',
                    'workflows' => $items,
                    'suggestion' => 'Verifiez que ces workflows peuvent coexister ou fusionnez-les avec un If/Filter.',
                ];
            }
        }

        return [
            'nativeWorkflows' => $this->scanner->scan($entity),
            'nativeConflicts' => $nativeConflicts,
            'triggerConflicts' => $triggerConflicts,
            'generatedAt' => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, string>
     */
    private function triggerKeys(array $definition): array
    {
        $keys = [];
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if (str_starts_with($type, 'trigger.')) {
                $keys[] = $type . ':' . md5(json_encode($node['config'] ?? []) ?: '{}');
            }
        }
        return $keys;
    }
}
