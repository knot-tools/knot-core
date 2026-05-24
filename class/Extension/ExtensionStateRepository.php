<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Extension;

use Knot\Repository\AbstractRepository;

/**
 * Per-(user, extension) key/value store backing the
 * `window.KnotCore.persistedState(extensionId)` runtime helper (ADR-20).
 *
 * - The browser keeps a synchronous localStorage cache; this table is the
 *   source of truth that survives browser changes and instance moves.
 * - Values are stored verbatim (JSON-encoded by the caller). The repository
 *   does not introspect them.
 * - Multi-tenant: every operation requires an `entity`, never inferred.
 * - Quotas: callers should enforce key count / value size at the API layer.
 *   The schema constrains keys to 128 chars and extension IDs to 64 chars.
 */
final class ExtensionStateRepository extends AbstractRepository
{
    public const MAX_KEY_LENGTH = 128;
    public const MAX_EXTENSION_ID_LENGTH = 64;
    public const MAX_VALUE_BYTES = 65_535;
    public const MAX_KEYS_PER_PAIR = 200;

    /**
     * Return every key/value pair for a (user, extension) couple.
     *
     * @return array<string, string> ordered by `state_key` ASC
     */
    public function all(int $userId, string $extensionId, int $entity): array
    {
        $extensionId = $this->cleanExtensionId($extensionId);
        if ($userId <= 0 || $extensionId === '') {
            return [];
        }

        $sql = 'SELECT state_key, state_value FROM ' . $this->stateTable();
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_user = ' . (int) $userId;
        $sql .= " AND extension_id = '" . $this->db->escape($extensionId) . "'";
        $sql .= ' ORDER BY state_key ASC';

        $res = $this->db->query($sql);
        if (!$res) {
            return [];
        }

        $out = [];
        while ($row = $this->db->fetch_object($res)) {
            $out[(string) $row->state_key] = $row->state_value === null ? '' : (string) $row->state_value;
        }
        return $out;
    }

    /**
     * Count the keys already stored for this (user, extension) couple.
     */
    public function countKeys(int $userId, string $extensionId, int $entity): int
    {
        $extensionId = $this->cleanExtensionId($extensionId);
        if ($userId <= 0 || $extensionId === '') {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS nb FROM ' . $this->stateTable();
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_user = ' . (int) $userId;
        $sql .= " AND extension_id = '" . $this->db->escape($extensionId) . "'";

        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $this->db->fetch_object($res);
        return $row ? (int) $row->nb : 0;
    }

    /**
     * Insert or update a single key for this (user, extension) couple.
     * Returns false on validation failure or DB error.
     */
    public function set(int $userId, string $extensionId, string $key, string $value, int $entity): bool
    {
        $extensionId = $this->cleanExtensionId($extensionId);
        $key = $this->cleanKey($key);
        if ($userId <= 0 || $extensionId === '' || $key === '') {
            return false;
        }
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return false;
        }

        $sql = 'INSERT INTO ' . $this->stateTable();
        $sql .= ' (extension_id, fk_user, state_key, state_value, entity, date_creation)';
        $sql .= ' VALUES (';
        $sql .= "'" . $this->db->escape($extensionId) . "',";
        $sql .= (int) $userId . ',';
        $sql .= "'" . $this->db->escape($key) . "',";
        $sql .= "'" . $this->db->escape($value) . "',";
        $sql .= (int) $entity . ',';
        $sql .= "'" . $this->db->idate(time()) . "'";
        $sql .= ') ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)';

        return $this->db->query($sql) !== false;
    }

    /**
     * Remove a single key. Idempotent: returns true even if the row did not
     * exist (avoids 404-vs-200 ambiguity in the HTTP layer).
     */
    public function remove(int $userId, string $extensionId, string $key, int $entity): bool
    {
        $extensionId = $this->cleanExtensionId($extensionId);
        $key = $this->cleanKey($key);
        if ($userId <= 0 || $extensionId === '' || $key === '') {
            return false;
        }

        $sql = 'DELETE FROM ' . $this->stateTable();
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_user = ' . (int) $userId;
        $sql .= " AND extension_id = '" . $this->db->escape($extensionId) . "'";
        $sql .= " AND state_key = '" . $this->db->escape($key) . "'";

        return $this->db->query($sql) !== false;
    }

    /**
     * Wipe every key for a (user, extension) couple. Used by Reset onboarding
     * actions and right-to-erasure flows.
     */
    public function clear(int $userId, string $extensionId, int $entity): bool
    {
        $extensionId = $this->cleanExtensionId($extensionId);
        if ($userId <= 0 || $extensionId === '') {
            return false;
        }

        $sql = 'DELETE FROM ' . $this->stateTable();
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_user = ' . (int) $userId;
        $sql .= " AND extension_id = '" . $this->db->escape($extensionId) . "'";

        return $this->db->query($sql) !== false;
    }

    private function stateTable(): string
    {
        return $this->table('extension_state');
    }

    private function cleanExtensionId(string $extensionId): string
    {
        $extensionId = trim($extensionId);
        // Strict lowercase shape mirrors the manifest contract (ADR-20).
        // We refuse uppercase variants instead of silently lowercasing so
        // `KNOT-MIGRATION` and `knot-migration` cannot share the same slot.
        if (preg_match('/^[a-z][a-z0-9-]{0,' . (self::MAX_EXTENSION_ID_LENGTH - 1) . '}$/', $extensionId) !== 1) {
            return '';
        }
        return $extensionId;
    }

    private function cleanKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9._:-]{1,' . self::MAX_KEY_LENGTH . '}$/', $key) !== 1) {
            return '';
        }
        return $key;
    }
}
