<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for encrypted credentials.
 *
 * Stores and retrieves credentials whose `encrypted_data` is encrypted
 * with AES-256-GCM (handled by the credential service). The repository
 * never returns decrypted secrets; it exposes only metadata (ref, label,
 * type, connector_type, expiry, audit fields).
 */
final class CredentialRepository extends AbstractRepository
{
    /**
     * Insert a credential and return its id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $entity, ?int $userId): int
    {
        $ref = $this->generateRef($entity);
        $label = $this->cleanLabel((string) ($data['label'] ?? ''));
        $type = $this->cleanKey((string) ($data['type'] ?? 'generic'));
        $connectorType = $this->cleanKey((string) ($data['connector_type'] ?? $data['connectorType'] ?? 'generic'));
        $encryptedData = (string) ($data['encrypted_data'] ?? $data['encryptedData'] ?? '');
        $encryptionVersion = (string) ($data['encryption_version'] ?? $data['encryptionVersion'] ?? '1');
        $expiresAt = $this->normaliseDate((string) ($data['expires_at'] ?? $data['expiresAt'] ?? ''));
        $now = $this->db->idate(time());

        if ($label === '' || $type === '' || $connectorType === '' || $encryptedData === '') {
            return 0;
        }

        $sql = 'INSERT INTO ' . $this->table('credential') . ' (';
        $sql .= 'ref, label, type, connector_type, encrypted_data, encryption_version, expires_at, ';
        $sql .= 'entity, fk_user_creat, fk_user_modif, date_creation';
        $sql .= ') VALUES (';
        $sql .= "'" . $this->db->escape($ref) . "',";
        $sql .= "'" . $this->db->escape($label) . "',";
        $sql .= "'" . $this->db->escape($type) . "',";
        $sql .= "'" . $this->db->escape($connectorType) . "',";
        $sql .= "'" . $this->db->escape($encryptedData) . "',";
        $sql .= "'" . $this->db->escape($encryptionVersion) . "',";
        $sql .= ($expiresAt !== null ? "'" . $this->db->escape($expiresAt) . "'" : 'NULL') . ',';
        $sql .= (int) $entity . ',';
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= ($userId !== null ? (int) $userId : 'NULL') . ',';
        $sql .= "'" . $now . "'";
        $sql .= ')';

        if (!$this->db->query($sql)) {
            return 0;
        }

        return (int) $this->db->last_insert_id($this->table('credential'));
    }

    /**
     * Update credential metadata and optionally replace encrypted payload.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, int $entity, ?int $userId): bool
    {
        if ($this->find($id, $entity) === null) {
            return false;
        }

        $sets = [];
        if (array_key_exists('label', $data)) {
            $sets[] = "label = '" . $this->db->escape($this->cleanLabel((string) $data['label'])) . "'";
        }
        if (array_key_exists('type', $data)) {
            $sets[] = "type = '" . $this->db->escape($this->cleanKey((string) $data['type'])) . "'";
        }
        if (array_key_exists('connector_type', $data) || array_key_exists('connectorType', $data)) {
            $connectorType = (string) ($data['connector_type'] ?? $data['connectorType'] ?? '');
            $sets[] = "connector_type = '" . $this->db->escape($this->cleanKey($connectorType)) . "'";
        }
        if (array_key_exists('encrypted_data', $data) || array_key_exists('encryptedData', $data)) {
            $encryptedData = (string) ($data['encrypted_data'] ?? $data['encryptedData'] ?? '');
            if ($encryptedData !== '') {
                $sets[] = "encrypted_data = '" . $this->db->escape($encryptedData) . "'";
            }
        }
        if (array_key_exists('encryption_version', $data) || array_key_exists('encryptionVersion', $data)) {
            $version = (string) ($data['encryption_version'] ?? $data['encryptionVersion'] ?? '1');
            $sets[] = "encryption_version = '" . $this->db->escape($version) . "'";
        }
        if (array_key_exists('expires_at', $data) || array_key_exists('expiresAt', $data)) {
            $expiresAt = $this->normaliseDate((string) ($data['expires_at'] ?? $data['expiresAt'] ?? ''));
            $sets[] = 'expires_at = ' . ($expiresAt !== null ? "'" . $this->db->escape($expiresAt) . "'" : 'NULL');
        }
        if ($userId !== null) {
            $sets[] = 'fk_user_modif = ' . (int) $userId;
        }

        if ($sets === []) {
            return true;
        }

        $sql = 'UPDATE ' . $this->table('credential') . ' SET ' . implode(', ', $sets);
        $sql .= ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;

        return $this->db->query($sql) !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $entity, ?string $connectorType = null, int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT rowid, ref, label, type, connector_type, encryption_version, expires_at, '
            . 'fk_user_creat, fk_user_modif, date_creation, tms FROM ' . $this->table('credential')
            . ' WHERE entity = ' . (int) $entity;

        if ($connectorType !== null && $connectorType !== '') {
            $sql .= " AND connector_type = '" . $this->db->escape($connectorType) . "'";
        }

        $sql .= ' ORDER BY tms DESC ' . $this->db->plimit($limit, $offset);

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }

        $rows = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = [
                'id' => (int) $obj->rowid,
                'ref' => (string) $obj->ref,
                'label' => (string) $obj->label,
                'type' => (string) $obj->type,
                'connectorType' => (string) $obj->connector_type,
                'encryptionVersion' => (string) $obj->encryption_version,
                'expiresAt' => $obj->expires_at !== null ? (string) $obj->expires_at : null,
                'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
                'modifiedBy' => $obj->fk_user_modif !== null ? (int) $obj->fk_user_modif : null,
                'createdAt' => (string) $obj->date_creation,
                'updatedAt' => (string) $obj->tms,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $entity): ?array
    {
        $sql = 'SELECT rowid, ref, label, type, connector_type, encryption_version, expires_at, '
            . 'fk_user_creat, fk_user_modif, date_creation, tms FROM ' . $this->table('credential')
            . ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'id' => (int) $obj->rowid,
            'ref' => (string) $obj->ref,
            'label' => (string) $obj->label,
            'type' => (string) $obj->type,
            'connectorType' => (string) $obj->connector_type,
            'encryptionVersion' => (string) $obj->encryption_version,
            'expiresAt' => $obj->expires_at !== null ? (string) $obj->expires_at : null,
            'createdBy' => $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null,
            'modifiedBy' => $obj->fk_user_modif !== null ? (int) $obj->fk_user_modif : null,
            'createdAt' => (string) $obj->date_creation,
            'updatedAt' => (string) $obj->tms,
        ];
    }

    /**
     * Return the encrypted payload alongside the metadata for runtime use only.
     *
     * Callers MUST decrypt with `CredentialCipher` and SHOULD discard the
     * cleartext as soon as it has been forwarded to the connector.
     *
     * @return array<string, mixed>|null
     */
    public function findEncrypted(int $id, int $entity): ?array
    {
        $sql = 'SELECT rowid, ref, label, type, connector_type, encrypted_data, encryption_version, expires_at '
            . 'FROM ' . $this->table('credential')
            . ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;
        $resql = $this->db->query($sql);
        if ($resql === false) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'id' => (int) $obj->rowid,
            'ref' => (string) $obj->ref,
            'label' => (string) $obj->label,
            'type' => (string) $obj->type,
            'connectorType' => (string) $obj->connector_type,
            'encryptedData' => (string) $obj->encrypted_data,
            'encryptionVersion' => (string) $obj->encryption_version,
            'expiresAt' => $obj->expires_at !== null ? (string) $obj->expires_at : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countByConnectorType(int $entity): array
    {
        $sql = 'SELECT connector_type, COUNT(*) AS total FROM ' . $this->table('credential')
            . ' WHERE entity = ' . (int) $entity . ' GROUP BY connector_type';
        $resql = $this->db->query($sql);
        $counts = [];
        if ($resql === false) {
            return $counts;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $counts[(string) $obj->connector_type] = (int) $obj->total;
        }
        return $counts;
    }

    public function delete(int $id, int $entity): bool
    {
        $sql = 'DELETE FROM ' . $this->table('credential')
            . ' WHERE rowid = ' . (int) $id . ' AND entity = ' . (int) $entity;
        return $this->db->query($sql) !== false;
    }

    private function generateRef(int $entity): string
    {
        return 'KNOT-CRED-' . (int) $entity . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function cleanLabel(string $label): string
    {
        return trim(substr($label, 0, 255));
    }

    private function cleanKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_.:-]/', '', trim($key)) ?: '';
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return $this->db->idate($timestamp);
    }
}
