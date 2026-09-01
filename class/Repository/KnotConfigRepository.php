<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Persistent key/value store for Knot Core internal configuration.
 *
 * Backed by `llx_knot_config` (created in V2.5.0a migration). This is the
 * authoritative storage for state that must survive process restarts but
 * does not belong in Dolibarr's `llx_const` (which is admin-facing and
 * gets exported into config dumps that should never include secrets).
 *
 * Typical use cases:
 *   - `licensing.local_salt` — 32 random bytes hex, used by
 *     {@see \Knot\Licensing\InstanceBinder} to derive `instanceId`.
 *     Generated once on first {@see \Knot\Licensing\Bootstrap} call,
 *     never logged.
 *   - `licensing.activation_enc.<extensionId>` — AES-GCM ciphertext of
 *     the Dolistore activation code (same blob as cache
 *     `activationCodeEnc`). Never logged in plaintext.
 *
 * Multi-entity aware: each (entity, key) pair is independent so the
 * same Dolibarr installation hosting multiple entities still produces
 * distinct instance bindings per entity.
 */
class KnotConfigRepository extends AbstractRepository
{
    /**
     * Read a config value, returning the default when absent.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $sql = sprintf(
            "SELECT v FROM %sknot_config WHERE entity = %d AND k = '%s' LIMIT 1",
            MAIN_DB_PREFIX,
            $this->entity(),
            $this->db->escape($key),
        );
        $res = $this->db->query($sql);
        if (!$res) {
            return $default;
        }
        $row = $this->db->fetch_object($res);
        if ($row === null) {
            return $default;
        }
        return isset($row->v) ? (string) $row->v : $default;
    }

    /**
     * Upsert a config value (atomic via ON DUPLICATE KEY UPDATE).
     */
    public function set(string $key, string $value): void
    {
        $now = $this->db->idate(time());
        $sql = sprintf(
            "INSERT INTO %sknot_config (entity, k, v, created_at, updated_at) "
            . "VALUES (%d, '%s', '%s', '%s', '%s') "
            . "ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
            MAIN_DB_PREFIX,
            $this->entity(),
            $this->db->escape($key),
            $this->db->escape($value),
            $now,
            $now,
        );
        $this->db->query($sql);
    }

    /**
     * Remove a config entry (no-op when absent).
     */
    public function delete(string $key): void
    {
        $sql = sprintf(
            "DELETE FROM %sknot_config WHERE entity = %d AND k = '%s'",
            MAIN_DB_PREFIX,
            $this->entity(),
            $this->db->escape($key),
        );
        $this->db->query($sql);
    }

    /**
     * Resolve the active Dolibarr entity (multi-entity aware).
     */
    private function entity(): int
    {
        if (isset($GLOBALS['conf']) && is_object($GLOBALS['conf'])) {
            $conf = $GLOBALS['conf'];
            if (isset($conf->entity)) {
                return (int) $conf->entity;
            }
        }
        return 1;
    }
}
