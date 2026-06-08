<?php

declare(strict_types=1);

namespace Knot\Assistant;

use Knot\Migration\ConnectorMigration;

/**
 * Detects Pro Pack connectors required by a natural-language user request.
 *
 * Conservative keyword matching: blocks only on strong signals, never on
 * bare "http://" URLs (Core trigger.webhook remains valid).
 */
final class AssistantPreflight
{
    /**
     * @var array<string, list<string>> connector id => keyword stems (lowercase)
     */
    private const KEYWORD_MAP = [
        'action.whatsapp_cloud' => ['whatsapp', 'whatsapp cloud', 'meta business', 'phone number id'],
        'action.whatsapp_twilio' => ['whatsapp twilio'],
        'action.gmail' => ['gmail', 'google mail'],
        'action.slack' => ['slack'],
        'action.discord' => ['discord'],
        'action.ai_openai' => ['openai', 'chatgpt', 'gpt-4', 'gpt4'],
        'action.ai_anthropic' => ['anthropic', 'claude'],
        'action.ai_mistral' => ['mistral'],
        'action.ai_gemini' => ['gemini'],
        'action.ai_ollama' => ['ollama'],
        'action.stripe' => ['stripe payment', 'stripe charge', 'paiement stripe'],
        'action.shopify' => ['shopify'],
        'action.woocommerce' => ['woocommerce', 'woo commerce'],
        'action.prestashop' => ['prestashop'],
        'action.notion' => ['notion'],
        'action.airtable' => ['airtable'],
        'action.google_sheets' => ['google sheets', 'google sheet', 'feuille google'],
        'action.google_drive' => ['google drive'],
        'action.google_calendar' => ['google calendar', 'agenda google'],
        'action.github' => ['github'],
        'action.gitlab' => ['gitlab'],
        'action.twilio_sms' => ['twilio sms', 'sms twilio'],
        'action.ovh_sms' => ['ovh sms'],
        'action.telegram' => ['telegram'],
        'action.sftp' => ['sftp', 'ssh file'],
        'action.http' => [
            'connector http',
            'requete http externe',
            'requête http externe',
            'http request connector',
            'appel api externe',
            'rest api externe',
            'action.http',
        ],
        'trigger.stripe_webhook' => ['stripe webhook'],
        'trigger.shopify_webhook' => ['shopify webhook'],
        'notification.alert_fanout' => ['alert fanout', 'fanout notification', 'multi canal notification'],
    ];

    /**
     * @param list<array<string, mixed>> $connectorDescriptors same shape as connectors.php rows
     *
     * @return array{
     *   blocked: bool,
     *   missing: list<array{id: string, label: string, licenseStatus: string, extensionId: string|null}>,
     *   detectedIds: list<string>
     * }
     */
    public function analyze(string $userRequest, array $connectorDescriptors): array
    {
        $normalized = mb_strtolower(trim($userRequest));
        if ($normalized === '') {
            return ['blocked' => false, 'missing' => [], 'detectedIds' => []];
        }

        $availableById = [];
        $labelById = [];
        $licenseById = [];
        $extensionById = [];

        foreach ($connectorDescriptors as $row) {
            $id = (string) ($row['id'] ?? ($row['metadata']['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $availableById[$id] = (bool) ($row['available'] ?? true);
            $labelById[$id] = (string) ($row['label'] ?? $id);
            $extInfo = is_array($row['extensionInfo'] ?? null) ? $row['extensionInfo'] : [];
            $licenseById[$id] = (string) ($extInfo['license_status'] ?? 'not_required');
            $extensionById[$id] = isset($extInfo['id']) ? (string) $extInfo['id'] : null;
        }

        $detected = $this->detectConnectorIds($normalized);
        $missing = [];

        foreach ($detected as $connectorId) {
            if (!ConnectorMigration::isMigrated($connectorId)) {
                continue;
            }
            if (($availableById[$connectorId] ?? false) === true) {
                continue;
            }
            $missing[] = [
                'id' => $connectorId,
                'label' => $labelById[$connectorId] ?? $connectorId,
                'licenseStatus' => $licenseById[$connectorId] ?? 'missing',
                'extensionId' => $extensionById[$connectorId] ?? 'knot-pro-pack',
            ];
        }

        return [
            'blocked' => $missing !== [],
            'missing' => $missing,
            'detectedIds' => $detected,
        ];
    }

    /**
     * @return list<string>
     */
    private function detectConnectorIds(string $normalizedRequest): array
    {
        $found = [];

        foreach (self::KEYWORD_MAP as $connectorId => $keywords) {
            foreach ($keywords as $keyword) {
                if ($this->containsKeyword($normalizedRequest, $keyword)) {
                    $found[] = $connectorId;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function containsKeyword(string $haystack, string $keyword): bool
    {
        if ($keyword === 'http' || str_starts_with($keyword, 'http://') || str_starts_with($keyword, 'https://')) {
            return false;
        }

        return str_contains($haystack, mb_strtolower($keyword));
    }
}
