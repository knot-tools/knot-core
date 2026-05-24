<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Builds workflow execution contexts.
 */
final class ExecutionContext
{
    /**
     * Build the base execution context.
     *
     * @param array<string, mixed> $workflow Workflow metadata
     * @param array<string, mixed> $trigger Trigger payload
     * @param array<string, mixed> $options Runtime options
     * @return array<string, mixed>
     */
    public function build(array $workflow, array $trigger, array $options = []): array
    {
        $depth = max(0, (int) ($options['depth'] ?? 0));
        $parentExecutionId = $options['parentExecutionId'] ?? null;
        $originChain = is_array($options['originChain'] ?? null) ? $options['originChain'] : [];
        $workflowStack = is_array($options['workflowStack'] ?? null) ? $options['workflowStack'] : [];
        $workflowId = (int) (($workflow['id'] ?? 0) ?: 0);
        if ($workflowId > 0 && !in_array($workflowId, $workflowStack, true)) {
            $workflowStack[] = $workflowId;
        }

        $dryRun = (bool) ($options['dryRun'] ?? false);

        return [
            'workflow' => $workflow,
            'execution' => [
                'id' => $options['executionId'] ?? null,
                'parentExecutionId' => $parentExecutionId,
                'depth' => $depth,
                'idempotencyKey' => $options['idempotencyKey'] ?? null,
                'startedAt' => date(DATE_ATOM),
                'dryRun' => $dryRun,
                'mode' => $dryRun ? 'dry-run' : 'live',
            ],
            'json' => $trigger,
            'nodes' => [],
            'vars' => [],
            'env' => [],
            'originChain' => $originChain,
            'workflowStack' => $workflowStack,
            'now' => date(DATE_ATOM),
        ];
    }
}
