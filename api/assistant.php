<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Assistant\AssistantConnectorPromptCatalog;
use Knot\Assistant\AssistantPreflight;
use Knot\Assistant\AssistantTechnicalAnnex;
use Knot\Assistant\WorkflowAssistantPromptBuilder;
use Knot\Connectors\ConnectorRegistry;
use Knot\Licensing\Bootstrap;
use Knot\Migration\ConnectorMigration;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = 'prompt';
$userRequest = '';

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            JsonResponse::error('validation_failed', 'Invalid JSON body', 400);
            exit;
        }
        $action = trim((string) ($input['action'] ?? 'prompt'));
        $userRequest = trim(strip_tags((string) ($input['userRequest'] ?? '')));
    }
}
if ($userRequest === '') {
    $userRequest = trim((string) GETPOST('userRequest', 'restricthtml'));
}

$registry = new ConnectorRegistry();
$extensions = Bootstrap::buildExtensionRegistry($db);
$descriptors = $registry->describeAllForPalette($extensions, [], (string) ($langs->defaultlang ?? 'en'));

$loadedConnectors = $registry->allWithExtensions($extensions);
$catalogEntries = [];
foreach ($loadedConnectors as $connector) {
    $catalogEntries[] = [
        'metadata' => $connector->getMetadata(),
        'configSchema' => $connector->getConfigSchema(),
        'credentialType' => $connector->getCredentialType(),
        'inputs' => $connector->getInputs(),
        'outputs' => $connector->getOutputs(),
        'available' => true,
    ];
}

$availableIds = array_map(
    static fn ($c) => (string) ($c->getMetadata()['id'] ?? ''),
    $loadedConnectors
);

$preflight = (new AssistantPreflight())->analyze($userRequest, $descriptors);

if ($action === 'preflight') {
    JsonResponse::success([
        'preflight' => $preflight,
        'availableConnectorIds' => $availableIds,
        'userRequest' => $userRequest,
    ]);
    exit;
}

if ($preflight['blocked']) {
    JsonResponse::error(
        'assistant_preflight_blocked',
        'Required connector not available on this instance',
        422,
        ['preflight' => $preflight]
    );
    exit;
}

if ($userRequest === '') {
    $userRequest = '<!-- Decris ici, en langage naturel, ce que tu veux que le workflow fasse. '
        . 'Exemple: "Quand une facture est validee dans Dolibarr, envoyer un email au client." -->';
}

$compactAll = AssistantConnectorPromptCatalog::fromCatalogEntries($catalogEntries);
$coreIds = array_keys($registry->all());
$detectedIds = $preflight['detectedIds'] ?? [];
$relevantProIds = array_values(array_filter(
    $detectedIds,
    static fn (string $id): bool => ConnectorMigration::isMigrated($id) && in_array($id, $availableIds, true)
));

$tier1Rows = [];
$overflowRows = [];
foreach ($compactAll as $row) {
    $id = (string) ($row['id'] ?? '');
    if (in_array($id, $coreIds, true)) {
        $tier1Rows[] = $row;
        continue;
    }
    if (in_array($id, $relevantProIds, true)) {
        $tier1Rows[] = $row;
        continue;
    }
    $overflowRows[] = $row;
}

usort($tier1Rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

global $langs;
$doliDb = isset($db) && $db instanceof \DoliDB ? $db : null;
$locale = (string) ($langs->defaultlang ?? 'fr_FR');

$builder = new WorkflowAssistantPromptBuilder();
$prompt = $builder->build($userRequest, $tier1Rows, $doliDb, $langs ?? null, $locale);

$annex = '';
if (AssistantTechnicalAnnex::exceedsBudget($prompt) && $overflowRows !== []) {
    $annex = (new AssistantTechnicalAnnex())->build($overflowRows, $doliDb, $langs ?? null);
}

JsonResponse::success([
    'prompt' => $prompt,
    'annex' => $annex !== '' ? $annex : null,
    'connectors' => $catalogEntries,
    'availableConnectorIds' => $availableIds,
    'userRequest' => $userRequest,
    'preflight' => $preflight,
    'tokenEstimate' => AssistantTechnicalAnnex::estimateTokens($prompt),
]);
