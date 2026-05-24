<?php

declare(strict_types=1);

namespace Knot\StateMachine;

/**
 * Hybrid Dolibarr state machine façade (ADR-005): L1 constants, L2 verb discovery,
 * L3 runtime invocation via {@see RuntimeValidator}.
 *
 * ## Logical current state (`getCurrentState`)
 *
 * The logical state is the **name** of the first matching class constant whose value
 * equals the live status property (`statut` or `fk_statut`). Constants must match
 * `STATUS_*` or `STATUT_*` and hold integer values. Knot does not ship Dolibarr
 * integer literals; mapping is always derived from reflection (L1).
 */
interface StateMachineEngineInterface
{
    /**
     * @return array<string, int> Constant name => Dolibarr integer value
     */
    public function getStates(string $slugOrFqcn, \DoliDB $db): array;

    /**
     * Status-oriented transitions discovered via {@see VerbDiscoverer} and filtered
     * by {@see TransitionDetector}.
     *
     * @return list<array<string, mixed>>
     */
    public function getTransitions(string $slugOrFqcn, \DoliDB $db): array;

    /**
     * @param array<string, int> $statesMap Output of {@see getStates()}
     */
    public function getCurrentState(object $instance, array $statesMap): ?string;

    /**
     * Invokes a Dolibarr transition method with Dolibarr-compatible argument injection.
     *
     * @param array<string, mixed> $namedArguments Extra named parameters beyond automatic `$user` injection
     *
     * @return array{changed: bool, method: string, return_code: int}
     */
    public function transition(object $instance, string $methodName, object $user, array $namedArguments = []): array;

    /**
     * Ranks transitions for UX hints (`high` | `medium` | `low`).
     *
     * @return list<array{method: string, maturity: string, probability: string, pattern: string}>
     */
    public function getProbableTransitions(object $instance, string $slugOrFqcn, \DoliDB $db): array;
}
