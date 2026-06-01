<?php

declare(strict_types=1);

namespace Knot\Tests\Connectors;

use Knot\Connectors\CredentialSchemaNormalizer;
use PHPUnit\Framework\TestCase;

final class CredentialSchemaNormalizerTest extends TestCase
{
    public function testPassthroughPropertiesFormat(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['apiKey'],
            'properties' => [
                'apiKey' => ['type' => 'string', 'title' => 'API key', 'secret' => true],
            ],
        ];

        $normalized = CredentialSchemaNormalizer::normalize($schema);

        self::assertSame($schema, $normalized);
    }

    public function testConvertsLegacyFieldsFormat(): void
    {
        $schema = [
            'type' => 'object',
            'label' => 'WhatsApp Cloud',
            'fields' => [
                [
                    'name' => 'phoneNumberId',
                    'label' => 'Phone number ID',
                    'type' => 'string',
                    'required' => true,
                ],
                [
                    'name' => 'accessToken',
                    'label' => 'System user access token',
                    'type' => 'string',
                    'secret' => true,
                    'required' => true,
                ],
            ],
        ];

        $normalized = CredentialSchemaNormalizer::normalize($schema);

        self::assertArrayHasKey('properties', $normalized);
        self::assertArrayNotHasKey('fields', $normalized);
        self::assertSame(['phoneNumberId', 'accessToken'], $normalized['required']);
        self::assertTrue($normalized['properties']['accessToken']['secret'] ?? false);
        self::assertSame('Phone number ID', $normalized['properties']['phoneNumberId']['title']);
    }

    public function testNormalizeNullReturnsNull(): void
    {
        self::assertNull(CredentialSchemaNormalizer::normalize(null));
    }
}
