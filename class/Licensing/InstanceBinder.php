<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Computes the deterministic `instanceId` of a Dolibarr instance,
 * used to bind a Dolistore licence to a single deployment.
 *
 *   instanceId = sha256(MAIN_INFO_SOCIETE_NOM | DOL_URL_ROOT | local_salt)
 *
 * Where `local_salt` is generated at install time and stored in
 * `llx_knot_config`. Copying an activated extension folder to another
 * Dolibarr instance fails the next refresh because the recomputed
 * `instanceId` no longer matches the one bound at activation.
 */
final class InstanceBinder
{
    public function __construct(
        private readonly string $societeName,
        private readonly string $dolUrlRoot,
        private readonly string $localSalt,
        private readonly ?string $pinnedFingerprint = null,
    ) {
    }

    /**
     * Docs Docker only: keep licence cache binding when {@see Bootstrap::localSalt}
     * was pinned after the licence was activated with an earlier salt.
     */
    public static function withPinnedFingerprint(string $fingerprint, string $localSalt): self
    {
        return new self('', '', $localSalt, $fingerprint);
    }

    public function compute(): string
    {
        if ($this->pinnedFingerprint !== null && $this->pinnedFingerprint !== '') {
            return $this->pinnedFingerprint;
        }

        return hash(
            'sha256',
            implode('|', [
                trim($this->societeName),
                rtrim(trim($this->dolUrlRoot), '/'),
                trim($this->localSalt),
            ])
        );
    }

    /** Salt used for activation-code encryption at rest (never log). */
    public function localSaltValue(): string
    {
        return trim($this->localSalt);
    }

    /**
     * Verify that the recomputed instanceId matches the one bound to
     * the cached licence. Constant-time comparison (avoids timing leak).
     */
    public function matches(string $boundInstanceId): bool
    {
        if ($this->pinnedFingerprint !== null && $this->pinnedFingerprint !== '') {
            return hash_equals($this->pinnedFingerprint, $boundInstanceId);
        }

        return hash_equals($this->compute(), $boundInstanceId);
    }

    /**
     * Generate a 32-byte cryptographic salt suitable for storage in
     * `llx_knot_config`. Called once at module install / first run.
     */
    public static function generateLocalSalt(): string
    {
        return bin2hex(random_bytes(32));
    }
}
