<?php

declare(strict_types=1);

namespace Knot\Security;

use Knot\Extension\ExtensionRegistry;
use Knot\Repository\AuditLogRepository;

/**
 * Enforces critical workflow activation acknowledgement on the server.
 */
final class WorkflowActivationGuard
{
    public function __construct(
        private readonly WorkflowRiskAnalyzer $analyzer,
        private readonly AuditLogRepository $audit,
    ) {
    }

    public static function create(?ExtensionRegistry $extensions, AuditLogRepository $audit): self
    {
        return new self(new WorkflowRiskAnalyzer(extensions: $extensions), $audit);
    }

    /**
     * @param array<string, mixed>|null $existing Current workflow row from repository.
     * @param array<string, mixed> $incoming Fields being written (status, definition, flags).
     * @return array{blocked: bool, report: WorkflowRiskReport|null, error: string|null}
     */
    public function checkActivation(
        ?array $existing,
        array $incoming,
        bool $criticalActivationAcknowledged,
    ): array {
        $targetStatus = isset($incoming['status'])
            ? strtolower((string) $incoming['status'])
            : strtolower((string) ($existing['status'] ?? 'draft'));

        if ($targetStatus !== 'active') {
            return ['blocked' => false, 'report' => null, 'error' => null];
        }

        $previousStatus = strtolower((string) ($existing['status'] ?? 'draft'));
        $isActivation = $existing === null || $previousStatus !== 'active';
        if (!$isActivation) {
            return ['blocked' => false, 'report' => null, 'error' => null];
        }

        $definition = is_array($incoming['definition'] ?? null)
            ? $incoming['definition']
            : (is_array($existing['definition'] ?? null) ? $existing['definition'] : []);

        $report = $this->analyzer->analyze($definition);
        if (!$report->hasCritical()) {
            return ['blocked' => false, 'report' => $report, 'error' => null];
        }

        if ($criticalActivationAcknowledged) {
            return ['blocked' => false, 'report' => $report, 'error' => null];
        }

        return [
            'blocked' => true,
            'report' => $report,
            'error' => 'workflow_activation_requires_acknowledgement',
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function definitionHasCritical(array $definition): bool
    {
        return $this->analyzer->analyze($definition)->hasCritical();
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $incoming
     * @return bool|null Dismiss flag to persist, or null when unchanged.
     */
    public function resolveDismissFlag(?array $existing, array $incoming): ?bool
    {
        if (array_key_exists('activation_warning_dismissed', $incoming)) {
            return (bool) $incoming['activation_warning_dismissed'];
        }

        if (!array_key_exists('definition', $incoming) || !is_array($incoming['definition'])) {
            return null;
        }

        if ($this->definitionHasCritical($incoming['definition'])) {
            return false;
        }

        return null;
    }

    public function recordCriticalActivationAudit(
        int $workflowId,
        int $userId,
        int $entity,
        WorkflowRiskReport $report,
        bool $dismissedWarning,
    ): void {
        $criticalIds = array_map(
            static fn (array $n): string => (string) $n['nodeId'],
            $report->criticalNodes
        );
        $this->audit->record(
            'workflow.activate.critical',
            'workflow',
            $workflowId,
            $userId,
            [
                'critical_nodes_count' => count($report->criticalNodes),
                'critical_node_ids' => $criticalIds,
                'dismissed_warning' => $dismissedWarning,
            ],
            $entity
        );
    }
}
