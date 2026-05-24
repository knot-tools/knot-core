<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Contract for the Knot licence backend client.
 */
interface DolistoreClientContract
{
    /**
     * @param array{
     *     activationCode: string,
     *     instanceFingerprint: string,
     *     deploymentToken?: string,
     *     deploymentNonce?: string,
     * } $params
     * @return array{
     *     valid: bool,
     *     expiresAt: ?string,
     *     plan: ?string,
     *     issuedTo: ?string,
     *     signature: string,
     *     signedAt: string,
     *     payload: array<string, mixed>
     * }
     * @throws \RuntimeException When the call fails (network/HTTP).
     */
    public function check(array $params): array;
}
