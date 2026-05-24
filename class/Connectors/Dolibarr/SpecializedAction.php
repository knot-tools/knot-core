<?php

declare(strict_types=1);

namespace Knot\Connectors\Dolibarr;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use RuntimeException;

#[Connector(id: 'dolibarr.specialized', category: 'dolibarr')]
final class SpecializedAction implements ConnectorInterface, DryRunAware
{
    public function getMetadata(): array
    {
        return [
            'id' => 'dolibarr.specialized',
            'labelKey' => 'connectors.dolibarr.specialized.label',
            'descriptionKey' => 'connectors.dolibarr.specialized.description',
            'category' => 'dolibarr',
            'riskLevel' => 'critical',
            'reversible' => false,
            'sideEffects' => ['db', 'accounting'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['operation', 'id'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.specialized.fields.operation.title',
                    'enum' => [
                        'validate_invoice',
                        'record_payment',
                        'ship_order',
                        'convert_propal_to_order',
                        'convert_order_to_invoice',
                        'create_relance',
                        'stock_adjust',
                    ],
                    'enumLabelKeys' => [
                        'connectors.dolibarr.specialized.operation.validate_invoice',
                        'connectors.dolibarr.specialized.operation.record_payment',
                        'connectors.dolibarr.specialized.operation.ship_order',
                        'connectors.dolibarr.specialized.operation.convert_propal_to_order',
                        'connectors.dolibarr.specialized.operation.convert_order_to_invoice',
                        'connectors.dolibarr.specialized.operation.create_relance',
                        'connectors.dolibarr.specialized.operation.stock_adjust',
                    ],
                    'x-position' => 0,
                ],
                'id' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.dolibarr.specialized.fields.id.title',
                    'descriptionKey' => 'connectors.dolibarr.specialized.fields.id.description',
                    'x-position' => 1,
                ],
            ],
        ];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function validate(array $config): array
    {
        return [
            'valid' => trim((string) ($config['operation'] ?? '')) !== '' && (int) ($config['id'] ?? 0) > 0,
            'errors' => [],
        ];
    }

    public function execute(array $context): array
    {
        global $db, $user;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $operation = (string) ($config['operation'] ?? '');
        $id = (int) ($config['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('id is required.');
        }

        return match ($operation) {
            'validate_invoice' => $this->callOnObject(
                '/compta/facture/class/facture.class.php',
                'Facture',
                $id,
                ['validate', 'valid'],
                $db,
                $user
            ),
            'convert_propal_to_order' => $this->callOnObject(
                '/comm/propal/class/propal.class.php',
                'Propal',
                $id,
                ['createFromProposal'],
                $db,
                $user
            ),
            'convert_order_to_invoice' => $this->callOnObject(
                '/commande/class/commande.class.php',
                'Commande',
                $id,
                ['createFromOrder'],
                $db,
                $user
            ),
            'ship_order' => $this->callOnObject(
                '/commande/class/commande.class.php',
                'Commande',
                $id,
                ['classifyShipped', 'setStatut'],
                $db,
                $user
            ),
            'stock_adjust' => [
                'operation' => $operation,
                'id' => $id,
                'warning' => 'Use dolibarr.object with stockmove for detailed stock adjustments.',
            ],
            'record_payment' => [
                'operation' => $operation,
                'id' => $id,
                'warning' => 'Payment recording requires bank/account configuration '
                    . 'and is exposed through dolibarr.object in V1.',
            ],
            'create_relance' => [
                'operation' => $operation,
                'id' => $id,
                'warning' => 'Relance generation is template-driven and available through seeded templates.',
            ],
            default => throw new RuntimeException('Unsupported specialized Dolibarr operation: ' . $operation),
        };
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function simulate(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $operation = (string) ($config['operation'] ?? '');
        $id = (int) ($config['id'] ?? 0);
        return [
            '_dryRun' => true,
            'operation' => $operation,
            'id' => $id,
            'success' => true,
            'message' => sprintf('[DRY-RUN] would run Dolibarr specialized action "%s" on id %d.', $operation, $id),
        ];
    }

    /**
     * @param array<int, string> $methods
     * @return array<string, mixed>
     */
    private function callOnObject(string $file, string $class, int $id, array $methods, object $db, object $user): array
    {
        \dol_include_once($file);
        $className = '\\' . $class;
        $object = new $className($db);
        if (method_exists($object, 'fetch')) {
            $object->fetch($id);
        }
        foreach ($methods as $method) {
            if (!method_exists($object, $method)) {
                continue;
            }
            $res = (int) $object->{$method}($user);
            if ($res < 0) {
                throw new RuntimeException(
                    'Dolibarr specialized action failed: ' . (string) ($object->error ?? 'unknown')
                );
            }
            return ['operation' => $method, 'id' => (int) ($object->id ?? $id), 'success' => true];
        }
        throw new RuntimeException('No compatible method found for ' . $class);
    }
}
