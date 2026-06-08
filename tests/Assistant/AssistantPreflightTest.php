<?php

declare(strict_types=1);

namespace Knot\Tests\Assistant;

use Knot\Assistant\AssistantPreflight;
use PHPUnit\Framework\TestCase;

final class AssistantPreflightTest extends TestCase
{
    public function testEmailRequestDoesNotBlock(): void
    {
        $result = (new AssistantPreflight())->analyze(
            'envoyer un email au client quand la facture est validee',
            $this->descriptors()
        );

        self::assertFalse($result['blocked']);
        self::assertSame([], $result['missing']);
    }

    public function testWhatsAppRequestBlocksWhenUnavailable(): void
    {
        $result = (new AssistantPreflight())->analyze(
            'envoyer un message WhatsApp au client',
            $this->descriptors()
        );

        self::assertTrue($result['blocked']);
        self::assertNotEmpty($result['missing']);
        self::assertSame('action.whatsapp_cloud', $result['missing'][0]['id']);
    }

    public function testWebhookHttpDoesNotFalsePositive(): void
    {
        $result = (new AssistantPreflight())->analyze(
            'declencher sur webhook https://demo.example.com/hooks/knot/secret',
            $this->descriptors()
        );

        self::assertFalse($result['blocked']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function descriptors(): array
    {
        return [
            [
                'id' => 'action.email',
                'label' => 'Email',
                'available' => true,
                'extensionInfo' => ['license_status' => 'not_required'],
            ],
            [
                'id' => 'trigger.webhook',
                'label' => 'Webhook',
                'available' => true,
                'extensionInfo' => ['license_status' => 'not_required'],
            ],
            [
                'id' => 'action.whatsapp_cloud',
                'label' => 'WhatsApp Cloud',
                'available' => false,
                'extensionInfo' => [
                    'id' => 'knot-pro-pack',
                    'license_status' => 'missing',
                ],
            ],
        ];
    }
}
