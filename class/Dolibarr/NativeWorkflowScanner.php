<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

final class NativeWorkflowScanner
{
    private const CONFLICT_MAP = [
        'dolibarr.convert_propal_order' => ['WORKFLOW_PROPAL_AUTOCREATE_ORDER'],
        'dolibarr.convert_order_invoice' => ['WORKFLOW_ORDER_AUTOCREATE_INVOICE'],
        'dolibarr.validate_invoice' => ['WORKFLOW_INVOICE_*'],
        'dolibarr.ship_order' => ['WORKFLOW_ORDER_AUTOCREATE_SHIPPING', 'WORKFLOW_ORDER_CLASSIFY_SHIPPED_SHIPPING'],
    ];

    public function __construct(private readonly \DoliDB $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(int $entity): array
    {
        $sql = 'SELECT name, value, entity FROM ' . \MAIN_DB_PREFIX . 'const ';
        $sql .= "WHERE name LIKE 'WORKFLOW\\_%' AND entity IN (0, " . (int) $entity . ') ';
        $sql .= "AND value NOT IN ('', '0', 'no', 'false') ORDER BY name ASC";
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($res)) {
            $rows[] = [
                'name' => (string) $obj->name,
                'value' => (string) $obj->value,
                'entity' => (int) $obj->entity,
                'severity' => 'warning',
                'description' => $this->describe((string) $obj->name),
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $workflowDefinition
     * @return array<int, array<string, string>>
     */
    public function conflictsForDefinition(array $workflowDefinition, int $entity): array
    {
        $active = array_column($this->scan($entity), 'name');
        $conflicts = [];
        $nodes = is_array($workflowDefinition['nodes'] ?? null) ? $workflowDefinition['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            foreach (self::CONFLICT_MAP[$type] ?? [] as $nativePattern) {
                foreach ($active as $nativeName) {
                    if ($this->matches($nativePattern, (string) $nativeName)) {
                        $conflicts[] = [
                            'nodeId' => (string) ($node['id'] ?? ''),
                            'nodeType' => $type,
                            'nativeWorkflow' => (string) $nativeName,
                            'severity' => 'warning',
                            'suggestion' => 'Gardez une seule source de verite : Dolibarr natif ou Knot, mais evitez les deux pour la meme action.',
                        ];
                    }
                }
            }
        }
        return $conflicts;
    }

    private function matches(string $pattern, string $value): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($value, substr($pattern, 0, -1));
        }
        return $pattern === $value;
    }

    private function describe(string $name): string
    {
        return match ($name) {
            'WORKFLOW_PROPAL_AUTOCREATE_ORDER' => 'Dolibarr cree automatiquement une commande depuis une propale.',
            'WORKFLOW_ORDER_AUTOCREATE_INVOICE' => 'Dolibarr cree automatiquement une facture depuis une commande.',
            'WORKFLOW_ORDER_AUTOCREATE_SHIPPING' => 'Dolibarr cree automatiquement une expedition depuis une commande.',
            default => 'Automatisme natif Dolibarr actif.',
        };
    }
}
