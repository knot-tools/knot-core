<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Compute a structural diff between two workflow definitions.
 *
 * Produces a deterministic JSON-friendly report consumed by the visual diff
 * panel:
 *  - nodes.added / nodes.removed / nodes.changed (with per-node patch)
 *  - edges.added / edges.removed
 *  - meta.changed (workflow.label, workflow.description, schemaVersion)
 *
 * The diff is intentionally schema-light so the frontend can render both
 * a graph view (color-coded nodes) and a JSON view (side-by-side patch).
 */
final class WorkflowDiffer
{
    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    public function diff(array $left, array $right): array
    {
        $leftNodes = $this->indexById($left['nodes'] ?? []);
        $rightNodes = $this->indexById($right['nodes'] ?? []);

        $added = [];
        $removed = [];
        $changed = [];
        foreach ($rightNodes as $id => $node) {
            if (!isset($leftNodes[$id])) {
                $added[] = $node;
                continue;
            }
            $patch = $this->shallowPatch($leftNodes[$id], $node);
            if ($patch !== []) {
                $changed[] = [
                    'id' => $id,
                    'type' => (string) ($node['type'] ?? ''),
                    'before' => $leftNodes[$id],
                    'after' => $node,
                    'patch' => $patch,
                ];
            }
        }
        foreach ($leftNodes as $id => $node) {
            if (!isset($rightNodes[$id])) {
                $removed[] = $node;
            }
        }

        $leftEdges = $this->indexEdges($left['edges'] ?? []);
        $rightEdges = $this->indexEdges($right['edges'] ?? []);
        $edgesAdded = array_values(array_diff_key($rightEdges, $leftEdges));
        $edgesRemoved = array_values(array_diff_key($leftEdges, $rightEdges));

        $metaChanged = $this->shallowPatch(
            is_array($left['workflow'] ?? null) ? $left['workflow'] : [],
            is_array($right['workflow'] ?? null) ? $right['workflow'] : []
        );

        return [
            'nodes' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
            'edges' => [
                'added' => $edgesAdded,
                'removed' => $edgesRemoved,
            ],
            'meta' => [
                'changed' => $metaChanged,
                'schemaVersion' => [
                    'left' => (string) ($left['schemaVersion'] ?? ''),
                    'right' => (string) ($right['schemaVersion'] ?? ''),
                ],
            ],
            'summary' => [
                'nodesAdded' => count($added),
                'nodesRemoved' => count($removed),
                'nodesChanged' => count($changed),
                'edgesAdded' => count($edgesAdded),
                'edgesRemoved' => count($edgesRemoved),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<string, array<string, mixed>>
     */
    private function indexById(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = $item;
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexEdges(mixed $edges): array
    {
        $out = [];
        if (!is_array($edges)) {
            return $out;
        }
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $key = sprintf(
                '%s|%s|%s|%s',
                (string) ($edge['source'] ?? ''),
                (string) ($edge['target'] ?? ''),
                (string) ($edge['sourceHandle'] ?? 'main'),
                (string) ($edge['targetHandle'] ?? 'main')
            );
            $out[$key] = $edge;
        }
        return $out;
    }

    /**
     * Shallow object-level diff: detects keys added/removed/value-changed.
     * For nested arrays, the comparison is done on the JSON representation.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{op: string, before?: mixed, after?: mixed}>
     */
    private function shallowPatch(array $before, array $after): array
    {
        $patch = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            $hasBefore = array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);
            if ($hasBefore && !$hasAfter) {
                $patch[(string) $key] = ['op' => 'remove', 'before' => $before[$key]];
                continue;
            }
            if (!$hasBefore && $hasAfter) {
                $patch[(string) $key] = ['op' => 'add', 'after' => $after[$key]];
                continue;
            }
            $b = $before[$key];
            $a = $after[$key];
            if ($this->normalize($b) !== $this->normalize($a)) {
                $patch[(string) $key] = ['op' => 'replace', 'before' => $b, 'after' => $a];
            }
        }
        return $patch;
    }

    private function normalize(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }
}
