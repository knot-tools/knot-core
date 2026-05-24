<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

use RuntimeException;

/**
 * Builds Dolibarr business objects from stable Knot slugs.
 *
 * Beyond instantiation this class is also the single source of truth for the
 * `(slug → class, line class, label, module, supports lines)` lookup that
 * powers the Knot frontend. The {@see SchemaBuilder} consumes objects we hand
 * back here and never touches the MAP directly so V2.4's introspector can swap
 * the static MAP for a discovered registry without breaking callers.
 */
final class ObjectFactory
{
    /**
     * @var array<string, array{
     *     file: string,
     *     class: string,
     *     module?: string,
     *     label?: string,
     *     line?: array{file: string, class: string},
     *     rights?: string
     * }>
     */
    private const MAP = [
        'thirdparty' => [
            'file' => '/societe/class/societe.class.php',
            'class' => 'Societe',
            'module' => 'societe',
            'label' => 'ThirdParty',
        ],
        'contact' => [
            'file' => '/contact/class/contact.class.php',
            'class' => 'Contact',
            'module' => 'societe',
            'label' => 'Contact',
        ],
        'propal' => [
            'file' => '/comm/propal/class/propal.class.php',
            'class' => 'Propal',
            'module' => 'propal',
            'label' => 'Proposal',
            'line' => ['file' => '/comm/propal/class/propal.class.php', 'class' => 'PropaleLigne'],
        ],
        'commande' => [
            'file' => '/commande/class/commande.class.php',
            'class' => 'Commande',
            'module' => 'commande',
            'label' => 'Order',
            'line' => ['file' => '/commande/class/commande.class.php', 'class' => 'OrderLine'],
        ],
        'facture' => [
            'file' => '/compta/facture/class/facture.class.php',
            'class' => 'Facture',
            'module' => 'facture',
            'label' => 'Bill',
            'line' => ['file' => '/compta/facture/class/facture.class.php', 'class' => 'FactureLigne'],
        ],
        'product' => [
            'file' => '/product/class/product.class.php',
            'class' => 'Product',
            'module' => 'product',
            'label' => 'Product',
        ],
        'service' => [
            'file' => '/product/class/product.class.php',
            'class' => 'Product',
            'module' => 'service',
            'label' => 'Service',
        ],
        'project' => [
            'file' => '/projet/class/project.class.php',
            'class' => 'Project',
            'module' => 'projet',
            'label' => 'Project',
        ],
        'task' => [
            'file' => '/projet/class/task.class.php',
            'class' => 'Task',
            'module' => 'projet',
            'label' => 'Task',
        ],
        'ticket' => [
            'file' => '/ticket/class/ticket.class.php',
            'class' => 'Ticket',
            'module' => 'ticket',
            'label' => 'Ticket',
        ],
        'user' => ['file' => '/user/class/user.class.php', 'class' => 'User', 'module' => 'user', 'label' => 'User'],
        'member' => [
            'file' => '/adherents/class/adherent.class.php',
            'class' => 'Adherent',
            'module' => 'adherent',
            'label' => 'Member',
        ],
        'entrepot' => [
            'file' => '/product/stock/class/entrepot.class.php',
            'class' => 'Entrepot',
            'module' => 'stock',
            'label' => 'Warehouse',
        ],
        'stockmove' => [
            'file' => '/product/stock/class/mouvementstock.class.php',
            'class' => 'MouvementStock',
            'module' => 'stock',
            'label' => 'StockMovement',
        ],
        'agenda' => [
            'file' => '/comm/action/class/actioncomm.class.php',
            'class' => 'ActionComm',
            'module' => 'agenda',
            'label' => 'Agenda',
        ],
        'actioncomm' => [
            'file' => '/comm/action/class/actioncomm.class.php',
            'class' => 'ActionComm',
            'module' => 'agenda',
            'label' => 'Action',
        ],
        'categorie' => [
            'file' => '/categories/class/categorie.class.php',
            'class' => 'Categorie',
            'module' => 'categorie',
            'label' => 'Category',
        ],
        'bankaccount' => [
            'file' => '/compta/bank/class/account.class.php',
            'class' => 'Account',
            'module' => 'banque',
            'label' => 'BankAccount',
        ],
        'expense' => [
            'file' => '/expensereport/class/expensereport.class.php',
            'class' => 'ExpenseReport',
            'module' => 'expensereport',
            'label' => 'ExpenseReport',
        ],
        'expedition' => [
            'file' => '/expedition/class/expedition.class.php',
            'class' => 'Expedition',
            'module' => 'expedition',
            'label' => 'Shipment',
            'line' => [
                'file' => '/expedition/class/expeditionligne.class.php',
                'class' => 'ExpeditionLigne',
            ],
        ],
        'holiday' => [
            'file' => '/holiday/class/holiday.class.php',
            'class' => 'Holiday',
            'module' => 'holiday',
            'label' => 'Leave',
        ],
        'mailing' => [
            'file' => '/comm/mailing/class/mailing.class.php',
            'class' => 'Mailing',
            'module' => 'mailing',
            'label' => 'Mailing',
        ],
        'facturefourn' => [
            'file' => '/fourn/class/fournisseur.facture.class.php',
            'class' => 'FactureFournisseur',
            'module' => 'fournisseur',
            'label' => 'SupplierInvoice',
            'line' => ['file' => '/fourn/class/fournisseur.facture.class.php', 'class' => 'SupplierInvoiceLine'],
        ],
        'commandefourn' => [
            'file' => '/fourn/class/fournisseur.commande.class.php',
            'class' => 'CommandeFournisseur',
            'module' => 'fournisseur',
            'label' => 'SupplierOrder',
            'line' => ['file' => '/fourn/class/fournisseur.commande.class.php', 'class' => 'CommandeFournisseurLigne'],
        ],
        'propalfourn' => [
            'file' => '/supplier_proposal/class/supplier_proposal.class.php',
            'class' => 'SupplierProposal',
            'module' => 'supplier_proposal',
            'label' => 'SupplierProposal',
            'line' => [
                'file' => '/supplier_proposal/class/supplier_proposal.class.php',
                'class' => 'SupplierProposalLine',
            ],
        ],
        'contrat' => [
            'file' => '/contrat/class/contrat.class.php',
            'class' => 'Contrat',
            'module' => 'contrat',
            'label' => 'Contract',
            'line' => ['file' => '/contrat/class/contratligne.class.php', 'class' => 'ContratLigne'],
        ],
        // Customer/supplier invoice payment (Dolibarr core): supports PB-class stats and future create flows.
        'paiement' => [
            'file' => '/compta/paiement/class/paiement.class.php',
            'class' => 'Paiement',
            'module' => 'banque',
            'label' => 'Payment',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $schemaCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $describeCache = [];

    /** @var array<string, array<string, mixed>>|null Lazy lookup for discovered slugs */
    private ?array $discoveredCache = null;

    public function build(string $slug, \DoliDB $db): object
    {
        $slug = $this->normaliseSlug($slug);
        $def = self::MAP[$slug] ?? null;
        if ($def === null) {
            $def = $this->lookupDiscovered($slug);
            if ($def === null) {
                throw new RuntimeException('Unsupported Dolibarr object type: ' . $slug);
            }
        }

        \dol_include_once($def['file']);
        $class = '\\' . $def['class'];
        if (!class_exists($class)) {
            throw new RuntimeException('Dolibarr class not found: ' . $def['class']);
        }

        return new $class($db);
    }

    /**
     * Fully-qualified class name (leading backslash) after loading the Dolibarr include.
     *
     * @throws \RuntimeException
     */
    public function fqcnForSlug(string $slug, \DoliDB $db): string
    {
        $slug = $this->normaliseSlug($slug);
        $def = self::MAP[$slug] ?? null;
        if ($def === null) {
            $def = $this->lookupDiscovered($slug);
            if ($def === null) {
                throw new RuntimeException('Unsupported Dolibarr object type: ' . $slug);
            }
        }

        \dol_include_once($def['file']);

        return '\\' . $def['class'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupDiscovered(string $slug): ?array
    {
        $discovered = $this->discoveredDescriptors();
        return $discovered[$slug] ?? null;
    }

    /**
     * Return all introspector-discovered descriptors keyed by slug.
     * Falls back to the cache file when the in-process map is empty.
     *
     * @return array<string, array<string, mixed>>
     */
    public function discoveredDescriptors(): array
    {
        if ($this->discoveredCache !== null) {
            return $this->discoveredCache;
        }
        $cache = new DescriptorCache();
        $payload = $cache->read();
        if ($payload === null) {
            return $this->discoveredCache = [];
        }
        $map = [];
        foreach ($payload['descriptors'] as $entry) {
            $slug = (string) ($entry['slug'] ?? '');
            if ($slug === '' || isset(self::MAP[$slug])) {
                continue;
            }
            $map[$slug] = [
                'file' => $entry['file'],
                'class' => $entry['class'],
                'module' => $entry['module'] ?? '',
                'label' => $entry['class'],
                'source' => $entry['source'] ?? 'core',
                'supportsLines' => (bool) ($entry['supportsLines'] ?? false),
            ];
        }
        return $this->discoveredCache = $map;
    }

    /**
     * Force a re-scan via ObjectIntrospector and rewrite the cache.
     * Returns the descriptor list that was just written.
     *
     * @return array{
     *     hash: string,
     *     descriptors: array<int, array<string, mixed>>,
     *     count: int
     * }
     */
    public function refreshIntrospection(\DoliDB $db, ?string $documentRoot = null): array
    {
        $root = $documentRoot
            ?? (defined('DOL_DOCUMENT_ROOT') ? (string) constant('DOL_DOCUMENT_ROOT') : '');
        $introspector = new ObjectIntrospector($root);
        $cache = new DescriptorCache();
        $descriptors = $introspector->scan();
        $hash = DescriptorCache::hashForRoots($root);
        $cache->write($descriptors, $hash);
        $this->discoveredCache = null; // force reload on next access
        $this->describeCache = [];
        $this->schemaCache = [];
        return [
            'hash' => $hash,
            'descriptors' => $descriptors,
            'count' => count($descriptors),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function listSupported(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(string $slug, \DoliDB $db): array
    {
        $slug = $this->normaliseSlug($slug);
        if (isset($this->schemaCache[$slug])) {
            return $this->schemaCache[$slug];
        }

        $object = $this->build($slug, $db);
        $fields = [];
        if (isset($object->fields) && is_array($object->fields)) {
            foreach ($object->fields as $name => $definition) {
                $fields[(string) $name] = is_array($definition) ? $definition : ['type' => 'unknown'];
            }
        }

        $classShort = ($p = strrpos($fqn = get_class($object), '\\')) !== false
            ? substr($fqn, $p + 1)
            : $fqn;

        return $this->schemaCache[$slug] = [
            'slug' => $slug,
            'class' => $classShort,
            'fields' => $fields,
        ];
    }

    /**
     * Describe an object for a given Knot action and return the JSON-schema
     * the frontend should render. The result embeds an `x-version-hash` so
     * the client can detect schema drift across deploys.
     *
     * @return array<string, mixed>
     */
    public function describeForAction(
        string $slug,
        string $action,
        \DoliDB $db,
        ?object $langs = null,
        string $fieldView = SchemaBuilder::FIELD_VIEW_STANDARD
    ): array {
        $slug = $this->normaliseSlug($slug);
        $fieldView = $fieldView === SchemaBuilder::FIELD_VIEW_FULL
            ? SchemaBuilder::FIELD_VIEW_FULL
            : SchemaBuilder::FIELD_VIEW_STANDARD;
        $cacheKey = $slug . ':' . strtolower($action) . ':' . $fieldView;
        if (isset($this->describeCache[$cacheKey])) {
            return $this->describeCache[$cacheKey];
        }

        $object = $this->build($slug, $db);
        $builder = new SchemaBuilder();
        $schema = $fieldView === SchemaBuilder::FIELD_VIEW_FULL
            ? $builder->buildForActionFull($object, $action, $langs)
            : $builder->buildForAction($object, $action, $langs);

        $schema['x-knot-object'] = $slug;
        $baseHash = $builder->fieldsHash($object);
        $schema['x-version-hash'] = $fieldView === SchemaBuilder::FIELD_VIEW_FULL
            ? substr(sha1($baseHash . ':full'), 0, 12)
            : $baseHash;

        if (in_array($action, [SchemaBuilder::ACTION_CREATE, SchemaBuilder::ACTION_UPDATE], true)) {
            $lineDef = self::MAP[$slug]['line'] ?? null;
            if ($lineDef !== null) {
                try {
                    $line = $this->buildLine($slug, $db);
                    $schema['properties']['lines'] = $fieldView === SchemaBuilder::FIELD_VIEW_FULL
                        ? $builder->buildLinesSchemaFull($line, $langs)
                        : $builder->buildLinesSchema($line, $langs);
                } catch (\Throwable $e) {
                    // Lines class might not be loadable in some installs (rare module
                    // disabled), in that case we surface the limitation in the schema.
                    $schema['properties']['lines'] = [
                        'type' => 'array',
                        'title' => 'Lines',
                        'description' => 'Could not introspect line schema: ' . $e->getMessage(),
                        'items' => ['type' => 'object'],
                        'x-knot-bulk' => true,
                        'x-knot-error' => true,
                    ];
                }
            }

            if (defined('MAIN_DB_PREFIX') && isset($GLOBALS['conf']) && is_object($GLOBALS['conf'])) {
                $conf = $GLOBALS['conf'];
                $entity = (int) ($conf->entity ?? 1);
                $extras = new ExtrafieldsSchema();
                $schema = $extras->mergeInto($schema, $db, $object, $entity, $langs);
            }
        }

        return $this->describeCache[$cacheKey] = $schema;
    }

    /**
     * List every object the factory knows about, in a shape suitable for the
     * `api/dolibarr_schemas.php?list=1` payload.
     *
     * @return array<int, array{
     *     slug:string,
     *     class:string,
     *     module:string,
     *     label:string,
     *     supportsLines:bool,
     *     element:?string,
     *     source:string,
     *     fromMap:bool
     * }>
     */
    public function listObjectsForApi(?object $langs = null, ?\DoliDB $db = null): array
    {
        $items = [];
        $seen = [];
        foreach (self::MAP as $slug => $def) {
            $seen[$slug] = true;
            $element = null;
            if ($db !== null) {
                try {
                    $object = $this->build($slug, $db);
                    $element = isset($object->element) && is_string($object->element) ? $object->element : null;
                } catch (\Throwable) {
                    $element = null;
                }
            }

            $items[] = [
                'slug' => $slug,
                'class' => $def['class'],
                'module' => $def['module'],
                'label' => $this->translate($def['label'], $langs),
                'supportsLines' => isset($def['line']),
                'element' => $element,
                'source' => 'core',
                'fromMap' => true,
            ];
        }

        foreach ($this->discoveredDescriptors() as $slug => $def) {
            if (isset($seen[$slug])) {
                continue;
            }
            $items[] = [
                'slug' => $slug,
                'class' => $def['class'],
                'module' => (string) ($def['module'] ?? ''),
                'label' => $this->translate((string) ($def['label'] ?? $def['class']), $langs),
                'supportsLines' => (bool) ($def['supportsLines'] ?? false),
                'element' => null,
                'source' => (string) ($def['source'] ?? 'core'),
                'fromMap' => false,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        return $items;
    }

    /**
     * Return a stable hash of every supported object's `$fields`. Used by the
     * frontend to invalidate its local cache whenever any schema has shifted.
     */
    public function getVersionHash(\DoliDB $db): string
    {
        $builder = new SchemaBuilder();
        $accum = '';
        foreach (array_keys(self::MAP) as $slug) {
            try {
                $object = $this->build($slug, $db);
                $accum .= $slug . ':' . $builder->fieldsHash($object) . '|';
            } catch (\Throwable) {
                $accum .= $slug . ':noload|';
            }
        }

        $cache = new DescriptorCache();
        $payload = $cache->read();
        if ($payload !== null) {
            $accum .= 'descriptors:' . $payload['hash'] . '|';
        }

        return substr(sha1($accum), 0, 12);
    }

    /**
     * @return array{file:string,class:string}|null
     */
    public function getLineClass(string $slug): ?array
    {
        $slug = $this->normaliseSlug($slug);
        return self::MAP[$slug]['line'] ?? null;
    }

    /**
     * Discover verb-style methods exposed by a Dolibarr object class
     * (V2.4 Sprint 2). Returns the auto-discovered list with maturity
     * tags so the frontend palette can render Verified/Experimental
     * badges.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discoverVerbs(string $slug, \DoliDB $db, bool $simulate = true): array
    {
        $instance = $this->build($slug, $db);
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover($instance);
        if ($simulate) {
            $verbs = $discoverer->simulateAndAnnotate($instance, $verbs);
        }
        return $verbs;
    }

    /**
     * Build the line companion object (eg. FactureLigne for facture).
     */
    public function buildLine(string $slug, \DoliDB $db): object
    {
        $line = $this->getLineClass($slug);
        if ($line === null) {
            throw new RuntimeException('Object does not support line items: ' . $slug);
        }

        \dol_include_once($line['file']);
        $class = '\\' . $line['class'];
        if (!class_exists($class)) {
            throw new RuntimeException('Dolibarr line class not found: ' . $line['class']);
        }

        return new $class($db);
    }

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

    private function normaliseSlug(string $slug): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($slug)) ?: '');
    }
}
