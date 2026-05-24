<?php

declare(strict_types=1);

namespace Knot\Licensing;

use Knot\Licensing\Audit\LicenseAuditEvent;
use Knot\Licensing\Audit\LicenseAuditWriter;
use RuntimeException;
use Throwable;

/**
 * Orchestrator for the Dolistore licence validation chain (V2.5.0a).
 *
 * Flow:
 *   1. Read the cached signed payload (if any).
 *   2. If cache is fresh (< TTL), verify signature + instance bind +
 *      fork detection, return verdict.
 *   3. If cache is stale OR missing, call DolistoreClient.
 *      a. On success: verify signature, persist new cache entry,
 *         return verdict.
 *      b. On failure: if cache exists and OfflineGracePolicy allows,
 *         return cached verdict marked `offlineGrace = true`.
 *         Otherwise return `expired` (or `missing` if no cache at all).
 *
 * The `LicenseValidator` (Extension namespace) facade delegates to this
 * class when the manifest declares `validation = dolistore`. The
 * `local` mode keeps using its V2.3.5 implementation.
 */
final class DolistoreValidator
{
    public const DEFAULT_TTL_HOURS = 24;

    public function __construct(
        private readonly DolistoreClientContract $client,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly InstanceBinder $instanceBinder,
        private readonly ForkDetector $forkDetector,
        private readonly LicenseCache $cache,
        private readonly OfflineGracePolicy $gracePolicy,
        private readonly int $ttlHours = self::DEFAULT_TTL_HOURS,
        private readonly ?string $domain = null,
        private readonly ?string $deploymentToken = null,
        private readonly ?string $deploymentNonce = null,
        private readonly ?LicenseAuditWriter $auditWriter = null,
    ) {
    }

    /**
     * Inspect the licence for a given normalised manifest.
     *
     * @param array{id: string, license: array<string, mixed>, ...} $manifest
     */
    public function inspect(array $manifest): LicenseStatus
    {
        $extensionId = (string) $manifest['id'];
        $license = $manifest['license'];
        $productId = isset($license['productId']) ? (string) $license['productId'] : '';
        if ($productId === '') {
            return new LicenseStatus(
                LicenseStatus::INVALID,
                $extensionId,
                null,
                null,
                null,
                null,
                'Manifest declares dolistore validation but is missing productId'
            );
        }

        $manifestSignature = isset($license['manifestSignature'])
            ? strtolower(trim((string) $license['manifestSignature']))
            : '';

        if ($this->forkDetector->expectsPinnedManifestSignature($extensionId)) {
            $match = $this->forkDetector->classify($extensionId, $manifestSignature);
            if ($match === ManifestSignatureMatch::MISSING) {
                $this->audit(LicenseAuditEvent::LICENSE_FORK_DETECTED, $extensionId, [
                    'reason' => 'manifest_signature_missing',
                ]);
                return new LicenseStatus(
                    LicenseStatus::TAMPERED,
                    $extensionId,
                    null,
                    null,
                    null,
                    null,
                    'Official Knot extension requires a valid manifest signature. '
                    . 'Reinstall or update the extension from Setup → Knot → Installed products.'
                );
            }
        }

        if ($manifestSignature !== '') {
            $match = $this->forkDetector->classify($extensionId, $manifestSignature);
            if ($match === ManifestSignatureMatch::REJECTED) {
                $this->audit(LicenseAuditEvent::LICENSE_FORK_DETECTED, $extensionId, [
                    'manifestSignature' => $manifestSignature,
                    'reason' => 'manifest_signature_rejected',
                ]);
                return new LicenseStatus(
                    LicenseStatus::TAMPERED,
                    $extensionId,
                    null,
                    null,
                    null,
                    null,
                    'The installed extension manifest does not match an official Knot release. '
                    . 'Update the extension from Setup → Knot → Installed products '
                    . '(apply Knot Core and the extension together after a manifest change).'
                );
            }
        }

        $cached = $this->cache->read($extensionId);
        if ($cached !== null && $this->isCacheFresh($cached)) {
            $verdict = $this->verdictFromCache($cached);
            if ($verdict->status !== LicenseStatus::TAMPERED) {
                return $verdict;
            }
        }

        $activationCode = $this->resolveActivationCode($extensionId, $cached);
        if ($activationCode === null) {
            return new LicenseStatus(
                LicenseStatus::MISSING,
                $extensionId,
                null,
                null,
                null,
                null,
                'No activation code in cache — re-activate the extension from Knot admin'
            );
        }

        try {
            $checkParams = [
                'activationCode' => $activationCode,
                'instanceFingerprint' => $this->instanceBinder->compute(),
            ];
            if ($this->deploymentToken !== null && $this->deploymentToken !== '') {
                $checkParams['deploymentToken'] = $this->deploymentToken;
            }
            if ($this->deploymentNonce !== null && $this->deploymentNonce !== '') {
                $checkParams['deploymentNonce'] = $this->deploymentNonce;
            }
            $response = $this->client->check($checkParams);
        } catch (Throwable $e) {
            return $this->fallbackFromCache($extensionId, $cached, $e->getMessage());
        }

        $payloadJson = SignatureVerifier::canonicalize($response['payload']);
        if (!$this->signatureVerifier->verify($payloadJson, $response['signature'])) {
            $this->audit(LicenseAuditEvent::LICENSE_TAMPERED, $extensionId, [
                'reason' => 'response_signature_invalid',
                'signedAt' => $response['signedAt'],
            ]);
            return new LicenseStatus(
                LicenseStatus::TAMPERED,
                $extensionId,
                null,
                $response['signedAt'],
                $response['plan'],
                $response['issuedTo'],
                'Signature verification failed for the licence backend response'
            );
        }

        $boundFingerprint = $this->boundFingerprintFromPayload($response['payload']);
        if ($boundFingerprint !== '' && !$this->instanceBinder->matches($boundFingerprint)) {
            $this->audit(LicenseAuditEvent::LICENSE_BINDING_CHANGED, $extensionId, [
                'reason' => 'response_binding_mismatch',
                'signedAt' => $response['signedAt'],
            ]);
            return new LicenseStatus(
                LicenseStatus::TAMPERED,
                $extensionId,
                null,
                $response['signedAt'],
                $response['plan'],
                $response['issuedTo'],
                'Licence is bound to a different Dolibarr instance'
            );
        }

        if (!$response['valid']) {
            $this->cache->delete($extensionId);
            $this->audit(LicenseAuditEvent::LICENSE_REVOKED, $extensionId, [
                'expiresAt' => $response['expiresAt'] ?? null,
                'signedAt' => $response['signedAt'],
            ]);
            return new LicenseStatus(
                LicenseStatus::EXPIRED,
                $extensionId,
                $response['expiresAt'],
                $response['signedAt'],
                $response['plan'],
                $response['issuedTo'],
                'Licence backend reports the licence as no longer valid'
            );
        }

        if (
            $response['expiresAt'] !== null
            && strtotime($response['expiresAt']) !== false
            && strtotime($response['expiresAt']) < time()
        ) {
            $this->cache->delete($extensionId);
            $this->audit(LicenseAuditEvent::LICENSE_EXPIRED, $extensionId, [
                'expiresAt' => $response['expiresAt'],
                'signedAt' => $response['signedAt'],
            ]);
            return new LicenseStatus(
                LicenseStatus::EXPIRED,
                $extensionId,
                $response['expiresAt'],
                $response['signedAt'],
                $response['plan'],
                $response['issuedTo'],
                'Licence expired on ' . $response['expiresAt']
            );
        }

        $now = date('c');
        $cacheEntry = [
            'extensionId' => $extensionId,
            'instanceId' => $this->instanceBinder->compute(),
            'signedPayload' => $response['payload'],
            'signature' => $response['signature'],
            'signedAt' => $response['signedAt'],
            'expiresAt' => $response['expiresAt'],
            'plan' => $response['plan'],
            'issuedTo' => $response['issuedTo'],
            'lastSuccessfulRefresh' => $now,
            'lastAttempt' => $now,
            'lastError' => null,
        ];
        if ($cached !== null && isset($cached['activationCodeEnc'])) {
            $cacheEntry['activationCodeEnc'] = (string) $cached['activationCodeEnc'];
        }
        $this->cache->write($cacheEntry);

        $isFirstActivation = ($cached === null);
        $this->audit(
            $isFirstActivation
                ? LicenseAuditEvent::LICENSE_ACTIVATED
                : LicenseAuditEvent::LICENSE_REFRESH_SUCCESS,
            $extensionId,
            [
                'plan' => $response['plan'] ?? null,
                'expiresAt' => $response['expiresAt'] ?? null,
                'signedAt' => $response['signedAt'],
            ]
        );

        return new LicenseStatus(
            LicenseStatus::VALID,
            $extensionId,
            $response['expiresAt'],
            $response['signedAt'],
            $response['plan'],
            $response['issuedTo'],
            null
        );
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function isCacheFresh(array $cached): bool
    {
        $signedAt = isset($cached['signedAt']) ? strtotime((string) $cached['signedAt']) : false;
        if ($signedAt === false) {
            return false;
        }
        return time() < $signedAt + ($this->ttlHours * 3600);
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function verdictFromCache(array $cached): LicenseStatus
    {
        $extensionId = isset($cached['extensionId']) ? (string) $cached['extensionId'] : null;
        $payload = is_array($cached['signedPayload'] ?? null) ? $cached['signedPayload'] : [];
        $payloadJson = SignatureVerifier::canonicalize($payload);
        $signature = isset($cached['signature']) ? (string) $cached['signature'] : '';
        if (!$this->signatureVerifier->verify($payloadJson, $signature)) {
            $this->audit(LicenseAuditEvent::LICENSE_TAMPERED, (string) ($extensionId ?? ''), [
                'reason' => 'cache_signature_invalid',
                'signedAt' => $cached['signedAt'] ?? null,
            ]);
            return new LicenseStatus(
                LicenseStatus::TAMPERED,
                $extensionId,
                null,
                isset($cached['signedAt']) ? (string) $cached['signedAt'] : null,
                isset($cached['plan']) ? (string) $cached['plan'] : null,
                isset($cached['issuedTo']) ? (string) $cached['issuedTo'] : null,
                'Cached payload signature is invalid (cache likely tampered)'
            );
        }
        $boundInstanceId = isset($cached['instanceId']) ? (string) $cached['instanceId'] : '';
        if ($boundInstanceId !== '' && !$this->instanceBinder->matches($boundInstanceId)) {
            $this->audit(LicenseAuditEvent::LICENSE_BINDING_CHANGED, (string) ($extensionId ?? ''), [
                'reason' => 'cache_binding_mismatch',
                'signedAt' => $cached['signedAt'] ?? null,
            ]);
            return new LicenseStatus(
                LicenseStatus::TAMPERED,
                $extensionId,
                null,
                isset($cached['signedAt']) ? (string) $cached['signedAt'] : null,
                isset($cached['plan']) ? (string) $cached['plan'] : null,
                isset($cached['issuedTo']) ? (string) $cached['issuedTo'] : null,
                'Cached licence is bound to a different Dolibarr instance'
            );
        }
        $expiresAt = isset($cached['expiresAt']) ? (string) $cached['expiresAt'] : null;
        if ($expiresAt !== null && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            return new LicenseStatus(
                LicenseStatus::EXPIRED,
                $extensionId,
                $expiresAt,
                isset($cached['signedAt']) ? (string) $cached['signedAt'] : null,
                isset($cached['plan']) ? (string) $cached['plan'] : null,
                isset($cached['issuedTo']) ? (string) $cached['issuedTo'] : null,
                'Cached licence expired on ' . $expiresAt
            );
        }
        return new LicenseStatus(
            LicenseStatus::VALID,
            $extensionId,
            $expiresAt,
            isset($cached['signedAt']) ? (string) $cached['signedAt'] : null,
            isset($cached['plan']) ? (string) $cached['plan'] : null,
            isset($cached['issuedTo']) ? (string) $cached['issuedTo'] : null,
            null
        );
    }

    /**
     * @param array<string, mixed>|null $cached
     */
    private function fallbackFromCache(string $extensionId, ?array $cached, string $networkError): LicenseStatus
    {
        $refreshThrottleMerge = null;
        if (!$this->shouldSkipRefreshFailedAudit($cached, $networkError, $extensionId)) {
            $this->audit(LicenseAuditEvent::LICENSE_REFRESH_FAILED, $extensionId, [
                'networkError' => $networkError,
                'hasCache' => ($cached !== null),
            ]);
            $class = LicenseAuditThrottlePolicy::refreshFailureClass($networkError);
            if ($cached !== null) {
                $refreshThrottleMerge = [
                    'refreshFailedAt' => date('c'),
                    'refreshFailedClass' => $class,
                ];
            } else {
                $this->cache->writeStandaloneRefreshThrottle($extensionId, $class);
            }
        }

        if ($cached === null) {
            return new LicenseStatus(
                LicenseStatus::MISSING,
                $extensionId,
                null,
                null,
                null,
                null,
                'License backend unreachable and no cached payload: ' . $networkError
            );
        }

        $this->cache->recordFailedAttempt($extensionId, $networkError, $refreshThrottleMerge);

        $lastSuccess = isset($cached['lastSuccessfulRefresh']) ? (string) $cached['lastSuccessfulRefresh'] : '';
        if ($lastSuccess === '' || !$this->gracePolicy->isWithinGrace($lastSuccess)) {
            $this->audit(LicenseAuditEvent::LICENSE_GRACE_EXHAUSTED, $extensionId, [
                'lastSuccessfulRefresh' => $lastSuccess !== '' ? $lastSuccess : null,
                'networkError' => $networkError,
            ]);
            return new LicenseStatus(
                LicenseStatus::EXPIRED,
                $extensionId,
                isset($cached['expiresAt']) ? (string) $cached['expiresAt'] : null,
                isset($cached['signedAt']) ? (string) $cached['signedAt'] : null,
                isset($cached['plan']) ? (string) $cached['plan'] : null,
                isset($cached['issuedTo']) ? (string) $cached['issuedTo'] : null,
                'License backend unreachable beyond offline grace window: ' . $networkError
            );
        }
        $verdict = $this->verdictFromCache($cached);
        if ($verdict->status !== LicenseStatus::VALID) {
            return $verdict;
        }
        if (!$this->shouldSkipGraceEnteredAudit($cached)) {
            $this->audit(LicenseAuditEvent::LICENSE_GRACE_ENTERED, $extensionId, [
                'lastSuccessfulRefresh' => $lastSuccess,
                'networkError' => $networkError,
            ]);
            $this->cache->mergeAuditThrottle($extensionId, [
                'graceEnteredAt' => date('c'),
            ]);
        }

        return new LicenseStatus(
            LicenseStatus::VALID,
            $verdict->extensionId,
            $verdict->expiresAt,
            $verdict->signedAt,
            $verdict->plan,
            $verdict->issuedTo,
            null,
            true
        );
    }

    /**
     * @param array<string, mixed>|null $cached
     */
    private function shouldSkipRefreshFailedAudit(?array $cached, string $networkError, string $extensionId): bool
    {
        $failureClass = LicenseAuditThrottlePolicy::refreshFailureClass($networkError);
        $throttle = [];
        if ($cached !== null && isset($cached['auditThrottle']) && is_array($cached['auditThrottle'])) {
            $throttle = $cached['auditThrottle'];
        } else {
            $standalone = $this->cache->readStandaloneRefreshThrottle($extensionId);
            if ($standalone !== null) {
                $throttle = $standalone;
            }
        }
        $lastAtRaw = $throttle['refreshFailedAt'] ?? null;
        $lastClass = isset($throttle['refreshFailedClass']) ? (string) $throttle['refreshFailedClass'] : null;
        if ($lastAtRaw === null || !is_string($lastAtRaw) || $lastClass !== $failureClass) {
            return false;
        }
        $lastAt = strtotime($lastAtRaw);
        if ($lastAt === false) {
            return false;
        }

        return (time() - $lastAt) < LicenseAuditThrottlePolicy::refreshFailureCooldownSeconds($failureClass);
    }

    /**
     * @param array<string, mixed> $cached snapshot read at start of offline handling
     *                                     (before {@see LicenseCache::recordFailedAttempt}).
     */
    private function shouldSkipGraceEnteredAudit(array $cached): bool
    {
        $throttle = isset($cached['auditThrottle']) && is_array($cached['auditThrottle'])
            ? $cached['auditThrottle']
            : [];
        $lastAtRaw = $throttle['graceEnteredAt'] ?? null;
        if ($lastAtRaw === null || !is_string($lastAtRaw)) {
            return false;
        }
        $lastAt = strtotime($lastAtRaw);
        if ($lastAt === false) {
            return false;
        }

        return (time() - $lastAt) < LicenseAuditThrottlePolicy::graceEnteredCooldownSeconds();
    }

    /**
     * @param array<string, mixed>|null $cached
     */
    private function resolveActivationCode(string $extensionId, ?array $cached): ?string
    {
        if ($cached === null || !isset($cached['activationCodeEnc'])) {
            return null;
        }
        $enc = (string) $cached['activationCodeEnc'];
        if ($enc === '') {
            return null;
        }
        try {
            return ActivationCodeProtector::decrypt(
                $enc,
                $this->instanceBinder->localSaltValue(),
                $extensionId,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boundFingerprintFromPayload(array $payload): string
    {
        if (isset($payload['instance_fingerprint']) && $payload['instance_fingerprint'] !== '') {
            return (string) $payload['instance_fingerprint'];
        }
        if (isset($payload['instanceId']) && $payload['instanceId'] !== '') {
            return (string) $payload['instanceId'];
        }

        return '';
    }

    /**
     * Emit an audit event when an audit writer is wired (no-op otherwise).
     *
     * @param array<string, mixed> $context Free-form contextual data; sensitive
     *                                      keys are auto-masked by {@see LicenseAuditWriter}.
     */
    private function audit(LicenseAuditEvent $event, string $extensionId, array $context = []): void
    {
        if ($this->auditWriter === null) {
            return;
        }
        if ($this->domain !== null && $this->domain !== '') {
            $context['instanceDomain'] = $this->domain;
        }
        $this->auditWriter->record($event, $extensionId, $context);
    }
}
