<?php

declare(strict_types=1);

namespace Knot\Connectors;

/**
 * Contract implemented by every Knot connector.
 */
interface ConnectorInterface
{
    /**
     * Return connector metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Return JSON-schema-like configuration schema.
     *
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;

    /**
     * Return required credential type, or null when none is required.
     */
    public function getCredentialType(): ?string;

    /**
     * Return input port definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInputs(): array;

    /**
     * Return output port definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOutputs(): array;

    /**
     * Validate connector configuration.
     *
     * @param array<string, mixed> $config Connector configuration
     * @return array<string, mixed> Validation result
     */
    public function validate(array $config): array;

    /**
     * Execute connector.
     *
     * @param array<string, mixed> $context Execution context
     * @return array<string, mixed> Execution output
     */
    public function execute(array $context): array;

    /**
     * Test connector connectivity/configuration.
     *
     * @param array<string, mixed> $config Connector configuration
     * @return array<string, mixed> Test result
     */
    public function test(array $config): array;
}
