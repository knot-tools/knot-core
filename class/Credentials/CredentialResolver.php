<?php

declare(strict_types=1);

namespace Knot\Credentials;

use Knot\Repository\CredentialRepository;

/**
 * Loads, decrypts and caches Knot credentials for connector consumption.
 *
 * The resolver is constructed once per execution and injected into the
 * WorkflowEngine. Connectors do NOT load credentials themselves; the engine
 * resolves a credential when a node carries `credentialId` and exposes the
 * cleartext secrets in `$context['credential']`.
 *
 * The cache is in-memory only and lives for the duration of a single
 * execution. After execution the resolver is discarded with the engine
 * instance.
 */
final class CredentialResolver implements CredentialResolverInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly CredentialRepository $repository,
        private readonly CredentialCipher $cipher,
        private readonly int $entity
    ) {
    }

    /**
     * Resolve the cleartext payload for a credential id.
     *
     * Returns `null` when the credential does not exist, is in another
     * tenant, or cannot be decrypted (corrupted blob, key rotation pending).
     *
     * @return array<string, mixed>|null
     */
    public function resolve(int $credentialId): ?array
    {
        if ($credentialId <= 0) {
            return null;
        }
        if (array_key_exists($credentialId, $this->cache)) {
            return $this->cache[$credentialId];
        }

        $row = $this->repository->findEncrypted($credentialId, $this->entity);
        if ($row === null || $row['encryptedData'] === '') {
            return $this->cache[$credentialId] = null;
        }

        $blob = json_decode((string) $row['encryptedData'], true);
        if (!is_array($blob)) {
            return $this->cache[$credentialId] = null;
        }

        try {
            $decrypted = $this->cipher->decrypt($blob);
        } catch (\Throwable) {
            return $this->cache[$credentialId] = null;
        }

        $secrets = is_array($decrypted['secrets'] ?? null) ? $decrypted['secrets'] : [];

        return $this->cache[$credentialId] = [
            'id' => (int) $row['id'],
            'ref' => (string) $row['ref'],
            'label' => (string) $row['label'],
            'type' => (string) $row['type'],
            'connectorType' => (string) $row['connectorType'],
            'expiresAt' => $row['expiresAt'],
            'secrets' => $secrets,
        ];
    }
}
