<?php

declare(strict_types=1);

namespace Knot\Connectors\Dolibarr;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\ObjectRegistryMode;
use Knot\Dolibarr\SchemaBuilder;
use Knot\Engine\ExpressionResolver;
use Knot\Errors\DolibarrErrorTranslator;
use Knot\Errors\KnotError;
use Knot\Errors\PreconditionError;
use Knot\StateMachine\RuntimeValidator;
use Knot\StateMachine\StateMachineEngine;
use RuntimeException;

#[Connector(id: 'dolibarr.object', category: 'dolibarr')]
final class ObjectAction implements ConnectorInterface, DryRunAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'dolibarr.object',
            'labelKey' => 'connectors.dolibarr.object.label',
            'descriptionKey' => 'connectors.dolibarr.object.description',
            'category' => 'dolibarr',
            'riskLevel' => 'critical',
            'reversible' => false,
            'sideEffects' => ['db'],
            'riskByConfig' => [
                'fetch' => 'safe',
                'create' => 'caution',
                'update' => 'caution',
                'add_note' => 'caution',
                'generate_pdf' => 'caution',
                'change_status' => 'critical',
                'delete' => 'critical',
            ],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['operation', 'objectType'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.operation.title',
                    'enum' => ['fetch', 'create', 'update', 'delete', 'change_status', 'add_note', 'generate_pdf'],
                    'enumLabelKeys' => [
                        'connectors.dolibarr.object.operation.fetch',
                        'connectors.dolibarr.object.operation.create',
                        'connectors.dolibarr.object.operation.update',
                        'connectors.dolibarr.object.operation.delete',
                        'connectors.dolibarr.object.operation.change_status',
                        'connectors.dolibarr.object.operation.add_note',
                        'connectors.dolibarr.object.operation.generate_pdf',
                    ],
                    'x-position' => 0,
                ],
                'objectType' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.objectType.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.objectType.description',
                    'x-position' => 1,
                ],
                'id' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.id.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.id.description',
                    'x-position' => 2,
                ],
                'fields' => [
                    'type' => 'object',
                    'titleKey' => 'connectors.dolibarr.object.fields.fields.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.fields.description',
                    'x-position' => 3,
                ],
                'statusMethod' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.statusMethod.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.statusMethod.description',
                    'x-position' => 4,
                ],
                'note' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.note.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.note.description',
                    'x-position' => 5,
                ],
                'objectRegistryMode' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.objectRegistryMode.title',
                    'enum' => ObjectRegistryMode::all(),
                    'enumLabelKeys' => [
                        'connectors.dolibarr.object.registryMode.all_except_unverified',
                        'connectors.dolibarr.object.registryMode.all',
                        'connectors.dolibarr.object.registryMode.curated',
                        'connectors.dolibarr.object.registryMode.discovery',
                        'connectors.dolibarr.object.registryMode.discovery_unverified',
                    ],
                    'descriptionKey' => 'connectors.dolibarr.object.fields.objectRegistryMode.description',
                    'x-position' => 6,
                ],
                'statusMethodCustom' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.dolibarr.object.fields.statusMethodCustom.title',
                    'descriptionKey' => 'connectors.dolibarr.object.fields.statusMethodCustom.description',
                    'x-position' => 7,
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
        $errors = [];
        foreach (['operation', 'objectType'] as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                $errors[] = $field . ' is required';
            }
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function execute(array $context): array
    {
        global $db, $user;

        $objectType = '';
        $operation = '';
        try {
            return $this->executeDolibarrObject($context, $objectType, $operation);
        } catch (KnotError $e) {
            throw $e;
        } catch (\Throwable $t) {
            throw (new DolibarrErrorTranslator())->translate($t, [
                'connector' => 'dolibarr.object',
                'objectType' => $objectType,
                'operation' => $operation,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @param-out string $objectType
     * @param-out string $operation
     *
     * @return array<string, mixed>
     */
    private function executeDolibarrObject(array $context, string &$objectType, string &$operation): array
    {
        global $db, $user;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $factory = new ObjectFactory();
        $objectType = (string) $resolver->resolve((string) ($config['objectType'] ?? ''), $context);
        $operation = (string) ($config['operation'] ?? 'fetch');
        $object = $factory->build($objectType, $db);
        $id = (int) $resolver->resolve((string) ($config['id'] ?? '0'), $context);

        if (
            in_array($operation, ['fetch', 'update', 'delete', 'change_status', 'add_note', 'generate_pdf'], true)
            && $id <= 0
        ) {
            throw new PreconditionError(
                'KNOT_OBJECT_ID_REQUIRED',
                'A Dolibarr object id is required for this operation.',
                'Dolibarr object id is required.',
                'https://knot.tools/docs/errors/catalog#knot-object-id-required',
                ['operation' => $operation, 'objectType' => $objectType],
                'Provide a numeric row id or fetch the record in an earlier step.',
                'warning'
            );
        }

        if (!is_object($user)) {
            throw new PreconditionError(
                'KNOT_USER_REQUIRED',
                'An authenticated Dolibarr user is required.',
                'Authenticated Dolibarr user is required for dolibarr.object operations.',
                'https://knot.tools/docs/errors/catalog#knot-user-required',
                ['connector' => 'dolibarr.object'],
                'Ensure the workflow runs in an authenticated session.',
                'error'
            );
        }

        // Permission gating before any state-mutating operation. We rely on
        // the schema's `x-dolibarr-permission` (computed from `$object->element`)
        // and fall back to never-blocking for fetch/list operations.
        $this->ensurePermission($factory, $objectType, $operation, $config, $user, $db);

        if ($id > 0 && method_exists($object, 'fetch')) {
            $object->fetch($id);
        }

        // For create/update we resolve the user-supplied payload first so we
        // can validate it against the introspected schema before mutating state.
        $resolvedFields = [];
        $resolvedLines = [];
        $warnings = [];
        if (in_array($operation, ['create', 'update'], true)) {
            $rawFields = $resolver->resolve(is_array($config['fields'] ?? null) ? $config['fields'] : [], $context);
            $rawLines = $resolver->resolve(is_array($config['lines'] ?? null) ? $config['lines'] : [], $context);

            $expert = ObjectRegistryMode::isDiscoveryUnverifiedExpert(
                trim((string) ($config['objectRegistryMode'] ?? ''))
            );
            $langs = $this->peekGlobalLangs();
            $payload = $this->validateAndCoercePayload(
                $factory,
                $db,
                $objectType,
                $operation,
                is_array($rawFields) ? $rawFields : [],
                is_array($rawLines) ? $rawLines : [],
                $langs,
                $expert
            );
            $resolvedFields = $payload['fields'];
            $resolvedLines = $payload['lines'];
            $warnings = $payload['warnings'];
        }

        $result = match ($operation) {
            'fetch' => ['object' => $this->exportObject($object, $factory, $objectType, $db)],
            'create' => $this->create($object, $resolvedFields, $resolvedLines, $factory, $objectType, $db, $user),
            'update' => $this->update($object, $resolvedFields, $user),
            'delete' => $this->delete($object, $user),
            'change_status' => $this->changeStatus(
                $object,
                $this->resolveStatusMethod($config),
                $user
            ),
            'add_note' => $this->addNote(
                $object,
                (string) $resolver->resolve((string) ($config['note'] ?? ''), $context),
                $user
            ),
            'generate_pdf' => $this->generatePdf($object, $user),
            default => throw new RuntimeException('Unsupported Dolibarr operation: ' . $operation),
        };

        $payload = array_replace(
            ['operation' => $operation, 'objectType' => $objectType, 'id' => (int) ($object->id ?? $id)],
            $result
        );
        if ($warnings !== []) {
            $payload['_warnings'] = $warnings;
        }
        return $payload;
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function simulate(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $operation = (string) ($config['operation'] ?? 'fetch');
        $objectType = (string) $resolver->resolve((string) ($config['objectType'] ?? ''), $context);
        $id = (int) $resolver->resolve((string) ($config['id'] ?? '0'), $context);

        if ($operation === 'fetch' && $id > 0) {
            try {
                global $db;
                $object = (new ObjectFactory())->build($objectType, $db);
                if (method_exists($object, 'fetch')) {
                    $object->fetch($id);
                }
                return [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => (int) ($object->id ?? $id),
                    'object' => $this->exportObject($object, new ObjectFactory(), $objectType, $db),
                    'message' => sprintf('[DRY-RUN] read-only fetch of %s#%d (real read).', $objectType, $id),
                ];
            } catch (\Throwable $e) {
                return [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => $id,
                    'message' => sprintf(
                        '[DRY-RUN] would fetch %s#%d (could not preview: %s).',
                        $objectType,
                        $id,
                        $e->getMessage()
                    ),
                ];
            }
        }

        if (in_array($operation, ['create', 'update'], true)) {
            try {
                global $db;
                $factory = new ObjectFactory();
                $rawFields = $resolver->resolve(is_array($config['fields'] ?? null) ? $config['fields'] : [], $context);
                $rawLines = $resolver->resolve(is_array($config['lines'] ?? null) ? $config['lines'] : [], $context);
                $expert = ObjectRegistryMode::isDiscoveryUnverifiedExpert(
                    trim((string) ($config['objectRegistryMode'] ?? ''))
                );
                $payload = $this->validateAndCoercePayload(
                    $factory,
                    $db,
                    $objectType,
                    $operation,
                    is_array($rawFields) ? $rawFields : [],
                    is_array($rawLines) ? $rawLines : [],
                    $this->peekGlobalLangs(),
                    $expert
                );
                $out = [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => $id > 0 ? $id : -1,
                    'simulatedFields' => $payload['fields'],
                    'simulatedLines' => $payload['lines'],
                    'message' => sprintf(
                        '[DRY-RUN] would %s %s#%s (payload coerced to schema).',
                        $operation,
                        $objectType,
                        $id > 0 ? (string) $id : 'new'
                    ),
                ];
                if ($payload['warnings'] !== []) {
                    $out['_warnings'] = $payload['warnings'];
                }

                return $out;
            } catch (\Throwable $e) {
                return [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => $id > 0 ? $id : -1,
                    'simulatedFields' => is_array($config['fields'] ?? null) ? $config['fields'] : [],
                    'message' => sprintf(
                        '[DRY-RUN] would %s %s (coercion preview failed: %s).',
                        $operation,
                        $objectType,
                        $e->getMessage()
                    ),
                ];
            }
        }

        if ($operation === 'change_status' && $id > 0) {
            try {
                global $db, $user;
                if (!is_object($user)) {
                    return [
                        '_dryRun' => true,
                        'operation' => $operation,
                        'objectType' => $objectType,
                        'id' => $id,
                        'message' => '[DRY-RUN] change_status preview requires an authenticated Dolibarr user.',
                    ];
                }

                $factory = new ObjectFactory();
                $object = $factory->build($objectType, $db);
                if (method_exists($object, 'fetch')) {
                    $object->fetch($id);
                }
                $engine = new StateMachineEngine($factory);
                $states = $engine->getStates($objectType, $db);
                $logical = $engine->getCurrentState($object, $states);
                $method = $this->resolveStatusMethod($config);
                $probable = $engine->getProbableTransitions($object, $objectType, $db);

                $validator = new RuntimeValidator();
                $invocationReady = false;
                $invocationArgCount = null;
                /** @var array<string, mixed>|null */
                $transitionPreviewError = null;
                try {
                    $plan = $validator->resolveInvocation($object, $method, $user);
                    $invocationReady = true;
                    $invocationArgCount = count($plan['args']);
                } catch (KnotError $ke) {
                    $transitionPreviewError = $ke->toArray();
                }

                return [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => (int) ($object->id ?? $id),
                    'resolvedMethod' => $method,
                    'methodExists' => method_exists($object, $method),
                    'invocationReady' => $invocationReady,
                    'invocationArgCount' => $invocationArgCount,
                    'transitionPreviewError' => $transitionPreviewError,
                    'currentLogicalState' => $logical,
                    'probableTransitions' => $probable,
                    'message' => $transitionPreviewError === null
                        ? sprintf(
                            '[DRY-RUN] would call %s() on %s#%d (invocation plan validated, no DB mutation).',
                            $method,
                            $objectType,
                            $id
                        )
                        : sprintf(
                            '[DRY-RUN] change_status blocked at same validation layer as execution: %s',
                            (string) ($transitionPreviewError['user_message'] ?? 'preview error')
                        ),
                ];
            } catch (\Throwable $e) {
                return [
                    '_dryRun' => true,
                    'operation' => $operation,
                    'objectType' => $objectType,
                    'id' => $id,
                    'message' => sprintf('[DRY-RUN] change_status preview failed: %s.', $e->getMessage()),
                ];
            }
        }

        return [
            '_dryRun' => true,
            'operation' => $operation,
            'objectType' => $objectType,
            'id' => $id > 0 ? $id : -1,
            'simulatedFields' => is_array($config['fields'] ?? null) ? $config['fields'] : [],
            'message' => sprintf('[DRY-RUN] would %s %s#%s.', $operation, $objectType, $id > 0 ? (string) $id : 'new'),
        ];
    }

    private function peekGlobalLangs(): ?object
    {
        return isset($GLOBALS['langs']) && is_object($GLOBALS['langs']) ? $GLOBALS['langs'] : null;
    }

    /**
     * @param array<string, mixed>          $fields
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function create(
        object $object,
        array $fields,
        array $lines,
        ObjectFactory $factory,
        string $objectType,
        \DoliDB $db,
        object $user
    ): array {
        $this->assignFields($object, $fields);
        if (!method_exists($object, 'create')) {
            throw new RuntimeException('Object does not support create().');
        }
        $id = (int) $object->create($user);
        if ($id <= 0) {
            throw new RuntimeException('Dolibarr create failed: ' . (string) ($object->error ?? 'unknown'));
        }

        $linesAdded = 0;
        if ($lines !== []) {
            $linesAdded = $this->addLines($object, $lines, $factory, $objectType, $db, $user);
        }

        return $linesAdded > 0
            ? ['created' => true, 'id' => $id, 'linesAdded' => $linesAdded]
            : ['created' => true, 'id' => $id];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function update(object $object, array $fields, object $user): array
    {
        $this->assignFields($object, $fields);
        if (!method_exists($object, 'update')) {
            throw new RuntimeException('Object does not support update().');
        }
        $res = (int) $object->update($user);
        if ($res < 0) {
            throw new RuntimeException('Dolibarr update failed: ' . (string) ($object->error ?? 'unknown'));
        }
        return ['updated' => true];
    }

    /** @return array<string, mixed> */
    private function delete(object $object, object $user): array
    {
        $method = method_exists($object, 'delete') ? 'delete' : (method_exists($object, 'remove') ? 'remove' : null);
        if ($method === null) {
            throw new RuntimeException('Object does not support delete().');
        }
        $res = (int) $object->{$method}($user);
        if ($res < 0) {
            throw new RuntimeException('Dolibarr delete failed: ' . (string) ($object->error ?? 'unknown'));
        }
        return ['deleted' => true];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveStatusMethod(array $config): string
    {
        $base = trim((string) ($config['statusMethod'] ?? 'setDraft'));
        $custom = trim((string) ($config['statusMethodCustom'] ?? ''));
        if (
            $custom !== '' && ObjectRegistryMode::isDiscoveryUnverifiedExpert(
                trim((string) ($config['objectRegistryMode'] ?? ''))
            )
        ) {
            return $custom;
        }

        return $base !== '' ? $base : 'setDraft';
    }

    /** @return array<string, mixed> */
    private function changeStatus(object $object, string $method, object $user): array
    {
        (new RuntimeValidator())->invokeTransition($object, $method, $user);

        return ['statusChanged' => true, 'method' => $method];
    }

    /** @return array<string, mixed> */
    private function addNote(object $object, string $note, object $user): array
    {
        if (method_exists($object, 'update_note')) {
            $object->note_private = trim(((string) ($object->note_private ?? '')) . "\n" . $note);
            $object->update_note($object->note_private, '_private');
            return ['noteAdded' => true];
        }
        if (method_exists($object, 'add_object_linked')) {
            return ['noteAdded' => false, 'warning' => 'Notes are not supported by this object type.'];
        }
        return ['noteAdded' => false];
    }

    /** @return array<string, mixed> */
    private function generatePdf(object $object, object $user): array
    {
        if (!method_exists($object, 'generateDocument')) {
            throw new RuntimeException('Object does not support PDF generation.');
        }
        $model = (string) ($object->model_pdf ?? '');
        $res = (int) $object->generateDocument($model, 'fr_FR', 0, 0, 0, $user);
        if ($res < 0) {
            throw new RuntimeException('PDF generation failed: ' . (string) ($object->error ?? 'unknown'));
        }
        return ['pdfGenerated' => true];
    }

    /**
     * Dolibarr classes have a long-standing inconsistency: the SQL column is
     * named one way (`fk_soc`, `fk_user_creat`…) but the PHP property is
     * named another way (`socid`, `fk_user_author`…). The `$fields` map
     * exposes the SQL names — Knot users should be able to set them and
     * have the connector route the value to the right place.
     *
     * @var array<string, array<int, string>>
     */
    private const FIELD_TO_PROPERTY_ALIASES = [
        'fk_soc' => ['socid'],
        'fk_user_creat' => ['fk_user_author', 'user_author_id', 'userauthorid'],
        'fk_user_modif' => ['fk_user_modif'],
        'fk_user_valid' => ['fk_user_valid', 'fk_user_validation'],
        'fk_user_cloture' => ['fk_user_close'],
        'fk_projet' => ['fk_project'],
        'fk_project' => ['fk_projet'],
        'fk_warehouse' => ['fk_warehouse', 'warehouse_id'],
        'fk_account' => ['fk_account'],
        'fk_typent' => ['typent_id'],
        'fk_effectif' => ['effectif_id'],
        'fk_forme_juridique' => ['forme_juridique_code'],
        // V2.4.1 : Contrat::create() lit $this->commercial_signature_id et
        // $this->commercial_suivi_id, mais le schema introspecté expose les
        // colonnes SQL fk_commercial_signature / fk_commercial_suivi. Sans
        // ces alias, les fields étaient silencieusement perdus → erreur
        // confuse "Field 'Sales representative (signature)' is required".
        'fk_commercial_signature' => ['commercial_signature_id'],
        'fk_commercial_suivi' => ['commercial_suivi_id'],
        // Date columns are exposed in `$fields` under their SQL names
        // (`datef` for Facture, `datep` for Propal, `date_commande` for
        // Commande...) but the create() routines read `$this->date`. Mirror
        // the value so users only need to set the schema-listed field.
        'datef' => ['date'],
        'datep' => ['date'],
        'date_commande' => ['date'],
        // Contrat::create() does arithmetic on $this->date_contrat itself
        // but also references $this->date in some helpers, so mirror.
        'date_contrat' => ['date'],
    ];

    /**
     * @param array<string, mixed> $fields
     */
    private function assignFields(object $object, array $fields): void
    {
        foreach ($fields as $key => $value) {
            $aliases = self::FIELD_TO_PROPERTY_ALIASES[$key] ?? [];
            if ($aliases !== []) {
                foreach ($aliases as $alias) {
                    $object->{$alias} = $value;
                }
                if (property_exists($object, $key)) {
                    $object->{$key} = $value;
                }
                continue;
            }

            $object->{$key} = $value;
        }
    }

    /**
     * Serialise a loaded Dolibarr object for workflow expressions (fetch / dry-run).
     *
     * Emits every key from {@see CommonObject::$fields} via {@see ObjectFactory::describe()}
     * (plus `array_options` and line rows using each line's `$fields` map when present),
     * then merges canonical shortcuts `id`, `ref`, `label`, `status` expected by existing
     * workflows. Password-typed fields are skipped.
     *
     * @return array<string, mixed>
     */
    private function exportObject(object $object, ObjectFactory $factory, string $objectType, \DoliDB $db): array
    {
        $fromSchema = $this->exportObjectFromDescribedFields($object, $factory, $objectType, $db);

        $canonical = [
            'id' => (int) ($object->id ?? 0),
            'ref' => (string) ($object->ref ?? ''),
            'label' => (string) ($object->label ?? $object->name ?? ''),
            'status' => $object->status ?? $object->statut ?? null,
        ];

        $merged = array_merge($fromSchema, $canonical);
        $merged['id'] = (int) ($object->id ?? $merged['rowid'] ?? 0);

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportObjectFromDescribedFields(
        object $object,
        ObjectFactory $factory,
        string $objectType,
        \DoliDB $db
    ): array {
        try {
            $desc = $factory->describe($objectType, $db);
            $fieldDefs = is_array($desc['fields'] ?? null) ? $desc['fields'] : [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($fieldDefs as $key => $def) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $typeHint = strtolower((string) (is_array($def) ? ($def['type'] ?? '') : ''));
            if (str_contains($typeHint, 'password')) {
                continue;
            }
            $raw = $this->readObjectFieldForExport($object, $key);
            $out[$key] = $this->normaliseExportedValue($raw);
        }

        if (isset($object->array_options) && is_array($object->array_options) && $object->array_options !== []) {
            $extras = [];
            foreach ($object->array_options as $xk => $xv) {
                if (!is_string($xk) || str_contains(strtolower($xk), 'password')) {
                    continue;
                }
                $extras[$xk] = $this->normaliseExportedValue($xv);
            }
            if ($extras !== []) {
                $out['array_options'] = $extras;
            }
        }

        if (isset($object->lines) && is_array($object->lines) && $object->lines !== []) {
            $linesOut = [];
            foreach ($object->lines as $lineObj) {
                if (!is_object($lineObj)) {
                    continue;
                }
                $linesOut[] = $this->exportLineLikeObject($lineObj);
            }
            if ($linesOut !== []) {
                $out['lines'] = $linesOut;
            }
        }

        return $out;
    }

    private function readObjectFieldForExport(object $object, string $fieldKey): mixed
    {
        if ($fieldKey === 'rowid') {
            return $object->rowid ?? $object->id ?? null;
        }
        if (isset($object->{$fieldKey})) {
            return $object->{$fieldKey};
        }
        foreach (self::FIELD_TO_PROPERTY_ALIASES[$fieldKey] ?? [] as $alias) {
            if (isset($object->{$alias})) {
                return $object->{$alias};
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportLineLikeObject(object $line): array
    {
        if (isset($line->fields) && is_array($line->fields)) {
            $row = [];
            foreach (array_keys($line->fields) as $lk) {
                if (!is_string($lk)) {
                    continue;
                }
                $lv = $line->{$lk} ?? null;
                $row[$lk] = $this->normaliseExportedValue($lv);
            }

            return $row;
        }

        return [];
    }

    private function normaliseExportedValue(mixed $v): mixed
    {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
            return $v;
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if (is_array($v)) {
            $z = [];
            foreach ($v as $k => $item) {
                $z[(string) $k] = $this->normaliseExportedValue($item);
            }

            return $z;
        }
        if (is_object($v)) {
            return null;
        }

        return $v;
    }

    /**
     * Validate the user-supplied payload against the introspected schema.
     *
     * - Unknown fields are dropped with a warning (so workflows authored
     *   before the schema existed keep working without a hard error).
     * - Required fields missing → RuntimeException (the operation cannot
     *   succeed at the Dolibarr level either, fail fast and explain why).
     * - Types are coerced (string '12' → int 12 for an integer FK).
     * - If the schema cannot be introspected (eg. exotic object), we fall
     *   back to the legacy behaviour and emit a warning.
     *
     * @param array<string, mixed>           $rawFields
     * @param array<int, array<string,mixed>> $rawLines
     *
     * @return array{
     *     fields:array<string,mixed>,
     *     lines:array<int,array<string,mixed>>,
     *     warnings:array<int,string>
     * }
     */
    private function validateAndCoercePayload(
        ObjectFactory $factory,
        \DoliDB $db,
        string $objectType,
        string $operation,
        array $rawFields,
        array $rawLines,
        ?object $langs = null,
        bool $expertPayload = false
    ): array {
        $warnings = [];

        $fieldView = $expertPayload ? SchemaBuilder::FIELD_VIEW_FULL : SchemaBuilder::FIELD_VIEW_STANDARD;

        try {
            $schema = $factory->describeForAction($objectType, $operation, $db, $langs, $fieldView);
        } catch (\Throwable $e) {
            return [
                'fields' => $rawFields,
                'lines' => $rawLines,
                'warnings' => ['Schema introspection failed (' . $e->getMessage() . '), legacy mode enabled.'],
            ];
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        if ($properties === [] && $required === []) {
            return [
                'fields' => $rawFields,
                'lines' => array_values(array_filter($rawLines, 'is_array')),
                'warnings' => [
                    sprintf(
                        'No introspected field list for %s.%s; using raw payload (enable / check Dolibarr module).',
                        $objectType,
                        $operation
                    ),
                ],
            ];
        }

        $cleanFields = [];
        foreach ($rawFields as $name => $value) {
            if (!isset($properties[$name])) {
                $warnings[] = sprintf('Field "%s" is not part of %s schema, dropped.', $name, $objectType);
                continue;
            }
            $cleanFields[$name] = $this->coerce($value, $properties[$name]);
        }

        $missing = [];
        foreach ($required as $req) {
            if (!array_key_exists($req, $cleanFields) || $cleanFields[$req] === null || $cleanFields[$req] === '') {
                $missing[] = (string) $req;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Missing required field(s) for %s.%s: %s',
                $objectType,
                $operation,
                implode(', ', $missing)
            ));
        }

        // Lines validation (best-effort): rely on the schema's
        // `lines.items` if present, otherwise pass through.
        $cleanLines = [];
        if (isset($properties['lines']['items'])) {
            $lineProperties = is_array($properties['lines']['items']['properties'] ?? null)
                ? $properties['lines']['items']['properties']
                : [];

            foreach ($rawLines as $idx => $line) {
                $cleanLine = [];
                foreach ($line as $k => $v) {
                    if (!isset($lineProperties[$k])) {
                        $warnings[] = sprintf('Line field "%s" not in schema, dropped.', $k);
                        continue;
                    }
                    $cleanLine[$k] = $this->coerce($v, $lineProperties[$k]);
                }
                $cleanLines[] = $cleanLine;
            }
        } else {
            $cleanLines = array_values(array_filter($rawLines, 'is_array'));
        }

        return ['fields' => $cleanFields, 'lines' => $cleanLines, 'warnings' => $warnings];
    }

    /**
     * Coerce a value into the type the schema declares. Strings that look
     * like numbers / booleans get converted; anything ambiguous is returned
     * as-is so Dolibarr can reject it loudly.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function coerce(mixed $value, array $propertySchema): mixed
    {
        $type = (string) ($propertySchema['type'] ?? 'string');
        if ($value === null || $value === '') {
            return $value;
        }

        // Date / datetime fields: Dolibarr business objects do arithmetic on
        // these (`$this->date + duration * 86400`) and call `idate()` to
        // serialise them, so they MUST land on the object as Unix timestamps.
        // We accept any reasonable string representation and convert it.
        $format = (string) ($propertySchema['format'] ?? '');
        if ($type === 'string' && in_array($format, ['date', 'date-time'], true)) {
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_string($value)) {
                $ts = strtotime($value);
                if ($ts !== false) {
                    return $ts;
                }
            }
            return $value;
        }

        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => is_bool($value)
                ? $value
                : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            default => is_scalar($value) || is_array($value) ? $value : $value,
        };
    }

    /**
     * Add resolved line items to a freshly created header object.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function addLines(
        object $object,
        array $lines,
        ObjectFactory $factory,
        string $objectType,
        \DoliDB $db,
        object $user
    ): int {
        $lineDef = $factory->getLineClass($objectType);
        if ($lineDef === null) {
            throw new RuntimeException('Object ' . $objectType . ' does not support line items.');
        }
        // Expedition::addline(entrepot_id, origin_line_id, qty, ...) is not invoice-shaped;
        // line payloads must be applied via ExpeditionLigne::create().
        $useCreateLineFallback = !method_exists($object, 'addline') || $objectType === 'expedition';
        if ($useCreateLineFallback) {
            // Some classes use create() on the line object directly with `fk_<header>` set.
            // We fall back to that path for objects without `addline()`.
            $headerFk = $this->guessHeaderForeignKey($objectType);
            $count = 0;
            foreach ($lines as $line) {
                $lineObject = $factory->buildLine($objectType, $db);
                if ($headerFk !== null) {
                    $lineObject->{$headerFk} = (int) ($object->id ?? 0);
                }
                $this->assignFields($lineObject, $line);
                if (method_exists($lineObject, 'create')) {
                    $res = (int) $lineObject->create($user);
                    if ($res > 0) {
                        $count++;
                    }
                }
            }
            return $count;
        }

        $count = 0;
        foreach ($lines as $line) {
            $args = $this->mapLineToAddLineArgs($line);
            $res = (int) $object->addline(...$args);
            if ($res > 0) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Translate a Knot line payload (named keys) into the positional argument
     * list addline() expects. We only map the most common fields; anything
     * exotic falls through to a single-arg call which Dolibarr will then
     * complain about (handled by the caller's logging).
     *
     * @param array<string, mixed> $line
     * @return array<int, mixed>
     */
    private function mapLineToAddLineArgs(array $line): array
    {
        // Different Dolibarr objects (Facture / Propal / Commande / FactureFourn …)
        // expose addline() with the same 8 leading positional arguments and
        // then diverge wildly. We only pass that common prefix — Dolibarr
        // applies its own defaults to everything else, including the optional
        // `price_base_type='HT'`. Trying to push more args here corrupts the
        // call (eg. our 'HT' would land on `info_bits` for Propal).
        return [
            (string) ($line['desc'] ?? $line['description'] ?? ''),
            isset($line['pu_ht'])
                ? (float) $line['pu_ht']
                : (isset($line['subprice']) ? (float) $line['subprice'] : 0.0),
            isset($line['qty']) ? (float) $line['qty'] : 1.0,
            isset($line['txtva']) ? (float) $line['txtva'] : (isset($line['tva_tx']) ? (float) $line['tva_tx'] : 0.0),
            (float) ($line['txlocaltax1'] ?? 0),
            (float) ($line['txlocaltax2'] ?? 0),
            (int) ($line['fk_product'] ?? 0),
            (float) ($line['remise_percent'] ?? 0),
        ];
    }

    private function guessHeaderForeignKey(string $objectType): ?string
    {
        return match ($objectType) {
            'facture' => 'fk_facture',
            'propal' => 'fk_propal',
            'commande' => 'fk_commande',
            'facturefourn' => 'fk_facture_fourn',
            'commandefourn' => 'fk_commande',
            'expedition' => 'fk_expedition',
            default => null,
        };
    }

    /**
     * Verify the running user has the right Dolibarr permission for the
     * requested operation. Read-only (`fetch`) skips Knot-side gating.
     * Write operations use `x-dolibarr-permission` from SchemaBuilder, which
     * may be either `module->verb` or `module->perm1->perm2` (three-level
     * rights as in Dolibarr 21+).
     *
     * Mirrors `User::hasRight($module, $permlevel1, $permlevel2 = '')` semantics
     * and special-cases `Facture::validate()` when `MAIN_USE_ADVANCED_PERMS` is on
     * (requires `facture->invoice_advance->validate` instead of `facture->creer`).
     *
     * @param array<string, mixed> $nodeConfig Dolibarr-object node `config` slice
     */
    private function ensurePermission(
        ObjectFactory $factory,
        string $objectType,
        string $operation,
        array $nodeConfig,
        object $user,
        \DoliDB $db
    ): void {
        if ($operation === 'fetch') {
            return;
        }

        $this->hydrateDolibarrUserRights($user);

        if ($operation === 'change_status' && $objectType === 'facture') {
            $method = strtolower(trim($this->resolveStatusMethod($nodeConfig)));
            if ($method === 'validate' && $this->userPassesCustomerInvoiceValidateRights($user)) {
                return;
            }
        }

        try {
            $schema = $factory->describeForAction($objectType, $operation, $db);
        } catch (\Throwable) {
            return; // Dolibarr remains authoritative if introspection fails
        }

        $perm = $schema['x-dolibarr-permission'] ?? null;
        if (!is_string($perm) || trim($perm) === '') {
            return;
        }

        if (!method_exists($user, 'hasRight')) {
            return;
        }
        if (!empty($user->admin)) {
            return;
        }

        if ($this->dolibarrUserHasPermissionString($user, $perm)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Permission denied for %s.%s (requires Dolibarr right %s).',
            $objectType,
            $operation,
            $perm
        ));
    }

    private function hydrateDolibarrUserRights(object $user): void
    {
        if (($user->id ?? 0) <= 0) {
            return;
        }
        if (!method_exists($user, 'loadRights')) {
            return;
        }
        if (
            property_exists($user, 'all_permissions_are_loaded')
            && !empty($user->all_permissions_are_loaded)
        ) {
            return;
        }

        /** @phan-suppress-next-line PhanUndeclaredMethod */
        $user->loadRights();
    }

    private function userPassesCustomerInvoiceValidateRights(object $user): bool
    {
        if (!method_exists($user, 'hasRight')) {
            return false;
        }
        // Facture::validate() (Dolibarr 21): see compta/facture/class/facture.class.php
        if (!$this->isDolibarrAdvancedPermsEnabled()) {
            return (bool) $user->hasRight('facture', 'creer');
        }

        return (bool) $user->hasRight('facture', 'invoice_advance', 'validate');
    }

    /**
     * True when Dolibarr "advanced permissions" toggle is enabled (supplier/customer invoice validate paths).
     */
    private function isDolibarrAdvancedPermsEnabled(): bool
    {
        if (function_exists('getDolGlobalInt')) {
            return (int) getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') !== 0;
        }
        if (function_exists('getDolGlobalString')) {
            $raw = strtolower(trim((string) getDolGlobalString('MAIN_USE_ADVANCED_PERMS')));

            return in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param non-empty-string $permission Human-readable `module->perm[->subperm]` as emitted in schemas
     */
    private function dolibarrUserHasPermissionString(object $user, string $permission): bool
    {
        if (!method_exists($user, 'hasRight')) {
            return false;
        }

        $segments = preg_split('/\s*->\s*/', $permission, limit: -1, flags: PREG_SPLIT_NO_EMPTY);
        /** @var list<string> $segments */
        $segments = array_values(array_map(static fn (string $s): string => trim($s), $segments));
        if ($segments === []) {
            return false;
        }

        if (count($segments) >= 4) {
            return false;
        }

        return $this->invokeDolibarrHasRightSegments($user, $segments)
            || $this->invokeDolibarrHasRightSynonyms($user, $segments);
    }

    /**
     * @param list<string> $segments
     */
    private function invokeDolibarrHasRightSegments(object $user, array $segments): bool
    {
        if (count($segments) === 3) {
            return (bool) $user->hasRight($segments[0], $segments[1], $segments[2]);
        }

        if (count($segments) === 2) {
            return (bool) $user->hasRight($segments[0], $segments[1]);
        }

        return false;
    }

    /**
     * English read/write/delete aliases used in newer Dolibarr screens and APIs.
     *
     * @param list<string> $segments
     */
    private function invokeDolibarrHasRightSynonyms(object $user, array $segments): bool
    {
        if (count($segments) !== 2) {
            return false;
        }

        [$module, $verb] = $segments;
        $map = [
            'lire' => ['read'],
            'creer' => ['write', 'create'],
            'supprimer' => ['delete'],
        ];

        foreach ($map[$verb] ?? [] as $alias) {
            if ((bool) $user->hasRight($module, $alias)) {
                return true;
            }
        }

        return false;
    }
}
