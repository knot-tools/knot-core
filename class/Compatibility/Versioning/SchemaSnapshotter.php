<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\SchemaBuilder;
use Knot\StateMachine\StateExtractor;
use Knot\StateMachine\TransitionDetector;

/**
 * Builds canonical JSON snapshots for schema compatibility tooling (ADR-011).
 */
final class SchemaSnapshotter
{
    public function __construct(
        private readonly ObjectFactory $factory = new ObjectFactory(),
        private readonly SchemaBuilder $schemaBuilder = new SchemaBuilder(),
        private readonly StateExtractor $stateExtractor = new StateExtractor(),
        private readonly TransitionDetector $transitionDetector = new TransitionDetector(),
    ) {
    }

    /**
     * @param list<string>|null $slugs When null, pilots ({@see PilotDocuments::SCHEMA_SNAPSHOT_SLUGS}) are used.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?array $slugs, \DoliDB $db, ?object $langs = null): array
    {
        $slugs = $slugs ?? PilotDocuments::SCHEMA_SNAPSHOT_SLUGS;
        $objects = [];
        $warnings = [];

        foreach ($slugs as $slug) {
            $slug = trim($slug);
            if ($slug === '') {
                continue;
            }
            try {
                $object = $this->factory->build($slug, $db);
            } catch (\Throwable $e) {
                $warnings[] = [
                    'slug' => $slug,
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            $schema = $this->schemaBuilder->buildForActionFull($object, SchemaBuilder::ACTION_CREATE, $langs);
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $keys = array_keys($properties);
            sort($keys);

            $types = [];
            foreach ($keys as $key) {
                $def = $properties[$key];
                $types[$key] = is_array($def) ? (string) ($def['type'] ?? 'unknown') : 'unknown';
            }

            $fqcn = $this->factory->fqcnForSlug($slug, $db);
            $states = $this->stateExtractor->extractStatusConstants($fqcn);
            $verbs = $this->transitionDetector->discoverTransitions($fqcn);
            $verbNames = array_values(array_unique(array_map(static fn (array $v): string => (string) ($v['name'] ?? ''), $verbs)));
            sort($verbNames);

            $objects[$slug] = [
                'slug' => $slug,
                'class' => ltrim($fqcn, '\\'),
                'fields_hash' => $this->schemaBuilder->fieldsHash($object),
                'property_keys' => $keys,
                'property_types' => $types,
                'status_constants' => $states,
                'transition_verbs' => $verbNames,
            ];
        }

        return [
            'schema_version' => 'knot.snapshot.v1',
            'generated_at' => gmdate('c'),
            'dolibarr_version' => defined('DOL_VERSION') ? (string) constant('DOL_VERSION') : 'unknown',
            'objects' => $objects,
            'warnings' => $warnings,
        ];
    }
}
