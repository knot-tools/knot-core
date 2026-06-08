<?php

declare(strict_types=1);

namespace Knot\Assistant;

use Knot\Connectors\ConnectorInterface;

/**
 * Builds compact connector specs for the Assistant Tier 1 prompt.
 *
 * Specs are read at runtime from loaded ConnectorInterface instances —
 * never duplicated from Pro Pack source in Core Git.
 */
final class AssistantConnectorPromptCatalog
{
    /**
     * @param array<string, ConnectorInterface> $connectors keyed by metadata id
     *
     * @return list<array<string, mixed>>
     */
    public static function fromLoadedConnectors(array $connectors): array
    {
        $rows = [];
        foreach ($connectors as $connector) {
            $rows[] = self::compactEntry($connector);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $catalogEntries from assistant API assembly
     *
     * @return list<array<string, mixed>>
     */
    public static function fromCatalogEntries(array $catalogEntries): array
    {
        $rows = [];
        foreach ($catalogEntries as $entry) {
            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
            $id = (string) ($metadata['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'category' => (string) ($metadata['category'] ?? 'other'),
                'labelKey' => (string) ($metadata['labelKey'] ?? ''),
                'credentialType' => $entry['credentialType'] ?? null,
                'inputs' => $entry['inputs'] ?? [],
                'outputs' => $entry['outputs'] ?? [],
                'config' => self::compactConfigSchema(
                    is_array($entry['configSchema'] ?? null) ? $entry['configSchema'] : []
                ),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $compactRows
     */
    public static function formatForPrompt(array $compactRows): string
    {
        if ($compactRows === []) {
            return '(aucun connecteur disponible)';
        }

        $lines = [];
        foreach ($compactRows as $row) {
            $lines[] = self::formatRow($row);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function compactEntry(ConnectorInterface $connector): array
    {
        $metadata = $connector->getMetadata();

        return [
            'id' => (string) ($metadata['id'] ?? ''),
            'category' => (string) ($metadata['category'] ?? 'other'),
            'labelKey' => (string) ($metadata['labelKey'] ?? ''),
            'credentialType' => $connector->getCredentialType(),
            'inputs' => $connector->getInputs(),
            'outputs' => $connector->getOutputs(),
            'config' => self::compactConfigSchema($connector->getConfigSchema()),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function compactConfigSchema(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $compactProps = [];

        foreach ($properties as $name => $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $compactProps[(string) $name] = [
                'type' => $prop['type'] ?? 'string',
                'required' => in_array($name, $required, true),
                'enum' => $prop['enum'] ?? null,
                'default' => $prop['default'] ?? null,
            ];
        }

        return [
            'required' => $required,
            'properties' => $compactProps,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function formatRow(array $row): string
    {
        $id = (string) ($row['id'] ?? '');
        $category = (string) ($row['category'] ?? 'other');
        $credential = $row['credentialType'] ?? null;
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        $props = is_array($config['properties'] ?? null) ? $config['properties'] : [];
        $required = is_array($config['required'] ?? null) ? $config['required'] : [];

        $reqNames = [];
        $optNames = [];
        foreach ($props as $name => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $fieldLabel = self::formatFieldName((string) $name, $meta);
            if (in_array($name, $required, true) || ($meta['required'] ?? false)) {
                $reqNames[] = $fieldLabel;
            } else {
                $optNames[] = $fieldLabel;
            }
        }

        $parts = ["{$id} ({$category})"];
        if ($reqNames !== []) {
            $parts[] = 'req=' . implode(',', $reqNames);
        }
        if ($optNames !== []) {
            $parts[] = 'opt=' . implode(',', array_slice($optNames, 0, 8))
                . (count($optNames) > 8 ? ',…' : '');
        }
        if ($credential !== null && $credential !== '') {
            $parts[] = "cred={$credential}";
        }

        $outputs = is_array($row['outputs'] ?? null) ? $row['outputs'] : [];
        $handles = [];
        foreach ($outputs as $out) {
            if (is_array($out) && isset($out['id'])) {
                $handles[] = (string) $out['id'];
            }
        }
        if ($handles !== []) {
            $parts[] = 'out=' . implode('|', $handles);
        }

        return '- ' . implode(' ; ', $parts);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function formatFieldName(string $name, array $meta): string
    {
        $enum = $meta['enum'] ?? null;
        if (!is_array($enum) || $enum === []) {
            return $name;
        }

        $values = array_map(static fn (mixed $v): string => (string) $v, $enum);

        return $name . '[' . implode('|', $values) . ']';
    }
}
