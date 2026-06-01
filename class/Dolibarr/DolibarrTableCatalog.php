<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Curated Dolibarr table names for SQL assistant grounding and sql_query lint.
 *
 * Primary source: {@see ObjectFactory} MAP slugs; optional live {@see $db} enriches
 * table_element from instantiated Dolibarr classes (same path as dolibarr_schemas).
 */
final class DolibarrTableCatalog
{
    /**
     * Slug → main (+ line) tables when introspection is unavailable.
     *
     * @var array<string, list<string>>
     */
    private const SLUG_TABLES = [
        'thirdparty' => ['llx_societe'],
        'contact' => ['llx_socpeople'],
        'propal' => ['llx_propal', 'llx_propaldet'],
        'commande' => ['llx_commande', 'llx_commandedet'],
        'facture' => ['llx_facture', 'llx_facturedet'],
        'product' => ['llx_product'],
        'service' => ['llx_product'],
        'project' => ['llx_projet'],
        'task' => ['llx_projet_task'],
        'ticket' => ['llx_ticket'],
        'user' => ['llx_user'],
        'member' => ['llx_adherent'],
        'entrepot' => ['llx_entrepot'],
        'stockmove' => ['llx_stock_mouvement'],
        'agenda' => ['llx_actioncomm'],
        'actioncomm' => ['llx_actioncomm'],
        'categorie' => ['llx_categorie'],
        'bankaccount' => ['llx_bank_account'],
        'expense' => ['llx_expensereport', 'llx_expensereport_det'],
        'expedition' => ['llx_expedition', 'llx_expeditionline'],
        'holiday' => ['llx_holiday'],
        'mailing' => ['llx_mailing'],
        'facturefourn' => ['llx_facture_fourn', 'llx_facture_fourn_det'],
        'commandefourn' => ['llx_commande_fournisseur', 'llx_commande_fournisseurdet'],
        'propalfourn' => ['llx_supplier_proposal', 'llx_supplier_proposaldet'],
        'contrat' => ['llx_contrat', 'llx_contratdet'],
        'paiement' => ['llx_paiement'],
    ];

    /**
     * Common misspellings seen in beta SQL Query nodes.
     *
     * @var array<string, string>
     */
    private const TABLE_TYPO_HINTS = [
        'llx_propale' => 'llx_propal',
        'llx_propales' => 'llx_propal',
        'llx_propale_det' => 'llx_propaldet',
        'llx_propaledet' => 'llx_propaldet',
        'llx_thirdparty' => 'llx_societe',
        'llx_thirdparties' => 'llx_societe',
        'llx_customer' => 'llx_societe',
        'llx_invoice' => 'llx_facture',
        'llx_order' => 'llx_commande',
        'llx_proposal' => 'llx_propal',
    ];

    /**
     * @return list<array{slug: string, label: string, tables: list<string>, source: string}>
     */
    public function objectsForPrompt(?\DoliDB $db = null, ?object $langs = null): array
    {
        $factory = new ObjectFactory();
        $rows = [];
        foreach (self::SLUG_TABLES as $slug => $fallbackTables) {
            $tables = $this->resolveTablesForSlug($slug, $fallbackTables, $db);
            $label = $slug;
            foreach ($factory->listObjectsForApi($langs, $db) as $item) {
                if ($item['slug'] === $slug) {
                    $label = (string) $item['label'];
                    break;
                }
            }
            $rows[] = [
                'slug' => $slug,
                'label' => $label,
                'tables' => $tables,
                'source' => $db !== null ? 'map+introspection' : 'map',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @return array<string, true> lowercase table name => true
     */
    public function knownTableSet(?\DoliDB $db = null): array
    {
        $set = [];
        foreach ($this->objectsForPrompt($db) as $row) {
            foreach ($row['tables'] as $table) {
                $set[strtolower($table)] = true;
            }
        }

        return $set;
    }

    public function hintForTable(string $table): ?string
    {
        $lower = strtolower(trim($table));
        if ($lower === '') {
            return null;
        }

        return self::TABLE_TYPO_HINTS[$lower] ?? null;
    }

    /**
     * @return list<string>
     */
    public function extractTableReferences(string $sql): array
    {
        if ($sql === '') {
            return [];
        }

        if (!preg_match_all('/\b((?:llx|MAIN_DB_PREFIX)[a-z0-9_]*)\b/i', $sql, $matches)) {
            return [];
        }

        $prefix = defined('MAIN_DB_PREFIX') ? strtolower((string) MAIN_DB_PREFIX) : 'llx_';
        $seen = [];
        $out = [];
        foreach ($matches[1] as $raw) {
            $token = strtolower((string) $raw);
            if (str_starts_with($token, 'main_db_prefix')) {
                $token = $prefix . substr($token, strlen('main_db_prefix'));
            }
            if (!str_starts_with($token, 'llx_') || strlen($token) < 5) {
                continue;
            }
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $out[] = $token;
        }

        return $out;
    }

    /**
     * @return list<array{table: string, hint: ?string}>
     */
    public function unknownTables(string $sql, ?\DoliDB $db = null): array
    {
        $known = $this->knownTableSet($db);
        $unknown = [];
        foreach ($this->extractTableReferences($sql) as $table) {
            if (isset($known[$table])) {
                continue;
            }
            $unknown[] = [
                'table' => $table,
                'hint' => $this->hintForTable($table),
            ];
        }

        return $unknown;
    }

    /**
     * @param list<string> $fallbackTables
     *
     * @return list<string>
     */
    private function resolveTablesForSlug(string $slug, array $fallbackTables, ?\DoliDB $db): array
    {
        if ($db === null) {
            return $fallbackTables;
        }

        try {
            $factory = new ObjectFactory();
            $object = $factory->build($slug, $db);
            $tables = [];
            if (isset($object->table_element) && is_string($object->table_element) && $object->table_element !== '') {
                $tables[] = 'llx_' . $object->table_element;
            }
            if (
                isset($object->table_element_line)
                && is_string($object->table_element_line)
                && $object->table_element_line !== ''
            ) {
                $tables[] = 'llx_' . $object->table_element_line;
            }
            if ($tables !== []) {
                return array_values(array_unique($tables));
            }
        } catch (\Throwable) {
            // Fall back to curated list when class or module is missing on this install.
        }

        return $fallbackTables;
    }
}
