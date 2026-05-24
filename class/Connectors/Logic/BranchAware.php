<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

/**
 * Marker interface for connectors that decide which output branches to skip.
 *
 * Implemented by `IfElseNode`, `SwitchNode`, etc. Returns the list of source
 * handle ids that must NOT be followed by the engine in this run.
 */
interface BranchAware
{
    /**
     * @param array<string, mixed> $context Execution context
     * @param array<string, mixed> $output  Output of execute()
     * @return array<int, string> Source handle ids to skip
     */
    public function branchesToSkip(array $context, array $output): array;
}
