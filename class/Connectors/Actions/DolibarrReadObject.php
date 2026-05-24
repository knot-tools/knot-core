<?php

declare(strict_types=1);

namespace Knot\Connectors\Actions;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use RuntimeException;

/**
 * Reads a supported Dolibarr object by id.
 */
#[Connector(id: 'dolibarr.read_object', category: 'dolibarr')]
final class DolibarrReadObject implements ConnectorInterface
{
    private const TYPES = [
        'thirdparty' => ['/societe/class/societe.class.php', 'Societe'],
        'contact' => ['/contact/class/contact.class.php', 'Contact'],
        'product' => ['/product/class/product.class.php', 'Product'],
        'project' => ['/projet/class/project.class.php', 'Project'],
        'propal' => ['/comm/propal/class/propal.class.php', 'Propal'],
        'order' => ['/commande/class/commande.class.php', 'Commande'],
        'invoice' => ['/compta/facture/class/facture.class.php', 'Facture'],
    ];

    public function getMetadata(): array
    {
        return [
            'id' => 'dolibarr.read_object',
            'labelKey' => 'connectors.dolibarr.read_object.label',
            'descriptionKey' => 'connectors.dolibarr.read_object.description',
            'category' => 'dolibarr',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => ['db'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['objectType', 'objectId'],
            'properties' => [
                'objectType' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.read_object.fields.objectType.title',
                    'enum' => array_keys(self::TYPES),
                    'enumLabelKeys' => [
                        'connectors.dolibarr.read_object.objectType.thirdparty',
                        'connectors.dolibarr.read_object.objectType.contact',
                        'connectors.dolibarr.read_object.objectType.product',
                        'connectors.dolibarr.read_object.objectType.project',
                        'connectors.dolibarr.read_object.objectType.propal',
                        'connectors.dolibarr.read_object.objectType.order',
                        'connectors.dolibarr.read_object.objectType.invoice',
                    ],
                    'x-position' => 0,
                ],
                'objectId' => [
                    'type' => 'integer',
                    'titleKey' => 'connectors.dolibarr.read_object.fields.objectId.title',
                    'descriptionKey' => 'connectors.dolibarr.read_object.fields.objectId.description',
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
        $valid = isset($config['objectType'], $config['objectId'])
            && isset(self::TYPES[(string) $config['objectType']])
            && (int) $config['objectId'] > 0;

        return ['valid' => $valid, 'errors' => $valid ? [] : ['Invalid object type or id']];
    }

    public function execute(array $context): array
    {
        global $db;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $type = (string) ($config['objectType'] ?? '');
        $id = (int) ($config['objectId'] ?? 0);

        if (!isset(self::TYPES[$type]) || $id <= 0) {
            throw new RuntimeException('Unsupported Dolibarr object type or id.');
        }

        [$includePath, $className] = self::TYPES[$type];
        require_once constant('DOL_DOCUMENT_ROOT') . $includePath;

        if (!class_exists($className)) {
            throw new RuntimeException('Dolibarr class not found: ' . $className);
        }

        $object = new $className($db);
        if ($object->fetch($id) <= 0) {
            throw new RuntimeException('Dolibarr object not found.');
        }

        return [
            'objectType' => $type,
            'id' => (int) $object->id,
            'ref' => (string) ($object->ref ?? ''),
            'label' => (string) ($object->label ?? $object->name ?? ''),
        ];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}
