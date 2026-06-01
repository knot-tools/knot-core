<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Connectors\Logic\BranchAware;
use Knot\Connectors\Logic\LoopNode;
use Knot\Credentials\CredentialResolverInterface;
use Knot\Errors\DolibarrErrorTranslator;
use Knot\Errors\KnotError;
use Knot\Observability\RuntimeLogger;
use Knot\Security\SecretMasker;
use Throwable;

/**
 * Knot workflow engine — synchronous execution orchestrator.
 *
 * Responsibilities:
 *  - validate workflow definition,
 *  - resolve execution order (topological with branch awareness),
 *  - run connectors with full context,
 *  - capture per-node logs (input/output/error) optionally persisted,
 *  - mask secrets in outputs/logs.
 */
final class WorkflowEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $nodeLogs = [];

    private int $sequenceCounter = 0;

    private LoopScopeAnalyzer $scopeAnalyzer;

    /**
     * Runtime context propagated to RuntimeLogger.logNodeExecution() so
     * the JSON event carries correlation IDs without threading them
     * through every helper method.
     */
    private int $currentWorkflowId = 0;
    private int $currentExecutionId = 0;
    private int $currentTenant = 0;
    private bool $currentDryRun = false;

    /**
     * @param array<string, ConnectorInterface> $connectors Connector map keyed by node type
     */
    public function __construct(
        private readonly WorkflowValidator $validator,
        private readonly ExecutionContext $contextBuilder,
        private readonly array $connectors = [],
        private readonly ?SecretMasker $masker = null,
        private readonly ?CredentialResolverInterface $credentialResolver = null,
        ?LoopScopeAnalyzer $scopeAnalyzer = null,
        private readonly ?RuntimeLogger $runtimeLogger = null
    ) {
        $this->scopeAnalyzer = $scopeAnalyzer ?? new LoopScopeAnalyzer();
    }

    /**
     * Execute a workflow synchronously.
     *
     * @param array<string, mixed> $definition Workflow definition
     * @param array<string, mixed> $triggerData Trigger data
     * @param array<string, mixed> $options Runtime options
     * @return array<string, mixed>
     */
    public function execute(array $definition, array $triggerData = [], array $options = []): array
    {
        $this->validator->validate($definition);
        $this->nodeLogs = [];

        /** @var array<string, mixed> $workflow */
        $workflow = $definition['workflow'] ?? [];
        $context = $this->contextBuilder->build($workflow, $triggerData, $options);

        // Capture correlation IDs once per execution so RuntimeLogger
        // events can be joined back to the workflow / execution rows
        // in `llx_knot_execution`. `tenant` is the Dolibarr entity, fed
        // by the API layer through `$options['entity']`.
        $this->currentWorkflowId = (int) ($context['workflow']['id'] ?? $options['workflowId'] ?? 0);
        $this->currentExecutionId = (int) ($context['execution']['id'] ?? $options['executionId'] ?? 0);
        $this->currentTenant = (int) ($options['entity'] ?? 0);
        $this->currentDryRun = (bool) ($context['execution']['dryRun'] ?? false);

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = $definition['nodes'];
        /** @var array<int, array<string, mixed>> $edges */
        $edges = $definition['edges'];
        $nodeMap = $this->indexNodes($nodes);
        $ordered = $this->orderNodes($nodes, $edges);

        $masker = $this->masker ?? new SecretMasker();
        $skip = [];
        $this->sequenceCounter = 0;
        $startNodeId = (string) ($options['startNodeId'] ?? '');
        $started = $startNodeId === '';
        if ($startNodeId !== '' && is_array($options['inputData'] ?? null)) {
            $context['json'] = $options['inputData'];
        }

        foreach ($ordered as $nodeId) {
            if (!$started) {
                if ($nodeId === $startNodeId) {
                    $started = true;
                } else {
                    $this->logNode($nodeId, $nodeMap[$nodeId]['type'] ?? 'unknown', 'skipped', [], [], null);
                    continue;
                }
            }

            if (isset($skip[$nodeId])) {
                $this->logNode($nodeId, $nodeMap[$nodeId]['type'] ?? 'unknown', 'skipped', [], [], null);
                continue;
            }

            $node = $nodeMap[$nodeId];
            $type = (string) $node['type'];

            if (str_starts_with($type, 'trigger.')) {
                $context['nodes'][$nodeId] = ['json' => $context['json']];
                $this->logNode($nodeId, $type, 'success', [], $context['json'], null);
                continue;
            }

            if ($this->isRealIterationLoop($node)) {
                $aggregated = $this->runRealIteration(
                    $nodeId,
                    $node,
                    $nodeMap,
                    $edges,
                    $context,
                    $masker,
                    $skip
                );
                $context['nodes'][$nodeId] = ['json' => $aggregated];
                $context['json'] = $aggregated;
                continue;
            }

            $this->runConnectorNode(
                $nodeId,
                $node,
                $type,
                $edges,
                $context,
                $masker,
                $skip
            );
        }

        $context['_logs'] = $this->nodeLogs;
        return $context;
    }

    /**
     * Detect a Loop node configured with realIteration=true that will fan-out.
     *
     * @param array<string, mixed> $node
     */
    private function isRealIterationLoop(array $node): bool
    {
        if ((string) ($node['type'] ?? '') !== 'logic.loop') {
            return false;
        }
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        return !empty($config['realIteration']);
    }

    /**
     * Run a real-iteration loop: for each item the body subgraph is executed
     * once, with `$json` rebound to the current item. Outputs of all passes
     * are aggregated in the loop's own output.
     *
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, mixed> $context
     * @param array<string, true> $skip
     * @return array<string, mixed>
     */
    private function runRealIteration(
        string $loopId,
        array $node,
        array $nodeMap,
        array $edges,
        array &$context,
        SecretMasker $masker,
        array &$skip
    ): array {
        $resolver = new ExpressionResolver();
        $loopNode = new LoopNode($resolver);

        $context['node'] = $node;
        $previewItems = $loopNode->execute($context);
        $items = is_array($previewItems['items'] ?? null) ? $previewItems['items'] : [];
        $this->logNode($loopId, 'logic.loop', 'success', [], [
            'items' => $items,
            'count' => count($items),
            'loopMode' => 'iteration',
        ], null);

        $scope = $this->scopeAnalyzer->detectBody($loopId, $edges);
        $bodyIds = $scope['body'];
        $bodyNodeMap = [];
        foreach ($bodyIds as $bid) {
            if (isset($nodeMap[$bid])) {
                $bodyNodeMap[$bid] = $nodeMap[$bid];
            }
        }
        $bodyEdges = [];
        $bodyIdSet = array_flip($bodyIds);
        foreach ($edges as $edge) {
            $src = (string) $edge['source'];
            $tgt = (string) $edge['target'];
            if (isset($bodyIdSet[$src]) && isset($bodyIdSet[$tgt])) {
                $bodyEdges[] = $edge;
            }
        }
        $bodyOrder = $this->orderNodes(array_values($bodyNodeMap), $bodyEdges);

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $continueOnItemError = !array_key_exists('continueOnItemError', $config)
            || !empty($config['continueOnItemError']);

        $itemOutputs = [];
        $errors = [];
        foreach ($items as $index => $item) {
            $itemContext = $context;
            $itemContext['json'] = is_array($item) ? $item : ['value' => $item];
            $itemContext['loop'] = [
                'index' => $index,
                'total' => count($items),
                'item' => $item,
                'parentLoopId' => $loopId,
            ];
            $itemSkip = [];
            try {
                foreach ($bodyOrder as $bodyId) {
                    if (isset($itemSkip[$bodyId])) {
                        continue;
                    }
                    $bodyNode = $bodyNodeMap[$bodyId];
                    $this->runConnectorNode(
                        $bodyId,
                        $bodyNode,
                        (string) $bodyNode['type'],
                        $bodyEdges,
                        $itemContext,
                        $masker,
                        $itemSkip,
                        ['loopId' => $loopId, 'loopIndex' => $index]
                    );
                }
                $itemOutputs[] = $itemContext['json'];
            } catch (Throwable $e) {
                $errors[] = ['index' => $index, 'message' => $e->getMessage()];
                if (!$continueOnItemError) {
                    foreach ($bodyIds as $bid) {
                        $skip[$bid] = true;
                    }
                    throw $e;
                }
            }
        }

        foreach ($bodyIds as $bid) {
            $skip[$bid] = true;
        }

        $aggregated = [
            'items' => $itemOutputs,
            'count' => count($itemOutputs),
            'errors' => $errors,
            'loopMode' => 'iteration',
            'loopId' => $loopId,
        ];

        return $aggregated;
    }

    /**
     * Execute a single non-iterator connector node and update the context.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, mixed> $context
     * @param array<string, true> $skip
     * @param array<string, mixed> $loopMeta
     */
    private function runConnectorNode(
        string $nodeId,
        array $node,
        string $type,
        array $edges,
        array &$context,
        SecretMasker $masker,
        array &$skip,
        array $loopMeta = []
    ): void {
        $startedAt = microtime(true);
        if (!isset($this->connectors[$type])) {
            $message = 'Connector not registered: ' . $type;
            $context['nodes'][$nodeId] = ['json' => [], 'error' => $message];
            $this->logNode($nodeId, $type, 'error', [], [], $message, $loopMeta, $startedAt);
            return;
        }

        $connector = $this->connectors[$type];
        $context['node'] = $node;
        $input = $context['json'];

        $context['credential'] = null;
        $credentialId = (int) ($node['credentialId'] ?? ($node['config']['credentialId'] ?? 0));
        if ($credentialId > 0 && $this->credentialResolver !== null) {
            $resolved = $this->credentialResolver->resolve($credentialId);
            if ($resolved !== null) {
                $context['credential'] = $resolved;
            }
        }

        if (array_key_exists('pinnedOutput', $node) && is_array($node['pinnedOutput'] ?? null)) {
            $output = $node['pinnedOutput'];
            $context['nodes'][$nodeId] = ['json' => $output, 'pinned' => true];
            $context['json'] = $output;
            $this->logNode(
                $nodeId,
                $type,
                'success',
                $masker->maskArray($input),
                $masker->maskArray($output),
                null,
                $loopMeta,
                $startedAt
            );
            return;
        }

        try {
            $isDryRun = (bool) (($context['execution']['dryRun'] ?? false));
            $retryConfig = is_array($node['config']['retry'] ?? null) ? $node['config']['retry'] : [];
            $maxAttempts = max(1, (int) ($retryConfig['maxAttempts'] ?? 1));
            $backoffMs = max(0, (int) ($retryConfig['backoffMs'] ?? 0));
            $exponential = (bool) ($retryConfig['exponential'] ?? false);

            $attempt = 0;
            $lastException = null;
            $output = [];
            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    if ($isDryRun && $connector instanceof DryRunAware) {
                        $output = $connector->simulate($context);
                        if (!array_key_exists('_dryRun', $output)) {
                            $output['_dryRun'] = true;
                        }
                    } else {
                        $output = $connector->execute($context);
                        if ($isDryRun) {
                            $output['_dryRun'] = false;
                            $output['_dryRunWarning'] = 'Connector does not declare DryRunAware; ran live.';
                        }
                    }
                    if ($attempt > 1) {
                        $output['_retryAttempts'] = $attempt;
                    }
                    $lastException = null;
                    break;
                } catch (Throwable $retryErr) {
                    $lastException = $retryErr;
                    if ($attempt >= $maxAttempts) {
                        break;
                    }
                    if ($backoffMs > 0 && !$isDryRun) {
                        $delay = $exponential ? ($backoffMs * (2 ** ($attempt - 1))) : $backoffMs;
                        usleep((int) ($delay * 1000));
                    }
                }
            }
            if ($lastException !== null) {
                throw $lastException;
            }

            if ($connector instanceof BranchAware) {
                $skipBranches = $connector->branchesToSkip($context, $output);
                foreach ($skipBranches as $branchEdge) {
                    foreach ($edges as $edge) {
                        if (
                            (string) $edge['source'] === $nodeId
                            && ((string) ($edge['sourceHandle'] ?? 'main')) === $branchEdge
                        ) {
                            $this->markDownstreamSkipped((string) $edge['target'], $edges, $skip);
                        }
                    }
                }
            }

            $maskedOutput = $masker->maskArray($output);
            $context['nodes'][$nodeId] = ['json' => $output];
            $context['json'] = $output;
            $this->logNode(
                $nodeId,
                $type,
                'success',
                $masker->maskArray($input),
                $maskedOutput,
                null,
                $loopMeta,
                $startedAt
            );
        } catch (Throwable $e) {
            $failure = $this->normalizeConnectorFailure($e, $nodeId, $type);
            $context['nodes'][$nodeId] = ['json' => [], 'error' => $failure['message']];
            $errorOutput = [
                'error' => true,
                'message' => $failure['message'],
                'translated' => $failure['translated'],
                'knot' => $failure['knot'],
                'nodeId' => $nodeId,
                'nodeType' => $type,
            ];
            $this->logNode(
                $nodeId,
                $type,
                'error',
                $masker->maskArray($input),
                $errorOutput,
                $failure['message'],
                $loopMeta,
                $startedAt
            );

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            $hasErrorRoute = false;
            foreach ($edges as $edge) {
                if ((string) $edge['source'] === $nodeId && ((string) ($edge['sourceHandle'] ?? 'main')) === 'error') {
                    $hasErrorRoute = true;
                    break;
                }
            }
            if ($hasErrorRoute) {
                $context['nodes'][$nodeId] = ['json' => $errorOutput, 'error' => $failure['message']];
                $context['json'] = $errorOutput;
                $context['error'] = [
                    'message' => $failure['message'],
                    'nodeId' => $nodeId,
                    'nodeType' => $type,
                    'translated' => $failure['translated'],
                    'knot' => $failure['knot'],
                ];
                foreach ($edges as $edge) {
                    if (
                        (string) $edge['source'] === $nodeId
                        && ((string) ($edge['sourceHandle'] ?? 'main')) !== 'error'
                    ) {
                        $this->markDownstreamSkipped((string) $edge['target'], $edges, $skip);
                    }
                }
                return;
            }
            if (!empty($config['continueOnFail'])) {
                $context['nodes'][$nodeId] = ['json' => $errorOutput, 'error' => $failure['message']];
                $context['json'] = $errorOutput;
                foreach ($edges as $edge) {
                    if (
                        (string) $edge['source'] === $nodeId
                        && ((string) ($edge['sourceHandle'] ?? 'main')) !== 'error'
                    ) {
                        $this->markDownstreamSkipped((string) $edge['target'], $edges, $skip);
                    }
                }
                return;
            }

            throw $e;
        }
    }

    /**
     * Per-node logs from the last execute() call.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(): array
    {
        return $this->nodeLogs;
    }

    /**
     * Whether any node logged status `error` during the last execute() call.
     *
     * Used to derive the execution row status when the engine absorbed a
     * connector failure (error branch or continueOnFail) without throwing.
     */
    public function hasNodeErrors(): bool
    {
        foreach ($this->nodeLogs as $log) {
            if (($log['status'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * First non-empty error message from node logs, for execution.error_message.
     */
    public function firstNodeErrorMessage(): ?string
    {
        foreach ($this->nodeLogs as $log) {
            if (($log['status'] ?? '') !== 'error') {
                continue;
            }
            $message = $log['errorMessage'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return $message;
            }
            $nodeId = (string) ($log['nodeId'] ?? '');
            $nodeType = (string) ($log['nodeType'] ?? '');

            return trim('Node ' . $nodeId . ' (' . $nodeType . ') failed.');
        }

        return null;
    }

    /**
     * First structured Knot error from node logs (for API error_payload / sync simulate).
     *
     * @return array<string, mixed>|null
     */
    public function firstNodeKnotError(): ?array
    {
        foreach ($this->nodeLogs as $log) {
            if (($log['status'] ?? '') !== 'error') {
                continue;
            }
            $output = $log['output'] ?? null;
            if (!is_array($output)) {
                continue;
            }
            $knot = $output['knot'] ?? null;
            if (is_array($knot) && isset($knot['code'])) {
                return $knot;
            }
        }

        return null;
    }

    /**
     * @return array{message: string, translated: array<string, mixed>, knot: array<string, mixed>}
     */
    private function normalizeConnectorFailure(Throwable $e, string $nodeId, string $nodeType): array
    {
        if ($e instanceof KnotError) {
            $knot = $e->toArray();

            return [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $e->userMessage,
                'translated' => [
                    'code' => $e->knotCode,
                    'technicalMessage' => $e->getMessage(),
                    'messageKey' => 'errors.execution.unknown.message',
                    'suggestedFixKey' => 'errors.execution.unknown.hint',
                    'docLink' => $e->docLink ?? '',
                ],
                'knot' => $knot,
            ];
        }

        $knotError = (new DolibarrErrorTranslator())->translate($e, [
            'nodeId' => $nodeId,
            'nodeType' => $nodeType,
        ]);
        $legacy = (new ErrorTranslator())->translate($e);

        return [
            'message' => $knotError->getMessage() !== '' ? $knotError->getMessage() : $knotError->userMessage,
            'translated' => array_merge($legacy, ['code' => $knotError->knotCode]),
            'knot' => $knotError->toArray(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, true> $skip
     */
    private function markDownstreamSkipped(string $nodeId, array $edges, array &$skip): void
    {
        if (isset($skip[$nodeId])) {
            return;
        }
        $skip[$nodeId] = true;

        foreach ($edges as $edge) {
            if ((string) $edge['source'] === $nodeId) {
                $this->markDownstreamSkipped((string) $edge['target'], $edges, $skip);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @param array<string, mixed> $loopMeta Optional metadata when running inside a real-iteration loop
     * @param float|null $startedAt microtime(true) snapshot taken before the connector ran;
     *                              null for synthetic events (skipped, trigger replay)
     */
    private function logNode(
        string $nodeId,
        string $type,
        string $status,
        array $input,
        array $output,
        ?string $error,
        array $loopMeta = [],
        ?float $startedAt = null
    ): void {
        $durationMs = $startedAt !== null
            ? (int) round((microtime(true) - $startedAt) * 1000)
            : null;
        $entry = [
            'nodeId' => $nodeId,
            'nodeType' => $type,
            'status' => $status,
            'input' => $input,
            'output' => $output,
            'errorMessage' => $error,
            'sequenceOrder' => $this->sequenceCounter++,
            'durationMs' => $durationMs,
        ];
        if ($loopMeta !== []) {
            $entry['loopId'] = (string) ($loopMeta['loopId'] ?? '');
            $entry['loopIndex'] = (int) ($loopMeta['loopIndex'] ?? 0);
        }
        $this->nodeLogs[] = $entry;

        // Forward to the structured runtime logger when the parent
        // wired one. Skipped/trigger-replay events are intentionally
        // dropped: they have no duration ($startedAt === null) and
        // would just inflate Loki ingest with noise. Dry-runs are
        // also excluded — they don't reflect production traffic.
        if (
            $this->runtimeLogger !== null
            && !$this->currentDryRun
            && $startedAt !== null
            && in_array($status, ['success', 'error'], true)
        ) {
            $this->runtimeLogger->logNodeExecution([
                'workflowId' => $this->currentWorkflowId,
                'executionId' => $this->currentExecutionId,
                'nodeId' => $nodeId,
                'nodeType' => $type,
                'status' => $status,
                'durationMs' => $durationMs,
                'tenant' => $this->currentTenant,
                'error' => $error,
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function indexNodes(array $nodes): array
    {
        $indexed = [];
        foreach ($nodes as $node) {
            $indexed[(string) $node['id']] = $node;
        }
        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, string>
     */
    private function orderNodes(array $nodes, array $edges): array
    {
        $incoming = [];
        $outgoing = [];
        foreach ($nodes as $node) {
            $incoming[(string) $node['id']] = 0;
            $outgoing[(string) $node['id']] = [];
        }

        foreach ($edges as $edge) {
            $source = (string) $edge['source'];
            $target = (string) $edge['target'];
            $incoming[$target]++;
            $outgoing[$source][] = $target;
        }

        $queue = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        $ordered = [];

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            $ordered[] = $nodeId;
            foreach ($outgoing[$nodeId] as $target) {
                $incoming[$target]--;
                if ($incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }

        return $ordered;
    }
}
