<?php

declare(strict_types=1);

namespace Knot\Security;

use Knot\Dolibarr\DolibarrTableCatalog;

/**
 * Defensive lint for {@see \Knot\Connectors\Dolibarr\SqlQuery} — unknown llx_* tables.
 */
final class SqlTableReferenceValidator
{
    public function __construct(private readonly DolibarrTableCatalog $catalog = new DolibarrTableCatalog())
    {
    }

    /**
     * @return list<array{table: string, hint: ?string}>
     */
    public function findUnknownTables(string $query, ?\DoliDB $db = null): array
    {
        return $this->catalog->unknownTables($query, $db);
    }

    /**
     * Validation issues for workflow lint (warning severity).
     *
     * @return list<array{table: string, hint: ?string, messageKey: string, messageParams: array<string, string>}>
     */
    public function lintIssues(string $query, ?\DoliDB $db = null): array
    {
        $issues = [];
        foreach ($this->findUnknownTables($query, $db) as $row) {
            $params = ['table' => $row['table']];
            if ($row['hint'] !== null && $row['hint'] !== '') {
                $params['hint'] = $row['hint'];
            }
            $issues[] = [
                'table' => $row['table'],
                'hint' => $row['hint'],
                'messageKey' => $row['hint'] !== null && $row['hint'] !== ''
                    ? 'sql_unknown_table_hint'
                    : 'sql_unknown_table',
                'messageParams' => $params,
            ];
        }

        return $issues;
    }
}
