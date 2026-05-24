<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Detects which nodes form the body of a real-iteration LoopNode.
 *
 * Convention: a real-iteration loop has TWO output handles
 *   - "iteration": each item enters this branch; the branch is re-executed
 *     once per item with `$json` rebound to the item.
 *   - "done": fired once after every item has been processed; carries the
 *     aggregated output of all iterations.
 *
 * Body detection algorithm (pragmatic):
 *   body = forwardReachable(iterationTargets) \ forwardReachable(doneTargets) \ {loopId}
 *
 * Edges that bring data back to a node from outside the body are tolerated;
 * the body is treated as a self-contained sub-graph for each pass.
 */
final class LoopScopeAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $edges
     * @return array{body: array<int,string>, doneTargets: array<int,string>, iterationTargets: array<int,string>}
     */
    public function detectBody(string $loopId, array $edges): array
    {
        $iterationTargets = [];
        $doneTargets = [];
        foreach ($edges as $edge) {
            if ((string) $edge['source'] !== $loopId) {
                continue;
            }
            $handle = (string) ($edge['sourceHandle'] ?? 'main');
            $target = (string) $edge['target'];
            if ($handle === 'iteration' || $handle === 'main' || $handle === 'item') {
                $iterationTargets[] = $target;
            } elseif ($handle === 'done') {
                $doneTargets[] = $target;
            }
        }

        $iterationTargets = array_values(array_unique($iterationTargets));
        $doneTargets = array_values(array_unique($doneTargets));

        $bodyReach = $this->reachable($iterationTargets, $edges);
        $doneReach = $this->reachable($doneTargets, $edges);

        $body = [];
        foreach ($bodyReach as $nodeId => $_) {
            if (isset($doneReach[$nodeId])) {
                continue;
            }
            if ($nodeId === $loopId) {
                continue;
            }
            $body[] = $nodeId;
        }

        return [
            'body' => $body,
            'iterationTargets' => $iterationTargets,
            'doneTargets' => $doneTargets,
        ];
    }

    /**
     * Forward BFS from a set of nodes.
     *
     * @param array<int, string> $startIds
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, true>
     */
    private function reachable(array $startIds, array $edges): array
    {
        $visited = [];
        $queue = $startIds;
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($edges as $edge) {
                if ((string) $edge['source'] === $current) {
                    $target = (string) $edge['target'];
                    if (!isset($visited[$target])) {
                        $queue[] = $target;
                    }
                }
            }
        }
        return $visited;
    }
}
