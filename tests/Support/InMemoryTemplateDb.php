<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * In-memory DB stub for {@see \Knot\Repository\TemplateRepository} unit tests.
 */
final class InMemoryTemplateDb extends \DoliDB
{
    /** @var array<int, array<string, mixed>> */
    public array $templates = [];

    /** @var array<int, array<string, mixed>> */
    public array $workflows = [];

    private int $nextTemplateId = 1;

    /** @var array<int, mixed> */
    private array $resultSets = [];

    private int $cursor = 0;

    private int $lastAffectedRows = 0;

    public function query(string $sql)
    {
        $this->lastAffectedRows = 0;

        if (str_contains($sql, 'FROM llx_knot_template') && str_contains($sql, 'ORDER BY label ASC')) {
            $entity = 1;
            if (preg_match('/WHERE entity = (\d+)/', $sql, $m)) {
                $entity = (int) $m[1];
            }
            $rows = array_values(array_filter(
                $this->templates,
                static fn (array $row): bool => (int) ($row['entity'] ?? 0) === $entity
            ));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
            $this->resultSets[++$this->cursor] = ['__list' => $rows];

            return $this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_template') && str_contains($sql, 'WHERE slug = "')) {
            preg_match('/WHERE slug = "([^"]*)" AND entity = (\d+)/', $sql, $m);
            $slug = stripcslashes($m[1] ?? '');
            $entity = (int) ($m[2] ?? 0);
            foreach ($this->templates as $row) {
                if ((string) ($row['slug'] ?? '') === $slug && (int) ($row['entity'] ?? 0) === $entity) {
                    $this->resultSets[++$this->cursor] = $row;

                    return $this->cursor;
                }
            }
            $this->resultSets[++$this->cursor] = [];

            return $this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_template') && str_contains($sql, 'WHERE rowid = ')) {
            preg_match('/WHERE rowid = (\d+) AND entity = (\d+)/', $sql, $m);
            $id = (int) ($m[1] ?? 0);
            $entity = (int) ($m[2] ?? 0);
            $row = $this->templates[$id] ?? null;
            if ($row !== null && (int) ($row['entity'] ?? 0) === $entity) {
                $this->resultSets[++$this->cursor] = $row;

                return $this->cursor;
            }
            $this->resultSets[++$this->cursor] = [];

            return $this->cursor;
        }

        if (preg_match('/^INSERT INTO llx_knot_template /s', $sql)) {
            $id = $this->nextTemplateId++;
            $this->templates[$id] = [
                'rowid' => $id,
                'ref' => $this->extractInsertValue($sql, 1) ?? 'REF',
                'slug' => $this->extractInsertValue($sql, 2) ?? 'slug',
                'label' => $this->extractInsertValue($sql, 3) ?? 'label',
                'description' => $this->extractInsertValue($sql, 4) ?? '',
                'category' => $this->extractInsertValue($sql, 5) ?? 'general',
                'tier' => $this->extractInsertValue($sql, 6) ?? 'free',
                'status' => $this->extractInsertValue($sql, 7) ?? 'published',
                'icon' => $this->extractInsertValue($sql, 8) ?? '',
                'json_definition' => stripcslashes($this->extractInsertValue($sql, 9) ?? '{}'),
                'cached_at' => $this->extractInsertValue($sql, 11) ?? date('Y-m-d H:i:s'),
                'source' => $this->extractInsertValue($sql, 12) ?? 'license.knot.tools',
                'entity' => (int) ($this->extractInsertValue($sql, 13) ?? 1),
            ];
            $this->lastAffectedRows = 1;

            return ++$this->cursor;
        }

        if (preg_match('/^UPDATE llx_knot_template\s+SET /s', $sql) && preg_match('/WHERE rowid = (\d+)/', $sql, $m)) {
            $id = (int) $m[1];
            if (isset($this->templates[$id])) {
                foreach ([
                    'label' => '/label = "([^"]*)"/',
                    'description' => '/description = "([^"]*)"/',
                    'category' => '/category = "([^"]*)"/',
                    'tier' => '/tier = "([^"]*)"/',
                    'status' => '/status = "([^"]*)"/',
                    'icon' => '/icon = "([^"]*)"/',
                    'json_definition' => '/json_definition = "([^"]*)"/',
                    'cached_at' => '/cached_at = "([^"]*)"/',
                    'source' => '/source = "([^"]*)"/',
                ] as $field => $pattern) {
                    if (preg_match($pattern, $sql, $match)) {
                        $this->templates[$id][$field] = stripcslashes($match[1]);
                    }
                }
                $this->lastAffectedRows = 1;
            }

            return ++$this->cursor;
        }

        if (preg_match('/^DELETE FROM llx_knot_template WHERE entity = (\d+) AND source = "license.knot.tools" AND slug NOT IN \((.+)\)$/', $sql, $m)) {
            $entity = (int) $m[1];
            preg_match_all('/"([^"]*)"/', $m[2], $keep);
            $keepSlugs = $keep[1] ?? [];
            $deleted = 0;
            foreach ($this->templates as $id => $row) {
                if ((int) ($row['entity'] ?? 0) !== $entity) {
                    continue;
                }
                if ((string) ($row['source'] ?? '') !== 'license.knot.tools') {
                    continue;
                }
                if (!in_array((string) ($row['slug'] ?? ''), $keepSlugs, true)) {
                    unset($this->templates[$id]);
                    $deleted++;
                }
            }
            $this->lastAffectedRows = $deleted;

            return ++$this->cursor;
        }

        if (str_contains($sql, 'FROM llx_knot_workflow') && str_contains($sql, 'WHERE ref = "')) {
            preg_match('/WHERE ref = "([^"]*)" AND entity = (\d+)/', $sql, $m);
            $ref = stripcslashes($m[1] ?? '');
            $entity = (int) ($m[2] ?? 0);
            foreach ($this->workflows as $row) {
                if ((string) ($row['ref'] ?? '') === $ref && (int) ($row['entity'] ?? 0) === $entity) {
                    $this->resultSets[++$this->cursor] = $row;

                    return $this->cursor;
                }
            }
            $this->resultSets[++$this->cursor] = [];

            return $this->cursor;
        }

        if (preg_match('/^INSERT INTO llx_knot_workflow /s', $sql)) {
            $ref = $this->extractInsertValue($sql, 1) ?? '';
            $entity = (int) ($this->extractInsertValue($sql, 9) ?? 1);
            if ($ref !== '') {
                $this->workflows[] = [
                    'rowid' => count($this->workflows) + 1,
                    'ref' => stripcslashes($ref),
                    'entity' => $entity,
                ];
            }
            $this->lastAffectedRows = 1;

            return ++$this->cursor;
        }

        return false;
    }

    public function fetch_object($resource): ?object
    {
        $set = $this->resultSets[$resource] ?? null;
        if ($set === null || $set === []) {
            return null;
        }
        if (is_array($set) && isset($set['__list'])) {
            $row = array_shift($this->resultSets[$resource]['__list']);

            return $row === null ? null : (object) $row;
        }

        return (object) $set;
    }

    public function num_rows($resource): int
    {
        $set = $this->resultSets[$resource] ?? [];
        if ($set === []) {
            return 0;
        }
        if (isset($set['__list'])) {
            return count($set['__list']) + 1;
        }

        return 1;
    }

    public function affected_rows(mixed $resultset = null): int
    {
        return $this->lastAffectedRows;
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function extractQuoted(string $sql, string $needle): ?string
    {
        $pos = strpos($sql, $needle);
        if ($pos === false) {
            return null;
        }
        $start = $pos + strlen($needle);
        $end = strpos($sql, '"', $start);
        if ($end === false) {
            return null;
        }

        return stripcslashes(substr($sql, $start, $end - $start));
    }

    private function extractInsertValue(string $sql, int $index): ?string
    {
        if (!preg_match('/VALUES \((.*)\)\s*$/s', $sql, $m)) {
            return null;
        }
        $parts = $this->splitSqlValues($m[1]);
        if (!isset($parts[$index - 1])) {
            return null;
        }
        $value = trim($parts[$index - 1]);
        if ($value === 'NULL') {
            return null;
        }

        return trim($value, '"');
    }

    /** @return list<string> */
    private function splitSqlValues(string $values): array
    {
        $parts = [];
        $current = '';
        $inString = false;
        $len = strlen($values);
        for ($i = 0; $i < $len; $i++) {
            $ch = $values[$i];
            if ($ch === '"' && ($i === 0 || $values[$i - 1] !== '\\')) {
                $inString = !$inString;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && !$inString) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
