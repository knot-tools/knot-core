<?php

declare(strict_types=1);

namespace Knot\Errors;

use RuntimeException;

/**
 * Normalised Knot domain error (ADR-007).
 *
 * String codes use the KNOT_* prefix and are listed in docs/errors/catalog.md.
 */
class KnotError extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $knotCode,
        public readonly string $userMessage,
        string $technicalMessage,
        public readonly ?string $docLink = null,
        public readonly array $context = [],
        public readonly ?string $suggestion = null,
        public readonly string $severity = 'error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $userMessage, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->knotCode,
            'user_message' => $this->userMessage,
            'technical_message' => $this->getMessage(),
            'doc_link' => $this->docLink,
            'severity' => $this->severity,
            'suggestion' => $this->suggestion,
            'context' => $this->context,
        ];
    }
}
