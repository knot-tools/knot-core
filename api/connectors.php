<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Connectors\ConnectorRegistry;
use Knot\Connectors\CredentialSchemaNormalizer;
use Knot\Extension\ExtensionRegistry;
use Knot\Licensing\Bootstrap;
use Knot\Marketplace\ConnectorPresentationMerger;
use Knot\Marketplace\MarketplaceConnectorPresentation;

JsonResponse::installFatalHandler();

ApiAuth::requireRight('knot', 'workflow', 'read');

$registry = new ConnectorRegistry();
$extensions = Bootstrap::buildExtensionRegistry($db);
$connectors = [];

$snippetBag = MarketplaceConnectorPresentation::resolveSnippetForConnectorsRequests($db);
$snippetLookup = ConnectorPresentationMerger::buildSnippetLookup($snippetBag['connectors']);

// Loaded extension connectors keyed by id, used to enrich each row below.
$loadedByExt = $extensions->loadedConnectors();

foreach ($registry->allWithExtensions($extensions) as $id => $connector) {
    $credentialType = $connector->getCredentialType();
    $extensionInfo = null;
    $source = ConnectorRegistry::SOURCE_CORE;
    $metadata = $connector->getMetadata();
    if (isset($loadedByExt[$id]) && !arrayHasCoreId($registry, $id)) {
        $source = ConnectorRegistry::SOURCE_EXTENSION_PREFIX . ($loadedByExt[$id]['extension']['id'] ?? '?');
        $extensionManifestId = (string) ($loadedByExt[$id]['extension']['id'] ?? '');
        $metadata = ConnectorPresentationMerger::enrichMetadata(
            $metadata,
            $snippetLookup,
            $extensionManifestId,
            $connector::class,
            $snippetBag['lang'],
        );
        $extensionInfo = [
            'id' => $loadedByExt[$id]['extension']['id'] ?? null,
            'label' => $loadedByExt[$id]['extension']['label'] ?? null,
            'version' => $loadedByExt[$id]['extension']['version'] ?? null,
            'category' => $loadedByExt[$id]['extension']['category'] ?? 'third-party',
            'license_status' => $loadedByExt[$id]['extension']['licenseInfo']['status'] ?? null,
            'license_expires_at' => $loadedByExt[$id]['extension']['licenseInfo']['expiresAt'] ?? null,
        ];
    }
    $connectors[] = [
        'metadata' => $metadata,
        'configSchema' => $connector->getConfigSchema(),
        'credentialType' => $credentialType,
        'credentialSchema' => CredentialSchemaNormalizer::normalize(
            is_callable([$connector, 'getCredentialSchema'])
                ? $connector->{'getCredentialSchema'}()
                : defaultCredentialSchema($credentialType),
        ),
        'inputs' => $connector->getInputs(),
        'outputs' => $connector->getOutputs(),
        'source' => $source,
        'extensionInfo' => $extensionInfo,
        'available' => true,
    ];
}

// Surface non-loaded extension connectors so the UI can render greyed-out
// entries with a CTA ("Renew license", "Reinstall add-on", etc.).
foreach ($extensions->discover() as $extension) {
    if (($extension['status'] ?? null) === ExtensionRegistry::STATUS_LOADED) {
        continue;
    }
    foreach ($extension['connectorIds'] ?? [] as $connectorClass) {
        $manifestIdVirt = (string) ($extension['id'] ?? '');
        $metaPartial = [
            'id' => 'ext:' . ($extension['id'] ?? '?') . ':' . $connectorClass,
            'label' => shortClassName($connectorClass),
            'category' => 'other',
            'description' => sprintf(
                'Provided by %s (%s)',
                (string) ($extension['label'] ?? $extension['id'] ?? '?'),
                (string) ($extension['status'] ?? 'unavailable')
            ),
        ];
        $metaPartial = ConnectorPresentationMerger::enrichMetadata(
            $metaPartial,
            $snippetLookup,
            $manifestIdVirt,
            (string) $connectorClass,
            $snippetBag['lang'],
        );
        $connectors[] = [
            'metadata' => $metaPartial,
            'configSchema' => null,
            'credentialType' => null,
            'credentialSchema' => null,
            'inputs' => [],
            'outputs' => [],
            'source' => ConnectorRegistry::SOURCE_EXTENSION_PREFIX . ($extension['id'] ?? '?'),
            'extensionInfo' => [
                'id' => $extension['id'] ?? null,
                'label' => $extension['label'] ?? null,
                'version' => $extension['version'] ?? null,
                'category' => $extension['category'] ?? 'third-party',
                'license_status' => $extension['licenseInfo']['status'] ?? null,
                'license_expires_at' => $extension['licenseInfo']['expiresAt'] ?? null,
                'license_error' => $extension['licenseInfo']['error'] ?? null,
                'status' => $extension['status'] ?? null,
                'error' => $extension['error'] ?? null,
            ],
            'available' => false,
        ];
    }
}

$extensionsPublic = [];
foreach ($extensions->discover() as $ext) {
    $extensionsPublic[] = [
        'id' => $ext['id'] ?? null,
        'label' => $ext['label'] ?? null,
        'version' => $ext['version'] ?? null,
        'author' => $ext['author'] ?? null,
        'category' => $ext['category'] ?? 'third-party',
        'status' => $ext['status'] ?? null,
        'error' => $ext['error'] ?? null,
        'license_status' => $ext['licenseInfo']['status'] ?? null,
        'license_expires_at' => $ext['licenseInfo']['expiresAt'] ?? null,
    ];
}

JsonResponse::success([
    'connectors' => $connectors,
    'palette' => $registry->paletteSections(),
    'extensions' => $extensionsPublic,
]);

function arrayHasCoreId(ConnectorRegistry $registry, string $id): bool
{
    static $coreIds = null;
    if ($coreIds === null) {
        $coreIds = array_keys($registry->all());
    }
    return in_array($id, $coreIds, true);
}

function shortClassName(string $fqcn): string
{
    $parts = explode('\\', $fqcn);
    return end($parts) ?: $fqcn;
}

/**
 * @return array<string, mixed>|null
 */
function defaultCredentialSchema(?string $credentialType): ?array
{
    if ($credentialType === null || $credentialType === '') {
        return null;
    }

    return match ($credentialType) {
        'openai_api_key', 'anthropic_api_key' => [
            'type' => 'object',
            'required' => ['apiKey'],
            'properties' => [
                'apiKey' => ['type' => 'string', 'title' => 'API key', 'secret' => true],
            ],
        ],
        'telegram_bot' => [
            'type' => 'object',
            'required' => ['botToken'],
            'properties' => [
                'botToken' => ['type' => 'string', 'title' => 'Bot token', 'secret' => true],
                'defaultChatId' => ['type' => 'string', 'title' => 'Default chat ID'],
            ],
        ],
        default => [
            'type' => 'object',
            'required' => ['apiKey'],
            'properties' => [
                'apiKey' => ['type' => 'string', 'title' => 'API key / token', 'secret' => true],
            ],
        ],
    };
}
