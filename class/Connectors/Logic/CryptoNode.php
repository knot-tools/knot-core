<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'logic.crypto', category: 'logic')]
final class CryptoNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.crypto',
            'labelKey' => 'connectors.logic.crypto.label',
            'descriptionKey' => 'connectors.logic.crypto.description',
            'category' => 'logic',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.crypto.fields.operation.title',
                    'enum' => ['sha256', 'hmac_sha256', 'base64_encode', 'base64_decode', 'urlencode', 'uuidv4'],
                    'default' => 'sha256',
                    'x-position' => 0,
                ],
                'value' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.crypto.fields.value.title',
                    'descriptionKey' => 'connectors.logic.crypto.fields.value.description',
                    'x-position' => 1,
                ],
                'secret' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.crypto.fields.secret.title',
                    'descriptionKey' => 'connectors.logic.crypto.fields.secret.description',
                    'x-position' => 2,
                ],
            ],
        ];
    }
    public function getCredentialType(): ?string
    {
        return null;
    }
    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }
    public function getOutputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }
    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }
    public function execute(array $context): array
    {
        $config = is_array(($context['node']['config'] ?? null)) ? $context['node']['config'] : [];
        $value = (string) ($config['value'] ?? json_encode($context['json'] ?? []));
        $operation = (string) ($config['operation'] ?? 'sha256');
        $result = match ($operation) {
            'hmac_sha256' => hash_hmac('sha256', $value, (string) ($config['secret'] ?? '')),
            'base64_encode' => base64_encode($value),
            'base64_decode' => base64_decode($value, true) ?: '',
            'urlencode' => urlencode($value),
            'uuidv4' => self::uuidv4(),
            default => hash('sha256', $value),
        };
        return ['operation' => $operation, 'result' => $result];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
    private static function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
