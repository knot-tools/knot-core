<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

/**
 * Structural diff between two snapshots produced by {@see SchemaSnapshotter}.
 */
final class SchemaComparator
{
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $target
     *
     * @return list<array<string, mixed>>
     */
    public function diff(array $baseline, array $target): array
    {
        $changes = [];
        $baseObjects = is_array($baseline['objects'] ?? null) ? $baseline['objects'] : [];
        $targetObjects = is_array($target['objects'] ?? null) ? $target['objects'] : [];

        foreach ($targetObjects as $slug => $tObj) {
            if (!is_array($tObj)) {
                continue;
            }
            $slug = (string) $slug;
            $bObj = is_array($baseObjects[$slug] ?? null) ? $baseObjects[$slug] : null;

            if ($bObj === null) {
                $changes[] = [
                    'kind' => 'object_added',
                    'slug' => $slug,
                ];
                continue;
            }

            $bKeys = isset($bObj['property_keys']) && is_array($bObj['property_keys'])
                ? array_map('strval', $bObj['property_keys'])
                : [];
            $tKeys = isset($tObj['property_keys']) && is_array($tObj['property_keys'])
                ? array_map('strval', $tObj['property_keys'])
                : [];
            $removed = array_values(array_diff($bKeys, $tKeys));
            $added = array_values(array_diff($tKeys, $bKeys));

            foreach ($removed as $prop) {
                $changes[] = [
                    'kind' => 'property_removed',
                    'slug' => $slug,
                    'property' => $prop,
                ];
            }
            foreach ($added as $prop) {
                $changes[] = [
                    'kind' => 'property_added',
                    'slug' => $slug,
                    'property' => $prop,
                ];
            }

            $bTypes = is_array($bObj['property_types'] ?? null) ? $bObj['property_types'] : [];
            $tTypes = is_array($tObj['property_types'] ?? null) ? $tObj['property_types'] : [];
            foreach ($tKeys as $key) {
                if (!in_array($key, $bKeys, true)) {
                    continue;
                }
                $bt = (string) ($bTypes[$key] ?? '');
                $tt = (string) ($tTypes[$key] ?? '');
                if ($bt !== '' && $tt !== '' && $bt !== $tt) {
                    $changes[] = [
                        'kind' => 'property_type_changed',
                        'slug' => $slug,
                        'property' => $key,
                        'from' => $bt,
                        'to' => $tt,
                    ];
                }
            }

            $bStates = is_array($bObj['status_constants'] ?? null) ? $bObj['status_constants'] : [];
            $tStates = is_array($tObj['status_constants'] ?? null) ? $tObj['status_constants'] : [];
            foreach ($bStates as $name => $value) {
                if (!array_key_exists($name, $tStates)) {
                    $changes[] = [
                        'kind' => 'status_constant_removed',
                        'slug' => $slug,
                        'constant' => (string) $name,
                    ];
                } elseif ((int) $tStates[$name] !== (int) $value) {
                    $changes[] = [
                        'kind' => 'status_constant_value_changed',
                        'slug' => $slug,
                        'constant' => (string) $name,
                        'from' => (int) $value,
                        'to' => (int) $tStates[$name],
                    ];
                }
            }

            $bVerbs = isset($bObj['transition_verbs']) && is_array($bObj['transition_verbs'])
                ? $bObj['transition_verbs']
                : [];
            $tVerbs = isset($tObj['transition_verbs']) && is_array($tObj['transition_verbs'])
                ? $tObj['transition_verbs']
                : [];
            foreach ($bVerbs as $verb) {
                $verb = (string) $verb;
                if ($verb !== '' && !in_array($verb, array_map('strval', $tVerbs), true)) {
                    $changes[] = [
                        'kind' => 'transition_verb_removed',
                        'slug' => $slug,
                        'verb' => $verb,
                    ];
                }
            }
        }

        foreach ($baseObjects as $slug => $bObj) {
            if (!isset($targetObjects[$slug])) {
                $changes[] = [
                    'kind' => 'object_removed',
                    'slug' => (string) $slug,
                ];
            }
        }

        return $changes;
    }
}
