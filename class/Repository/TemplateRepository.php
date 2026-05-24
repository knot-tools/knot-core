<?php

declare(strict_types=1);

namespace Knot\Repository;

use Knot\Marketplace\CatalogClientFactory;
use Knot\Marketplace\TemplateClient;

/**
 * Local cache mirror for the workflow templates served by the public
 * marketplace at license.knot.tools.
 *
 * Marketplace v2 (V2.5.0c) moved the canonical template list to
 * license.knot.tools, where it is editable from the admin backoffice
 * with tier/status/icon controls. Knot Core now consumes the catalog
 * via {@see \Knot\Marketplace\TemplateClient} and persists the most
 * recent successful response into `llx_knot_template` so the editor
 * keeps working briefly when the network is down.
 *
 * The legacy `is_system` flag is retained but no longer drives
 * behaviour — every cached row is functionally equivalent.
 */
class TemplateRepository extends AbstractRepository
{
    /**
     * List the local cache for the given entity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCached(int $entity): array
    {
        $sql = sprintf(
            'SELECT rowid, ref, slug, label, description, category, tier, status, icon,'
            . ' json_definition, cached_at, source
             FROM %s
             WHERE entity = %d
             ORDER BY label ASC',
            $this->table('template'),
            $entity
        );

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $this->hydrate($obj);
        }

        return $rows;
    }

    /**
     * Backwards-compatible alias kept for callers migrating from the
     * V2.0 API. New code should call {@see listCached()} explicitly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $entity): array
    {
        return $this->listCached($entity);
    }

    /**
     * Lookup a single cached row by its license slug
     * (e.g. `knot-tpl-001`). Returns null when the cache has not been
     * populated yet — callers should then trigger
     * {@see \Knot\Marketplace\TemplateClient::refresh()}.
     */
    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug, int $entity): ?array
    {
        $sql = sprintf(
            'SELECT rowid, ref, slug, label, description, category, tier, status, icon,'
            . ' json_definition, cached_at, source
             FROM %s
             WHERE slug = "%s" AND entity = %d',
            $this->table('template'),
            $this->db->escape($slug),
            $entity
        );
        $resql = $this->db->query($sql);
        if ($resql === false) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj ? $this->hydrate($obj) : null;
    }

    /**
     * Lookup by local rowid (used by POST /api/templates.php for
     * backward compatibility with stored frontend bookmarks).
     */
    /** @return array<string, mixed>|null */
    public function find(int $templateId, int $entity): ?array
    {
        $sql = sprintf(
            'SELECT rowid, ref, slug, label, description, category, tier, status, icon,'
            . ' json_definition, cached_at, source
             FROM %s
             WHERE rowid = %d AND entity = %d',
            $this->table('template'),
            $templateId,
            $entity
        );
        $resql = $this->db->query($sql);
        if ($resql === false) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj ? $this->hydrate($obj) : null;
    }

    /**
     * Refresh the local cache from a list of templates returned by
     * {@see \Knot\Marketplace\CatalogClient::fetch('template')}.
     *
     * Returns the number of upserted rows. Idempotent: rows with no
     * change still bump `cached_at` and `updated_at` so admins can
     * see the cache is being kept fresh.
     *
     * @param array<int, array<string, mixed>> $templates Normalised
     *   rows produced by CatalogClient (keys: slug, label, description,
     *   category, icon, templateCategory, definition, tier).
     */
    public function cacheFromLicense(array $templates, int $entity): int
    {
        $written = 0;
        $now = $this->db->idate(time());
        foreach ($templates as $template) {
            $slug = (string) ($template['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $definition = is_array($template['definition'] ?? null) ? $template['definition'] : [];
            // Stable ref kept for the editor breadcrumb. Falls back to
            // upper-snake of the slug when the license backend does not
            // expose a separate ref (current case).
            $ref = strtoupper(str_replace('-', '_', $slug));

            $values = [
                'ref' => $this->db->escape($ref),
                'slug' => $this->db->escape($slug),
                'label' => $this->db->escape((string) ($template['label'] ?? $slug)),
                'description' => $this->db->escape((string) ($template['description'] ?? '')),
                'category' => $this->db->escape(
                    (string) ($template['templateCategory'] ?? $template['category'] ?? 'general')
                ),
                'tier' => $this->db->escape((string) ($template['tier'] ?? 'free')),
                'status' => 'published',
                'icon' => $this->db->escape((string) ($template['icon'] ?? '')),
                'definition' => $this->db->escape(json_encode($definition, JSON_UNESCAPED_SLASHES) ?: '{}'),
                'cached_at' => $now,
                'source' => 'license.knot.tools',
            ];

            $existing = $this->findBySlug($slug, $entity);
            if ($existing === null) {
                $sql = sprintf(
                    'INSERT INTO %s '
                    . '(ref, slug, label, description, category, tier, status, icon, json_definition,'
                    . ' is_system, cached_at, source, entity)
                     VALUES ("%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", 1, "%s", "%s", %d)',
                    $this->table('template'),
                    $values['ref'],
                    $values['slug'],
                    $values['label'],
                    $values['description'],
                    $values['category'],
                    $values['tier'],
                    $values['status'],
                    $values['icon'],
                    $values['definition'],
                    $values['cached_at'],
                    $values['source'],
                    $entity
                );
            } else {
                $sql = sprintf(
                    'UPDATE %s
                     SET ref = "%s",
                         label = "%s",
                         description = "%s",
                         category = "%s",
                         tier = "%s",
                         status = "%s",
                         icon = "%s",
                         json_definition = "%s",
                         cached_at = "%s",
                         source = "%s"
                     WHERE rowid = %d',
                    $this->table('template'),
                    $values['ref'],
                    $values['label'],
                    $values['description'],
                    $values['category'],
                    $values['tier'],
                    $values['status'],
                    $values['icon'],
                    $values['definition'],
                    $values['cached_at'],
                    $values['source'],
                    (int) $existing['id']
                );
            }
            if ($this->db->query($sql) !== false) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * Best-effort template catalog sync after setup completes.
     *
     * Populates `llx_knot_template` from license.knot.tools when reachable.
     * Offline instances keep an empty cache until the admin refreshes.
     */
    public function seed(int $entity): void
    {
        $configRepo = new KnotConfigRepository($this->db);
        $client = new TemplateClient($this, $configRepo, CatalogClientFactory::create(null, $this->db));
        $client->forceRefresh($entity);
    }

    /**
     * Import starter workflows from {@code examples/starter/*.knot.json}
     * as disabled drafts (same idea as {@see seedShowcaseWorkflows}).
     *
     * Idempotent: skips rows that already exist for the same {@code ref}.
     *
     * @return int Number of workflows inserted.
     */
    public function seedDemoWorkflows(int $entity, ?int $userId = null): int
    {
        $dir = dirname(__DIR__, 2) . '/examples/starter';
        if (!is_dir($dir)) {
            return 0;
        }
        $inserted = 0;
        $now = $this->db->idate(time());
        foreach (glob($dir . '/*.knot.json') ?: [] as $file) {
            $raw = (string) @file_get_contents($file);
            $data = json_decode($raw, true);
            if (!is_array($data) || ($data['knotExport'] ?? '') !== '1.0') {
                continue;
            }
            $workflow = $data['workflow'] ?? null;
            if (!is_array($workflow)) {
                continue;
            }
            $definition = $workflow['definition'] ?? null;
            if (!is_array($definition) || ($definition['schemaVersion'] ?? '') !== '1.0') {
                continue;
            }
            $inner = $definition['workflow'] ?? [];
            $ref = is_array($inner) ? (string) ($inner['ref'] ?? '') : '';
            if ($ref === '') {
                $base = basename((string) $file, '.knot.json');
                $ref = 'STARTER_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $base) ?: 'WORKFLOW');
            }
            $existing = $this->db->query(sprintf(
                'SELECT rowid FROM %s WHERE ref = "%s" AND entity = %d',
                $this->table('workflow'),
                $this->db->escape($ref),
                $entity
            ));
            if ($existing !== false && $this->db->fetch_object($existing)) {
                continue;
            }
            $definition['metadata'] = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
            $definition['metadata']['starter'] = true;
            $definition['metadata']['demoSource'] = $ref;

            $sql = sprintf(
                'INSERT INTO %s '
                . '(ref, label, description, status, json_definition, version_schema,'
                . ' fk_user_creat, date_creation, entity)
                 VALUES ("%s", "%s", "%s", "disabled", "%s", "1.0", %s, "%s", %d)',
                $this->table('workflow'),
                $this->db->escape($ref),
                $this->db->escape((string) ($workflow['label'] ?? $ref)),
                $this->db->escape((string) ($workflow['description'] ?? '')),
                $this->db->escape((string) (json_encode($definition, JSON_UNESCAPED_SLASHES) ?: '{}')),
                $userId !== null ? (int) $userId : 'NULL',
                $now,
                $entity
            );
            if ($this->db->query($sql) !== false) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /**
     * Delete cached rows that no longer exist in the upstream catalog.
     * Used by {@see \Knot\Marketplace\TemplateClient::refresh()} after
     * a successful fetch so the local cache cannot drift.
     *
     * @param array<int, string> $slugsToKeep
     */
    public function pruneMissing(array $slugsToKeep, int $entity): int
    {
        if ($slugsToKeep === []) {
            // Defensive: an empty upstream list is more likely a network
            // glitch than a deliberate full archival, so we never prune
            // everything in that case.
            return 0;
        }
        $quoted = array_map(fn (string $s): string => '"' . $this->db->escape($s) . '"', $slugsToKeep);
        $sql = sprintf(
            'DELETE FROM %s WHERE entity = %d AND source = "license.knot.tools" AND slug NOT IN (%s)',
            $this->table('template'),
            $entity,
            implode(',', $quoted)
        );
        $resql = $this->db->query($sql);
        if ($resql === false) {
            return 0;
        }
        return (int) $this->db->affected_rows($resql);
    }

    /**
     * Materialise the 5 showcase workflows shipped under tests/fixtures/demos/
     * as disabled draft workflows so users can clone & adapt them.
     *
     * Idempotent (keyed on `KNOT-SHOWCASE-NNN`).
     *
     * @return int Number of showcase workflows inserted.
     */
    public function seedShowcaseWorkflows(int $entity, ?int $userId = null): int
    {
        $dir = dirname(__DIR__, 2) . '/tests/fixtures/demos';
        if (!is_dir($dir)) {
            return 0;
        }
        $inserted = 0;
        $now = $this->db->idate(time());
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $raw = (string) @file_get_contents($file);
            $data = json_decode($raw, true);
            if (
                !is_array($data)
                || empty($data['ref'])
                || empty($data['label'])
                || !is_array($data['definition'] ?? null)
            ) {
                continue;
            }
            $ref = (string) $data['ref'];
            $existing = $this->db->query(sprintf(
                'SELECT rowid FROM %s WHERE ref = "%s" AND entity = %d',
                $this->table('workflow'),
                $this->db->escape($ref),
                $entity
            ));
            if ($existing !== false && $this->db->fetch_object($existing)) {
                continue;
            }
            $definition = $data['definition'];
            $definition['metadata'] = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
            $definition['metadata']['showcase'] = true;
            $definition['metadata']['demoSource'] = $ref;

            $sql = sprintf(
                'INSERT INTO %s '
                . '(ref, label, description, status, json_definition, version_schema,'
                . ' fk_user_creat, date_creation, entity)
                 VALUES ("%s", "%s", "%s", "disabled", "%s", "1.0", %s, "%s", %d)',
                $this->table('workflow'),
                $this->db->escape($ref),
                $this->db->escape((string) $data['label']),
                $this->db->escape((string) ($data['description'] ?? '')),
                $this->db->escape((string) (json_encode($definition, JSON_UNESCAPED_SLASHES) ?: '{}')),
                $userId !== null ? (int) $userId : 'NULL',
                $now,
                $entity
            );
            if ($this->db->query($sql) !== false) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /**
     * @param object $obj
     * @return array<string, mixed>
     */
    private function hydrate(object $obj): array
    {
        $slug = isset($obj->slug) ? (string) $obj->slug : strtolower((string) $obj->ref);
        return [
            'id' => (int) $obj->rowid,
            'ref' => (string) $obj->ref,
            'slug' => $slug,
            'label' => (string) $obj->label,
            'description' => (string) $obj->description,
            'category' => (string) $obj->category,
            'tier' => (string) ($obj->tier ?? 'free'),
            'status' => (string) ($obj->status ?? 'published'),
            'icon' => (string) ($obj->icon ?? ''),
            'cachedAt' => isset($obj->cached_at) ? (string) $obj->cached_at : null,
            'source' => (string) ($obj->source ?? 'license.knot.tools'),
            'isSystem' => true,
            'definition' => (array) (json_decode((string) $obj->json_definition, true) ?? []),
        ];
    }
}
