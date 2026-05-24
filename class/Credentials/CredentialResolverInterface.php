<?php

declare(strict_types=1);

namespace Knot\Credentials;

/**
 * Resolves credentials for the workflow engine.
 *
 * Production implementation is {@see CredentialResolver}; tests can provide
 * lightweight fakes without touching the database / cipher.
 */
interface CredentialResolverInterface
{
    /**
     * Return the cleartext payload of a credential.
     *
     * Implementations MUST return:
     *  - `null` when the credential does not exist or cannot be decrypted,
     *  - an associative array with at least `secrets` (associative) when found.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(int $credentialId): ?array;
}
