<?php

declare(strict_types=1);

namespace Knot\Capabilities;

use Knot\Compatibility\Versioning\BundledSnapshotCatalog;
use Knot\Compatibility\Versioning\PilotDocuments;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\ConnectorRegistry;
use Knot\Dolibarr\DescriptorCache;
use Knot\Dolibarr\ObjectFactory;
use Knot\Extension\ExtensionRegistry;
use Knot\Marketplace\MarketplaceConnectorPresentation;
use Knot\Licensing\Bootstrap;
use Knot\StateMachine\StateMachineEngine;
use Knot\Version;

/**
 * Builds the instance capability manifest for api/capabilities.php (ADR-008).
 */
final class CapabilitiesBuilder
{
    private const CACHE_TTL_SEC = 300;

    private \DoliDB $db;

    private \Conf $conf;

    private int $entity;

    public function __construct(\DoliDB $db, \Conf $conf, int $entity)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->entity = $entity;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?ExtensionRegistry $extensions = null): array
    {
        $extensions = $extensions ?? Bootstrap::buildExtensionRegistry($this->db);
        $registry = new ConnectorRegistry();
        $coreConnectors = $registry->all();
        $merged = $registry->allWithExtensions($extensions);
        $palette = $this->buildPaletteRowsSafe($registry, $extensions);

        $factory = new ObjectFactory();
        $objectRows = $factory->listObjectsForApi(null, $this->db);
        $modulesActivated = $this->modulesActivated();
        $objectPayload = [];
        foreach ($objectRows as $row) {
            $mod = (string) $row['module'];
            $active = $mod === '' || !empty($this->conf->modules[$mod]);
            $objectPayload[] = [
                'slug' => (string) $row['slug'],
                'class' => (string) $row['class'],
                'table' => null,
                'module_required' => $mod !== '' ? $mod : null,
                'module_status' => $active ? 'active' : 'inactive',
                'actions_available' => $active ? $this->defaultDolibarrObjectActions() : [],
                'states_known' => [],
                'extrafields_supported' => true,
                'introspection_quality' => !empty($row['fromMap']) ? 'mapped' : 'discovered',
            ];
        }

        $smEngine = new StateMachineEngine();
        $stateMachineStats = [
            'objects_with_known_states' => 0,
            'skipped_excluded_slug' => 0,
            'skipped_empty_states' => 0,
            'extraction_errors' => 0,
        ];
        foreach ($objectPayload as &$objectRow) {
            $slug = (string) $objectRow['slug'];
            if ($slug === '' || $objectRow['module_status'] !== 'active') {
                continue;
            }
            if (PilotDocuments::isStateMachineCapabilityExcluded($slug)) {
                ++$stateMachineStats['skipped_excluded_slug'];
                continue;
            }
            try {
                $states = $smEngine->getStates($slug, $this->db);
                if ($states === []) {
                    ++$stateMachineStats['skipped_empty_states'];
                    continue;
                }
                $objectRow['states_known'] = array_keys($states);
                ++$stateMachineStats['objects_with_known_states'];
            } catch (\Throwable) {
                ++$stateMachineStats['extraction_errors'];
                // Optional enrichment must never break the manifest.
            }
        }
        unset($objectRow);

        $categoryKeys = $this->connectorCategoryKeys($palette, $merged);

        $schemaVersioning = $this->schemaVersioningManifest();

        $descriptorCachePayload = (new DescriptorCache())->read();
        $descriptorFileCount = $descriptorCachePayload !== null
            ? count($descriptorCachePayload['descriptors'])
            : 0;

        return [
            'knot' => [
                'version' => Version::current(),
                'channel' => 'stable',
                'build_date' => date('Y-m-d'),
            ],
            'dolibarr' => [
                'version' => defined('DOL_VERSION') ? (string) constant('DOL_VERSION') : 'unknown',
                'modules_activated' => $modulesActivated,
                'modules_available_inactive' => $this->inactiveKnownModules($objectRows, $modulesActivated),
                'extrafields_count_by_object' => $this->extrafieldCounts(),
            ],
            'objects' => [
                'supported_count' => count($objectPayload),
                'descriptor_file_count' => $descriptorFileCount,
                'list' => $objectPayload,
            ],
            'connectors' => [
                'core_count' => count($coreConnectors),
                'pro_pack_count' => max(0, count($merged) - count($coreConnectors)),
                'pro_pack_installed' => count($merged) > count($coreConnectors),
                'categories' => $categoryKeys,
            ],
            'limits' => [
                'max_workflow_nodes' => 200,
                'max_concurrent_executions' => 10,
                'max_payload_size_kb' => 5000,
                'queue_backend' => 'mysql_native',
                'queue_modes_available' => ['cron'],
                'current_queue_mode' => 'cron',
                'audit_log_retention_days' => (function_exists('getDolGlobalInt')
                && ($d = (int) getDolGlobalInt('MAIN_KNOT_AUDIT_RETENTION_DAYS')) > 0)
                    ? $d
                    : 90,
            ],
            'features' => [
                'introspection_dynamic' => true,
                'state_machine_formal' => true,
                'schema_versioning' => true,
                'queue_priorities' => $this->executionTableHasPriorityColumn(),
                'ground_truth_check' => true,
                'marketplace' => true,
                'dolistore_licensing_ready' => false,
            ],
            'schema_versioning' => $schemaVersioning,
            'state_machine' => [
                'dynamic_eligibility' => true,
                'excluded_slugs' => PilotDocuments::STATE_MACHINE_EXCLUDED_SLUGS,
                'stats' => $stateMachineStats,
            ],
            'ground_truth' => [
                'last_check_date' => null,
                'last_check_status' => 'bundled_reference_shipped',
                'fields_coverage_percent' => null,
                'semantic' => 'Pilot schema snapshots + bundled reference JSON under '
                    . 'data/compatibility/snapshots (not a full DB audit).',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null Cached payload or null if stale / missing
     */
    public function loadCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['expires_at'], $raw['payload'])) {
            return null;
        }
        if (time() > (int) $raw['expires_at']) {
            return null;
        }
        /** @var array<string, mixed> */
        return $raw['payload'];
    }

    /** @param array<string, mixed> $payload */
    public function saveCache(array $payload): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $envelope = [
            'expires_at' => time() + self::CACHE_TTL_SEC,
            'payload' => $payload,
        ];
        file_put_contents($this->cachePath(), json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function invalidateCache(): void
    {
        $p = $this->cachePath();
        if (is_file($p)) {
            @unlink($p);
        }
    }

    private function cachePath(): string
    {
        $root = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : sys_get_temp_dir();

        return $root . '/knot/cache/capabilities-' . $this->entity . '.json';
    }

    /**
     * Palette merge (marketplace snippet) must never prevent publishing the manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPaletteRowsSafe(ConnectorRegistry $registry, ExtensionRegistry $extensions): array
    {
        try {
            $snippetBag = MarketplaceConnectorPresentation::resolveSnippetForConnectorsRequests($this->db);

            return $registry->describeAllForPalette($extensions, $snippetBag['connectors'], $snippetBag['lang']);
        } catch (\Throwable $e) {
            error_log('[knot capabilities] palette with marketplace snippet failed: ' . $e->getMessage());
            try {
                return $registry->describeAllForPalette($extensions, [], 'en');
            } catch (\Throwable $e2) {
                error_log('[knot capabilities] palette without snippet failed: ' . $e2->getMessage());

                return [];
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>>                                         $palette
     * @param array<string, ConnectorInterface> $merged
     *
     * @return list<string>
     */
    private function connectorCategoryKeys(array $palette, array $merged): array
    {
        $categories = [];
        foreach ($palette as $p) {
            $c = (string) ($p['category'] ?? 'other');
            $categories[$c] = true;
        }
        if ($categories !== []) {
            $keys = array_keys($categories);
            sort($keys);

            return $keys;
        }

        foreach ($merged as $connector) {
            try {
                $meta = $connector->getMetadata();
                $categories[(string) ($meta['category'] ?? 'other')] = true;
            } catch (\Throwable) {
                $categories['other'] = true;
            }
        }
        $keys = array_keys($categories);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function modulesActivated(): array
    {
        $mods = [];
        foreach ($this->conf->modules as $name => $flag) {
            if (!empty($flag)) {
                $mods[] = (string) $name;
            }
        }
        sort($mods);

        return $mods;
    }

    /**
     * @param array<int, array<string, mixed>> $objectRows
     * @param list<string>                      $activated
     *
     * @return list<string>
     */
    private function inactiveKnownModules(array $objectRows, array $activated): array
    {
        $need = [];
        foreach ($objectRows as $row) {
            $m = (string) ($row['module'] ?? '');
            if ($m !== '') {
                $need[$m] = true;
            }
        }
        $inactive = [];
        foreach (array_keys($need) as $m) {
            if (!in_array($m, $activated, true)) {
                $inactive[] = $m;
            }
        }
        sort($inactive);

        return $inactive;
    }

    /** @return array<string, int> */
    private function extrafieldCounts(): array
    {
        $table = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX . 'extrafields' : 'llx_extrafields';
        $sql = 'SELECT elementtype, COUNT(*) AS nb FROM ' . $table
            . ' WHERE entity IN (0, ' . (int) $this->entity . ') GROUP BY elementtype';
        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($obj = $this->db->fetch_object($res)) {
            $out[(string) $obj->elementtype] = (int) $obj->nb;
        }

        return $out;
    }

    /** @return list<string> */
    private function defaultDolibarrObjectActions(): array
    {
        return ['fetch', 'create', 'update', 'delete', 'change_status', 'add_note', 'generate_pdf'];
    }

    /**
     * True when migration V2.6 queue columns are present (ADR-009).
     */
    private function executionTableHasPriorityColumn(): bool
    {
        $prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
        $table = $prefix . 'knot_execution';
        $sql = 'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS ';
        $sql .= "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->db->escape($table) . "' ";
        $sql .= "AND COLUMN_NAME = 'priority'";
        $res = $this->db->query($sql);
        if (!$res) {
            return false;
        }
        $obj = $this->db->fetch_object($res);

        return $obj !== null && (int) ($obj->n ?? 0) > 0;
    }

    /**
     * Bundled snapshot catalogue (ship-only JSON). Not the live Dolibarr runtime version.
     *
     * @return array<string, mixed>
     */
    private function schemaVersioningManifest(): array
    {
        $dir = dirname(__DIR__, 2) . '/data/compatibility/snapshots';
        $catalog = new BundledSnapshotCatalog($dir);
        $rows = $catalog->listReferenceSnapshots();
        $versions = [];
        foreach ($rows as $row) {
            $v = $row['dolibarr_version'];
            if ($v !== '') {
                $versions[$v] = true;
            }
        }
        $available = array_keys($versions);
        sort($available);

        $dolibarrRows = [];
        foreach ($rows as $row) {
            if (str_starts_with($row['filename'], 'dolibarr-')) {
                $dolibarrRows[] = $row;
            }
        }
        $current = null;
        if ($dolibarrRows !== []) {
            $last = $dolibarrRows[array_key_last($dolibarrRows)];
            $current = $last['dolibarr_version'];
        }

        return [
            'current_snapshot_version' => $current,
            'snapshots_available' => $available,
            'snapshots_count' => count($rows),
            'bundled_filenames' => array_map(static fn (array $r): string => $r['filename'], $rows),
            'bundled_reference_note' => 'Shipped JSON under data/compatibility/snapshots '
                . '(excludes sample-*.json); distinct from dolibarr.version (live instance).',
            'auto_check_on_upgrade' => true,
        ];
    }
}
