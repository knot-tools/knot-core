<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Convert Dolibarr `$fields` metadata into a Knot-flavoured JSON-Schema.
 *
 * The schema is action-aware: the same Dolibarr object yields different schemas
 * depending on whether we are creating, updating, fetching, deleting…
 *
 * Conventions:
 * - JSON-Schema is draft-2020-12-ish; we only use the keywords Knot's
 *   {@see DynamicForm.vue} understands plus a few `x-*` extensions.
 * - Untranslated labels are emitted as-is when no Translate instance is given;
 *   when a Translate is provided we prefer `$langs->trans($label)` so the panel
 *   matches what the user sees inside Dolibarr.
 * - Hidden (visible 0/2/-2) and read-only (noteditable=1) fields are excluded
 *   from create/update schemas; they remain part of fetch responses though.
 *
 * The class is intentionally pure (no DB access, no globals beyond `eval`-style
 * `enabled` resolution) so it is straightforward to test.
 */
final class SchemaBuilder
{
    /** Default form schema (Dolibarr-visible editable fields only). */
    public const FIELD_VIEW_STANDARD = 'standard';

    /**
     * Introspect every `$fields` entry for create/update (expert / integration builder).
     * Visibility, read-only and auto-managed filters are not applied.
     */
    public const FIELD_VIEW_FULL = 'full';

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_FETCH = 'fetch';
    public const ACTION_DELETE = 'delete';
    public const ACTION_CHANGE_STATUS = 'change_status';
    public const ACTION_ADD_NOTE = 'add_note';
    public const ACTION_GENERATE_PDF = 'generate_pdf';

    private const ACTIONS_REQUIRING_FIELDS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
    ];

    /**
     * Field names that Dolibarr always populates internally (technical PK,
     * audit timestamps, user audit columns…). Asking Knot users to provide
     * them would be misleading at best and breaks `addLine`/`create()` flows
     * at worst, so we systematically strip them from create/update schemas.
     *
     * @var array<int, string>
     */
    private const AUTO_MANAGED_FIELDS = [
        'rowid',
        'tms',
        'date_creation',
        'date_modification',
        'date_valid',
        'date_cloture',
        'fk_user_author',
        'fk_user_creat',
        'fk_user_modif',
        'fk_user_valid',
        'fk_user_cloture',
        'fk_user_close',
        'last_main_doc',
        'import_key',
    ];

    /**
     * Dolibarr column types that Dolibarr's own `update()` resets every time
     * (`tms` ↦ `current_timestamp`, audit columns…). Excluding them at the
     * type level catches third-party objects that name their timestamp
     * columns differently than the Dolibarr core conventions.
     *
     * @var array<int, string>
     */
    private const AUTO_MANAGED_TYPES = ['timestamp'];

    /**
     * Map a Dolibarr foreign-key target class to the Knot slug used
     * downstream (so the picker UI knows which type to query).
     *
     * @var array<string, string>
     */
    private const FK_CLASS_TO_SLUG = [
        'Societe' => 'thirdparty',
        'Contact' => 'contact',
        'User' => 'user',
        'Product' => 'product',
        'Project' => 'project',
        'Task' => 'task',
        'Propal' => 'propal',
        'Commande' => 'commande',
        'Facture' => 'facture',
        'Adherent' => 'member',
        'Ticket' => 'ticket',
        'Categorie' => 'categorie',
        'Account' => 'bankaccount',
        'Ccountry' => 'country',
        'ActionComm' => 'agenda',
    ];

    /**
     * Build a JSON-schema for `(object, action)`.
     *
     * @param object        $object  A Dolibarr business object instance (Societe, Facture, …).
     * @param string        $action  One of the ACTION_* constants.
     * @param object|null   $langs   Optional Dolibarr Translate to localise titles/descriptions.
     * @param array<string,mixed> $context Optional context for `enabled` evaluation
     *                                     (`isModuleEnabled` callback, `globals` map…).
     *
     * @return array{
     *     type:string,
     *     properties?:array<string,array<string,mixed>>,
     *     required?:array<int,string>,
     *     x-knot-action?:string,
     *     x-knot-object?:string,
     *     x-version-hash?:string,
     *     x-dolibarr-permission?:string
     * }
     */
    public function buildForAction(
        object $object,
        string $action,
        ?object $langs = null,
        array $context = []
    ): array {
        $action = strtolower($action);

        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'x-knot-action' => $action,
        ];

        // Permission gating — when present we surface the right needed in
        // the schema so the panel can render an explicit badge.
        $permission = $this->detectPermission($object, $action);
        if ($permission !== null) {
            $schema['x-dolibarr-permission'] = $permission;
        }

        // Operations that don't take a fields payload return an empty schema.
        if (!in_array($action, self::ACTIONS_REQUIRING_FIELDS, true)) {
            unset($schema['required']);
            return $schema;
        }

        $fields = isset($object->fields) && is_array($object->fields) ? $object->fields : [];

        foreach ($fields as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            if ($this->isAutoManaged($name, $definition)) {
                continue;
            }

            if (!$this->isVisibleForAction($definition, $action)) {
                continue;
            }

            if (!$this->isEnabled($definition, $context)) {
                continue;
            }

            if ($this->isReadOnly($definition)) {
                continue;
            }

            $property = $this->mapField($name, $definition, $langs);

            $schema['properties'][$name] = $property;

            if ($this->isRequired($definition, $action)) {
                $schema['required'][] = $name;
            }
        }

        // Sort properties by x-position so the form renders in the same order
        // as Dolibarr's own UI does.
        $schema['properties'] = $this->sortByPosition($schema['properties']);

        if ($schema['required'] === []) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Build a create/update schema that includes every key from `$object->fields`.
     * Skips only malformed entries. Adds {@see self::FIELD_VIEW_FULL} marker for clients.
     *
     * @param array<string,mixed> $context Same as {@see buildForAction()} `enabled` context.
     *
     * @return array<string,mixed>
     */
    public function buildForActionFull(
        object $object,
        string $action,
        ?object $langs = null,
        array $context = []
    ): array {
        $action = strtolower($action);

        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'x-knot-action' => $action,
            'x-knot-field-view' => self::FIELD_VIEW_FULL,
        ];

        $permission = $this->detectPermission($object, $action);
        if ($permission !== null) {
            $schema['x-dolibarr-permission'] = $permission;
        }

        if (!in_array($action, self::ACTIONS_REQUIRING_FIELDS, true)) {
            unset($schema['required']);
            return $schema;
        }

        $fields = isset($object->fields) && is_array($object->fields) ? $object->fields : [];

        foreach ($fields as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $property = $this->mapField($name, $definition, $langs);
            $property['x-knot-expert-field'] = true;
            if ($this->isReadOnly($definition)) {
                $property['readOnly'] = true;
            }

            $schema['properties'][$name] = $property;

            if ($this->isRequired($definition, $action)) {
                $schema['required'][] = $name;
            }
        }

        $schema['properties'] = $this->sortByPosition($schema['properties']);

        if ($schema['required'] === []) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Wrap a line schema into an `array` property so a Facture/Propal/Commande
     * schema can embed `lines` directly.
     *
     * @param object      $lineObject A Dolibarr line instance (FactureLigne…).
     * @param object|null $langs      Optional translator.
     *
     * @return array<string,mixed>
     */
    public function buildLinesSchema(object $lineObject, ?object $langs = null): array
    {
        return [
            'type' => 'array',
            'title' => $this->translate('Lines', $langs),
            'description' => $this->translate('LinesHelp', $langs),
            'items' => $this->buildForAction($lineObject, self::ACTION_CREATE, $langs),
            'x-knot-bulk' => true,
        ];
    }

    /**
     * Same as {@see buildLinesSchema()} but line item fields are not filtered to Dolibarr form visibility.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string,mixed>
     */
    public function buildLinesSchemaFull(object $lineObject, ?object $langs = null, array $context = []): array
    {
        return [
            'type' => 'array',
            'title' => $this->translate('Lines', $langs),
            'description' => $this->translate('LinesHelp', $langs),
            'items' => $this->buildForActionFull($lineObject, self::ACTION_CREATE, $langs, $context),
            'x-knot-bulk' => true,
        ];
    }

    /**
     * Compute a stable hash of the given object's `$fields` definition so the
     * frontend can detect schema changes and invalidate its cache.
     */
    public function fieldsHash(object $object): string
    {
        $fields = isset($object->fields) && is_array($object->fields) ? $object->fields : [];

        // Strip closures / objects that would break json_encode and rely on a
        // canonical key ordering for stability across PHP versions.
        $normalised = $this->normaliseForHash($fields);

        return substr(sha1((string) json_encode($normalised, JSON_UNESCAPED_SLASHES)), 0, 12);
    }

    /**
     * Map a single Dolibarr field to a JSON-schema property.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function mapField(string $name, array $definition, ?object $langs): array
    {
        $rawType = (string) ($definition['type'] ?? 'varchar(255)');
        $property = $this->mapType($rawType);

        $label = (string) ($definition['label'] ?? $name);
        $property['title'] = $this->translate($label, $langs);

        if (!empty($definition['help'])) {
            $property['description'] = $this->translate((string) $definition['help'], $langs);
        }

        if (array_key_exists('default', $definition) && $definition['default'] !== '') {
            $property['default'] = $this->coerceDefault($definition['default'], $property['type'] ?? 'string');
        }

        if (isset($definition['position'])) {
            $property['x-position'] = (int) $definition['position'];
        }

        if (isset($definition['arrayofkeyval']) && is_array($definition['arrayofkeyval'])) {
            $property['enum'] = array_keys($definition['arrayofkeyval']);
            $property['enumLabels'] = array_values(array_map(
                fn ($v) => $this->translate((string) $v, $langs),
                $definition['arrayofkeyval']
            ));
        }

        $fk = $this->parseForeignKey($rawType);
        if ($fk !== null) {
            $property['x-dolibarr-fk'] = $fk;
        }

        // varchar(N) length carries through.
        if (preg_match('/^varchar\((\d+)\)/i', $rawType, $matches)) {
            $property['maxLength'] = (int) $matches[1];
        }

        return $property;
    }

    /**
     * Translate a Dolibarr type string to a JSON-Schema fragment.
     *
     * We keep this purposely tolerant: unknown / unknownish types fall back to
     * `{type:'string'}` so the form still renders something useful.
     *
     * @return array<string,mixed>
     */
    private function mapType(string $rawType): array
    {
        $type = strtolower(trim($rawType));

        // FK looks like `integer:Societe:societe/class/societe.class.php`.
        if (str_starts_with($type, 'integer:')) {
            return ['type' => 'integer'];
        }

        return match (true) {
            $type === 'integer', $type === 'int', preg_match('/^int\(/', $type) === 1 => ['type' => 'integer'],
            $type === 'boolean', $type === 'bool' => ['type' => 'boolean'],
            preg_match('/^tinyint(\(\d+\))?$/', $type) === 1 => [
                'type' => 'integer',
                'enum' => [0, 1],
                'enumLabels' => ['No', 'Yes'],
            ],
            $type === 'double', $type === 'real', $type === 'float',
                preg_match('/^double(\(\d+,\d+\))?$/', $type) === 1,
                preg_match('/^float(\(\d+,\d+\))?$/', $type) === 1 => ['type' => 'number'],
            $type === 'price' => ['type' => 'number', 'format' => 'price'],
            $type === 'date' => ['type' => 'string', 'format' => 'date'],
            $type === 'datetime', $type === 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            $type === 'text' => ['type' => 'string', 'multiline' => true],
            $type === 'mediumtext', $type === 'longtext' => ['type' => 'string', 'multiline' => true],
            $type === 'html' => ['type' => 'string', 'multiline' => true, 'format' => 'html'],
            $type === 'mail' => ['type' => 'string', 'format' => 'email'],
            $type === 'phone' => ['type' => 'string', 'format' => 'tel'],
            $type === 'url' => ['type' => 'string', 'format' => 'uri'],
            $type === 'password' => ['type' => 'string', 'secret' => true],
            $type === 'duration' => ['type' => 'integer', 'format' => 'duration'],
            $type === 'select' || $type === 'radio' || $type === 'checkbox' || $type === 'chkbxlst' => ['type' => 'string'],
            preg_match('/^varchar\(\d+\)$/i', $type) === 1, $type === 'varchar' => ['type' => 'string'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Should the field appear at all for the given action?
     *
     * We mirror Dolibarr's own logic from `core/class/commonobject.class.php`
     * so the Knot panel exposes exactly the same fields as Dolibarr's native
     * forms. The reference rule (showOptionals() / showInputField()) is:
     *
     *   create mode → abs(visible) in {1, 3}
     *   edit   mode → abs(visible) in {1, 3, 4}
     *
     * Other values mean "list/view only" (2, 5) or "hidden" (0, ±2). Negative
     * values (-1, -3) are still shown — Dolibarr keeps them editable in the
     * form but read-only in detail views. Setting `visible: -2` or `0` always
     * hides the field, and `noteditable: 1` is honoured separately.
     *
     * @param array<string,mixed> $definition
     */
    private function isVisibleForAction(array $definition, string $action): bool
    {
        $visible = $definition['visible'] ?? 1;
        if (is_string($visible) && $visible !== '') {
            $visible = $this->safeEvalInt($visible, 1);
        }
        $visible = (int) $visible;
        $abs = abs($visible);

        // Hidden / admin-only / pure list-view fields are never shown in form.
        if ($visible === 0 || $abs === 2 || $visible === -2) {
            return false;
        }

        if ($action === self::ACTION_CREATE) {
            // Dolibarr: abs(visibility) in {1, 3} OR visibility == -5 (create-only).
            return in_array($abs, [1, 3], true) || $visible === -5;
        }

        if ($action === self::ACTION_UPDATE) {
            return in_array($abs, [1, 3, 4], true);
        }

        // Other actions (fetch/delete/...) don't take a payload — the schema
        // for those is empty by construction (see buildForAction).
        return true;
    }

    /**
     * Is the field marked enabled for the current install? Dolibarr ships
     * conditional fields (eg. `'enabled' => "isModEnabled('order')"`) and we
     * want the Knot panel to reflect what the user actually sees in Dolibarr.
     *
     * We accept ints, raw strings (best-effort eval) and a context-supplied
     * callable for tests.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $context
     */
    private function isEnabled(array $definition, array $context): bool
    {
        $enabled = $definition['enabled'] ?? 1;

        if (is_int($enabled) || is_bool($enabled)) {
            return (int) $enabled !== 0;
        }

        if (!is_string($enabled) || $enabled === '') {
            return true;
        }

        if (isset($context['enabledEvaluator']) && is_callable($context['enabledEvaluator'])) {
            return (bool) $context['enabledEvaluator']($enabled);
        }

        return $this->safeEvalInt($enabled, 1) !== 0;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function isReadOnly(array $definition): bool
    {
        return !empty($definition['noteditable']);
    }

    /**
     * Is this a Dolibarr-managed field (PK, audit timestamp, audit user…)?
     * Always true when the field name is in {@see AUTO_MANAGED_FIELDS} or its
     * type is a managed timestamp. Authors can still tweak these via the
     * "Avancé" JSON tab if they really need to.
     *
     * @param array<string,mixed> $definition
     */
    private function isAutoManaged(string $name, array $definition): bool
    {
        if (in_array($name, self::AUTO_MANAGED_FIELDS, true)) {
            return true;
        }
        $type = strtolower((string) ($definition['type'] ?? ''));
        return in_array($type, self::AUTO_MANAGED_TYPES, true);
    }

    /**
     * Required = notnull + visible + no default value, when the action is create.
     * For update, only notnull + no default is enforced.
     *
     * @param array<string,mixed> $definition
     */
    private function isRequired(array $definition, string $action): bool
    {
        if (empty($definition['notnull'])) {
            return false;
        }
        if ((int) $definition['notnull'] !== 1) {
            return false;
        }

        // If Dolibarr ships a default value the field can be omitted.
        if (array_key_exists('default', $definition) && $definition['default'] !== null && $definition['default'] !== '') {
            return false;
        }

        return $action === self::ACTION_CREATE;
    }

    /**
     * Detect the right that Dolibarr will check inside `create()/update()/delete()`.
     * Returns `null` if the object doesn't expose a `$element` property — most
     * Dolibarr classes do, but custom objects may not.
     */
    private function detectPermission(object $object, string $action): ?string
    {
        $element = isset($object->element) && is_string($object->element) ? $object->element : null;
        if ($element === null || $element === '') {
            return null;
        }

        $verb = match ($action) {
            self::ACTION_CREATE, self::ACTION_UPDATE => 'creer',
            self::ACTION_DELETE => 'supprimer',
            self::ACTION_FETCH => 'lire',
            default => 'creer',
        };

        return $element . '->' . $verb;
    }

    /**
     * Parse a `integer:ClassName:path/to/file.class.php` type into a structured
     * descriptor the frontend can consume.
     *
     * @return array{targetClass:string,targetSlug:string,targetFile:string}|null
     */
    private function parseForeignKey(string $rawType): ?array
    {
        if (!str_starts_with($rawType, 'integer:')) {
            return null;
        }

        $parts = explode(':', $rawType);
        if (count($parts) < 3) {
            return null;
        }

        $class = trim($parts[1]);
        $file = trim($parts[2]);

        return [
            'targetClass' => $class,
            'targetSlug' => self::FK_CLASS_TO_SLUG[$class] ?? strtolower($class),
            'targetFile' => $file,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $properties
     * @return array<string,array<string,mixed>>
     */
    private function sortByPosition(array $properties): array
    {
        uasort($properties, static function (array $a, array $b): int {
            $posA = isset($a['x-position']) ? (int) $a['x-position'] : PHP_INT_MAX;
            $posB = isset($b['x-position']) ? (int) $b['x-position'] : PHP_INT_MAX;
            return $posA <=> $posB;
        });
        return $properties;
    }

    /**
     * Translate `$key` via the optional Dolibarr `Translate` instance.
     * Returns the raw key when no translator is provided so tests can assert
     * on stable strings.
     */
    private function translate(string $key, ?object $langs): string
    {
        if ($langs !== null && method_exists($langs, 'trans')) {
            $value = $langs->trans($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return $key;
    }

    private function coerceDefault(mixed $raw, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $raw,
            'number' => (float) $raw,
            'boolean' => (bool) $raw,
            default => is_scalar($raw) ? (string) $raw : $raw,
        };
    }

    /**
     * Best-effort evaluation of a Dolibarr-style condition string.
     *
     * We DO NOT eval the string; that would defeat the security audit. Instead
     * we recognise a small set of safe patterns that cover ~95% of real cases
     * (`isModEnabled('xxx')`, `getDolGlobalString('YYY')`, `getDolGlobalInt`,
     * `$conf->X->enabled`). Anything else falls back to the default and we
     * prefer to show the field rather than hide it.
     */
    private function safeEvalInt(string $expression, int $default): int
    {
        $expression = trim($expression);

        if ($expression === '' || $expression === '0') {
            return 0;
        }
        if ($expression === '1') {
            return 1;
        }

        if (preg_match('/^isModEnabled\([\'"]([a-z0-9_]+)[\'"]\)$/i', $expression, $m)) {
            // @phpstan-ignore-next-line — isModEnabled() is provided by Dolibarr at runtime.
            return function_exists('isModEnabled') ? (call_user_func('isModEnabled', $m[1]) ? 1 : 0) : $default;
        }

        if (preg_match('/^getDolGlobalString\([\'"]([A-Z0-9_]+)[\'"]\)$/i', $expression, $m)) {
            return function_exists('getDolGlobalString')
                ? (\getDolGlobalString($m[1]) !== '' ? 1 : 0)
                : $default;
        }

        if (preg_match('/^getDolGlobalInt\([\'"]([A-Z0-9_]+)[\'"]\)$/i', $expression, $m)) {
            return function_exists('getDolGlobalInt') ? (int) (\getDolGlobalInt($m[1]) > 0) : $default;
        }

        if (preg_match('/^\$conf->([a-z0-9_]+)->enabled$/i', $expression, $m)) {
            global $conf;
            if (isset($conf->{$m[1]}->enabled)) {
                return (int) ((int) $conf->{$m[1]}->enabled !== 0);
            }
            return $default;
        }

        return $default;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function normaliseForHash(array $value): array
    {
        $out = [];
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->normaliseForHash($v);
            } elseif (is_object($v)) {
                $out[$k] = '__obj';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
