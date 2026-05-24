<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Tracks idempotency keys so retries cannot create duplicate executions.
 */
final class IdempotencyRepository extends AbstractRepository
{
    public function findExecution(string $key, int $entity): ?int
    {
        $key = $this->cleanKey($key);
        if ($key === '') {
            return null;
        }

        $sql = 'SELECT fk_execution FROM ' . $this->table('idempotency');
        $sql .= " WHERE idempotency_key = '" . $this->db->escape($key) . "'";
        $sql .= ' AND entity = ' . (int) $entity;
        $sql .= ' LIMIT 1';

        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (int) $obj->fk_execution : null;
    }

    public function remember(string $key, int $executionId, int $entity, int $ttlSeconds = 86400): bool
    {
        $key = $this->cleanKey($key);
        if ($key === '' || $executionId <= 0) {
            return false;
        }

        $sql = 'INSERT INTO ' . $this->table('idempotency') . ' (';
        $sql .= 'idempotency_key, fk_execution, ttl_seconds, entity, date_creation';
        $sql .= ') VALUES (';
        $sql .= "'" . $this->db->escape($key) . "',";
        $sql .= (int) $executionId . ',';
        $sql .= max(60, (int) $ttlSeconds) . ',';
        $sql .= (int) $entity . ',';
        $sql .= "'" . $this->db->idate(time()) . "'";
        $sql .= ')';

        return $this->db->query($sql) !== false;
    }

    public function purgeExpired(int $entity): int
    {
        $sql = 'DELETE FROM ' . $this->table('idempotency');
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND date_creation < DATE_SUB(NOW(), INTERVAL ttl_seconds SECOND)';
        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }

        return (int) $this->db->affected_rows($res);
    }

    private function cleanKey(string $key): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '', trim($key)) ?: '', 0, 128);
    }
}
