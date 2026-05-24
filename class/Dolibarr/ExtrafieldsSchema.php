<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Loads Dolibarr complementary attribute definitions from {@see llx_extrafields}
 * and merges them into create/update JSON schemas.
 *
 * Runs only when Knot API boots inside Dolibarr (`MAIN_DB_PREFIX` defined).
 */
final class ExtrafieldsSchema
{
    /**
     * Merge extrafield columns into an existing schema produced by {@see SchemaBuilder}.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function mergeInto(array $schema, \DoliDB $db, object $object, int $entity, ?object $langs = null): array
    {
        if (!defined('MAIN_DB_PREFIX')) {
            return $schema;
        }
        $element = $this->elementType($object);
        if ($element === '') {
            return $schema;
        }
        $extras = $this->fetchDefinitions($db, $element, $entity);
        if ($extras === []) {
            return $schema;
        }
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['properties'] = [];
        }
        foreach ($extras as $name => $def) {
            if (isset($schema['properties'][$name])) {
                continue;
            }
            $schema['properties'][$name] = $this->mapToJsonProperty($name, $def, $langs);
            if ($this->isRequired($def)) {
                if (!isset($schema['required']) || !is_array($schema['required'])) {
                    $schema['required'] = [];
                }
                $schema['required'][] = $name;
            }
        }
        if (isset($schema['required']) && is_array($schema['required']) && $schema['required'] !== []) {
            $schema['required'] = array_values(array_unique($schema['required']));
        } elseif (isset($schema['required']) && $schema['required'] === []) {
            unset($schema['required']);
        }
        return $schema;
    }

    private function elementType(object $object): string
    {
        if (isset($object->element) && is_string($object->element) && $object->element !== '') {
            return $object->element;
        }
        if (isset($object->table_element) && is_string($object->table_element) && $object->table_element !== '') {
            return $object->table_element;
        }
        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchDefinitions(\DoliDB $db, string $element, int $entity): array
    {
        $table = constant('MAIN_DB_PREFIX') . 'extrafields';
        $elem = $db->escape($element);
        $sql = 'SELECT name, label, type, size, fielddefault, fieldrequired ';
        $sql .= 'FROM ' . $table . " ";
        $sql .= "WHERE elementtype = '" . $elem . "' ";
        $sql .= 'AND (entity = 0 OR entity = ' . (int) $entity . ') ';
        $sql .= 'ORDER BY pos ASC, name ASC';

        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($o = $db->fetch_object($res)) {
            $name = (string) ($o->name ?? '');
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $out[$name] = [
                'label' => (string) ($o->label ?? ''),
                'type' => (string) ($o->type ?? 'varchar'),
                'size' => (string) ($o->size ?? ''),
                'default' => isset($o->fielddefault) ? (string) $o->fielddefault : '',
                'required' => (int) ($o->fieldrequired ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private function mapToJsonProperty(string $name, array $def, ?object $langs): array
    {
        $type = strtolower((string) ($def['type'] ?? 'varchar'));
        $property = match (true) {
            in_array($type, ['int', 'double'], true) || str_starts_with($type, 'price') => ['type' => 'number'],
            $type === 'boolean' => ['type' => 'boolean'],
            str_contains($type, 'text') || $type === 'html' => ['type' => 'string'],
            $type === 'date' || $type === 'datetime' || $type === 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };

        $label = (string) ($def['label'] ?? $name);
        $property['title'] = $this->translate($label, $langs);
        $property['x-dolibarr-extrafield'] = true;

        return $property;
    }

    private function translate(string $key, ?object $langs): string
    {
        if ($langs !== null && method_exists($langs, 'trans')) {
            $v = $langs->trans($key);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return $key;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function isRequired(array $def): bool
    {
        return (int) ($def['required'] ?? 0) === 1;
    }
}
