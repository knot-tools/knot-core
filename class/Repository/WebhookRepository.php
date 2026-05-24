<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Repository for webhook definitions.
 */
final class WebhookRepository extends AbstractRepository
{
    /**
     * Fetch an active webhook by token.
     *
     * @return array<string, mixed>|null
     */
    public function fetchActiveByToken(string $token): ?array
    {
        $sql = 'SELECT rowid, fk_workflow, method, secret_hmac, ip_allowlist, rate_limit_per_minute, entity ';
        $sql .= 'FROM ' . $this->table('webhook') . ' ';
        $sql .= "WHERE webhook_token = '" . $this->db->escape($token) . "' ";
        $sql .= 'AND is_active = 1';

        $res = $this->db->query($sql);
        if (!$res || $this->db->num_rows($res) === 0) {
            return null;
        }

        $obj = $this->db->fetch_object($res);
        return [
            'id' => (int) $obj->rowid,
            'workflowId' => (int) $obj->fk_workflow,
            'method' => (string) $obj->method,
            'secretHmac' => (string) $obj->secret_hmac,
            'ipAllowlist' => (string) $obj->ip_allowlist,
            'rateLimitPerMinute' => (int) $obj->rate_limit_per_minute,
            'entity' => (int) $obj->entity,
        ];
    }

    /**
     * Increment webhook hit counters.
     */
    public function recordHit(int $webhookId): bool
    {
        $sql = 'UPDATE ' . $this->table('webhook') . ' SET ';
        $sql .= 'hit_count = hit_count + 1, ';
        $sql .= "last_hit_at = '" . $this->db->idate(time()) . "' ";
        $sql .= 'WHERE rowid = ' . (int) $webhookId;

        return (bool) $this->db->query($sql);
    }

    /**
     * Fetch the webhook bound to a workflow.
     *
     * @return array<string, mixed>|null
     */
    public function fetchByWorkflow(int $workflowId, int $entity): ?array
    {
        $sql = 'SELECT rowid, fk_workflow, webhook_token, method, secret_hmac, ip_allowlist, '
            . 'is_active, hit_count, last_hit_at, rate_limit_per_minute, entity '
            . 'FROM ' . $this->table('webhook') . ' '
            . 'WHERE fk_workflow = ' . (int) $workflowId . ' AND entity = ' . (int) $entity . ' '
            . 'ORDER BY rowid ASC LIMIT 1';

        $res = $this->db->query($sql);
        if (!$res || $this->db->num_rows($res) === 0) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return [
            'id' => (int) $obj->rowid,
            'workflowId' => (int) $obj->fk_workflow,
            'token' => (string) $obj->webhook_token,
            'method' => (string) $obj->method,
            'secretHmac' => (string) $obj->secret_hmac,
            'ipAllowlist' => (string) $obj->ip_allowlist,
            'isActive' => (int) $obj->is_active === 1,
            'hitCount' => (int) $obj->hit_count,
            'lastHitAt' => $obj->last_hit_at !== null ? (string) $obj->last_hit_at : null,
            'rateLimitPerMinute' => (int) $obj->rate_limit_per_minute,
            'entity' => (int) $obj->entity,
        ];
    }

    /**
     * Provision (or rotate) a webhook for a workflow.
     *
     * @param array<string, mixed> $config { method, secretHmac?, ipAllowlist?, rateLimitPerMinute?, isActive? }
     * @return array<string, mixed>|null
     */
    public function provision(int $workflowId, int $entity, array $config = []): ?array
    {
        $method = strtoupper((string) ($config['method'] ?? 'POST'));
        $secret = (string) ($config['secretHmac'] ?? '');
        $ipAllow = (string) ($config['ipAllowlist'] ?? '');
        $rate = max(1, (int) ($config['rateLimitPerMinute'] ?? 60));
        $active = isset($config['isActive']) ? ((bool) $config['isActive'] ? 1 : 0) : 1;

        $existing = $this->fetchByWorkflow($workflowId, $entity);
        if ($existing !== null) {
            $rotateSecret = !empty($config['rotateSecret']);
            $newSecret = $rotateSecret ? bin2hex(random_bytes(24)) : ($secret !== '' ? $secret : (string) $existing['secretHmac']);
            $sql = 'UPDATE ' . $this->table('webhook') . ' SET '
                . "method = '" . $this->db->escape($method) . "', "
                . "secret_hmac = '" . $this->db->escape($newSecret) . "', "
                . "ip_allowlist = '" . $this->db->escape($ipAllow) . "', "
                . 'rate_limit_per_minute = ' . $rate . ', '
                . 'is_active = ' . $active . ' '
                . 'WHERE rowid = ' . (int) $existing['id'] . ' AND entity = ' . (int) $entity;
            $this->db->query($sql);
            return $this->fetchByWorkflow($workflowId, $entity);
        }

        $token = bin2hex(random_bytes(24));
        $providedSecret = $secret !== '' ? $secret : bin2hex(random_bytes(24));
        $sql = 'INSERT INTO ' . $this->table('webhook') . ' '
            . '(fk_workflow, webhook_token, method, secret_hmac, ip_allowlist, is_active, rate_limit_per_minute, entity) '
            . 'VALUES (' . (int) $workflowId . ", '" . $this->db->escape($token) . "', "
            . "'" . $this->db->escape($method) . "', '" . $this->db->escape($providedSecret) . "', "
            . "'" . $this->db->escape($ipAllow) . "', " . $active . ', ' . $rate . ', ' . (int) $entity . ')';
        if (!$this->db->query($sql)) {
            return null;
        }
        return $this->fetchByWorkflow($workflowId, $entity);
    }
}
