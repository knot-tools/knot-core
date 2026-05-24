<?php

declare(strict_types=1);

namespace Knot\StateMachine;

use Knot\Errors\StateMachineExtractionError;

final class StateExtractor
{
    /**
     * @return array<string, int>
     */
    public function extractStatusConstants(string $className): array
    {
        try {
            $ref = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            throw new StateMachineExtractionError(
                'KNOT_SM_EXTRACTION_FAILED',
                'Could not inspect this Dolibarr class for status constants.',
                $e->getMessage(),
                null,
                ['class' => $className],
                'Ensure the object module is enabled and the class is loadable.',
                'error',
                $e
            );
        }

        $map = [];
        foreach ($ref->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $name = $constant->getName();
            if (!preg_match('/^(STATUS_|STATUT_)/', $name)) {
                continue;
            }
            $value = $constant->getValue();
            if (!is_int($value)) {
                continue;
            }
            $map[$name] = $value;
        }
        ksort($map);

        return $map;
    }

    public function resolveStatusProperty(object $instance): ?string
    {
        foreach (['statut', 'fk_statut'] as $prop) {
            if (property_exists($instance, $prop)) {
                return $prop;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $statesMap
     */
    public function resolveLogicalState(object $instance, array $statesMap): ?string
    {
        $prop = $this->resolveStatusProperty($instance);
        if ($prop === null) {
            return null;
        }
        $raw = $instance->{$prop} ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = is_int($raw) ? $raw : (int) $raw;

        foreach ($statesMap as $name => $intVal) {
            if ($intVal === $value) {
                return $name;
            }
        }

        return null;
    }

    public function readStatusValue(object $instance): ?int
    {
        $prop = $this->resolveStatusProperty($instance);
        if ($prop === null) {
            return null;
        }
        $raw = $instance->{$prop} ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_int($raw) ? $raw : (int) $raw;
    }
}
