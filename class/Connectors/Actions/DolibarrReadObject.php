<?php

declare(strict_types=1);

namespace Knot\Connectors\Actions;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Dolibarr\ObjectFactory;
use Knot\Engine\ExpressionResolver;
use RuntimeException;

/**
 * Reads a supported Dolibarr object by id.
 */
#[Connector(id: 'dolibarr.read_object', category: 'dolibarr')]
final class DolibarrReadObject implements ConnectorInterface
{
    /** Curated slugs for editor enum + assistant prompt (build accepts any ObjectFactory slug). */
    private const CURATED_OBJECT_TYPES = [
        'facture',
        'commande',
        'propal',
        'thirdparty',
        'contact',
        'product',
        'project',
    ];

    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

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
                    'enum' => self::CURATED_OBJECT_TYPES,
                    'enumLabelKeys' => [
                        'connectors.dolibarr.read_object.objectType.facture',
                        'connectors.dolibarr.read_object.objectType.commande',
                        'connectors.dolibarr.read_object.objectType.propal',
                        'connectors.dolibarr.read_object.objectType.thirdparty',
                        'connectors.dolibarr.read_object.objectType.contact',
                        'connectors.dolibarr.read_object.objectType.product',
                        'connectors.dolibarr.read_object.objectType.project',
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
        $typeRaw = trim((string) ($config['objectType'] ?? ''));
        $objectIdRaw = $config['objectId'] ?? null;
        $isExpression = is_string($objectIdRaw) && str_contains($objectIdRaw, '{{');
        $numericId = is_numeric($objectIdRaw) ? (int) $objectIdRaw : 0;
        $valid = $typeRaw !== '' && ($isExpression || $numericId > 0);

        return ['valid' => $valid, 'errors' => $valid ? [] : ['Invalid object type or id']];
    }

    public function execute(array $context): array
    {
        global $db;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $type = trim((string) $resolver->resolve((string) ($config['objectType'] ?? ''), $context));
        $resolvedId = $resolver->resolve($config['objectId'] ?? 0, $context);
        $id = is_numeric($resolvedId) ? (int) $resolvedId : (int) preg_replace('/\D/', '', (string) $resolvedId);

        if ($type === '' || $id <= 0) {
            throw new RuntimeException('Unsupported Dolibarr object type or id.');
        }

        $factory = new ObjectFactory();
        $object = $factory->build($type, $db);
        if ($object->fetch($id) <= 0) {
            throw new RuntimeException('Dolibarr object not found.');
        }

        $slug = strtolower(trim($type));

        return array_merge(
            [
                'objectType' => $slug,
                'id' => (int) $object->id,
                'ref' => (string) ($object->ref ?? ''),
                'label' => (string) ($object->label ?? $object->name ?? ''),
            ],
            $this->snapshotFields($slug, $object),
        );
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFields(string $type, object $object): array
    {
        return match ($type) {
            'facture' => [
                'total_ht' => (float) ($object->total_ht ?? 0),
                'total_ttc' => (float) ($object->total_ttc ?? 0),
                'fk_soc' => (int) ($object->socid ?? $object->fk_soc ?? 0),
                'fk_account' => (int) ($object->fk_account ?? 0),
                'date_lim_reglement' => (int) ($object->date_lim_reglement ?? 0),
            ],
            'thirdparty' => [
                'nom' => (string) ($object->name ?? $object->nom ?? ''),
                'name' => (string) ($object->name ?? $object->nom ?? ''),
                'email' => (string) ($object->email ?? ''),
            ],
            'propal', 'commande' => [
                'total_ht' => (float) ($object->total_ht ?? 0),
                'total_ttc' => (float) ($object->total_ttc ?? 0),
                'fk_soc' => (int) ($object->socid ?? $object->fk_soc ?? 0),
            ],
            default => [],
        };
    }
}
