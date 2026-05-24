<?php

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Optional contract for connectors that need a credential.
 *
 * The schema describes the expected secrets/fields so the Credentials UI
 * can render a typed form. When this interface is not implemented, the
 * generic schema in `api/credentials.php#defaultCredentialSchema()` applies.
 */
interface CredentialAware
{
    /**
     * Return the JSON-schema-like description of secrets stored alongside
     * the credential. Required fields go in the top-level `required` array.
     *
     * Each property MAY include:
     *  - `secret: true` to flag values that must be masked in the UI/logs,
     *  - `title`, `description`,
     *  - `type` (string|integer|boolean),
     *  - `default`.
     *
     * @return array<string, mixed>
     */
    public function getCredentialSchema(): array;
}
