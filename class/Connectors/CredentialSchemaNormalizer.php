<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Normalises connector credential schemas to the JSON-schema shape expected
 * by the Credentials UI and {@see api/credentials.php} validation.
 *
 * Canonical format: `{ type, required[], properties: { name: { title, type, secret? } } }`.
 * Pro Pack and third-party add-ons may still ship the legacy `{ fields: [...] }`
 * array; this helper converts both shapes at the API boundary.
 */
final class CredentialSchemaNormalizer
{
    /**
     * @param array<string, mixed>|null $schema
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $schema): ?array
    {
        if ($schema === null) {
            return null;
        }

        if (
            isset($schema['properties'])
            && is_array($schema['properties'])
            && $schema['properties'] !== []
        ) {
            return self::ensureShape($schema);
        }

        if (!isset($schema['fields']) || !is_array($schema['fields'])) {
            return self::ensureShape($schema);
        }

        $properties = [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($schema['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = isset($field['name']) ? trim((string) $field['name']) : '';
            if ($name === '') {
                continue;
            }

            $property = [
                'type' => (string) ($field['type'] ?? 'string'),
                'title' => (string) ($field['label'] ?? $field['title'] ?? $name),
            ];
            if (!empty($field['secret'])) {
                $property['secret'] = true;
            }
            if (isset($field['description']) && (string) $field['description'] !== '') {
                $property['description'] = (string) $field['description'];
            }
            if (array_key_exists('default', $field)) {
                $property['default'] = $field['default'];
            }

            $properties[$name] = $property;
            if (!empty($field['required']) && !in_array($name, $required, true)) {
                $required[] = $name;
            }
        }

        $normalized = $schema;
        $normalized['type'] = $normalized['type'] ?? 'object';
        $normalized['properties'] = $properties;
        $normalized['required'] = $required;
        unset($normalized['fields']);

        return self::ensureShape($normalized);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function ensureShape(array $schema): array
    {
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['properties'] = [];
        }
        if (!isset($schema['required']) || !is_array($schema['required'])) {
            $schema['required'] = [];
        }

        return $schema;
    }
}
