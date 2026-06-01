<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Assistant\WorkflowAssistantPromptBuilder;
use Knot\Connectors\ConnectorRegistry;
use Knot\Licensing\Bootstrap;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userRequest = '';
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            JsonResponse::error('validation_failed', 'Invalid JSON body', 400);
            exit;
        }
        $userRequest = trim(strip_tags((string) ($input['userRequest'] ?? '')));
    }
}
if ($userRequest === '') {
    $userRequest = trim((string) GETPOST('userRequest', 'restricthtml'));
}

$registry = new ConnectorRegistry();
$extensions = Bootstrap::buildExtensionRegistry($db);
$catalog = [];
foreach ($registry->allWithExtensions($extensions) as $connector) {
    $catalog[] = [
        'metadata' => $connector->getMetadata(),
        'configSchema' => $connector->getConfigSchema(),
        'credentialType' => $connector->getCredentialType(),
        'inputs' => $connector->getInputs(),
        'outputs' => $connector->getOutputs(),
    ];
}

if ($userRequest === '') {
    $userRequest = '<!-- Decris ici, en langage naturel, ce que tu veux que le workflow fasse. '
        . 'Exemple: "Quand une facture est validee dans Dolibarr, envoyer un message WhatsApp '
        . 'au client et creer une tache de relance si le montant depasse 1000 EUR." -->';
}

global $langs;

$doliDb = isset($db) && $db instanceof \DoliDB ? $db : null;
$prompt = (new WorkflowAssistantPromptBuilder())->build(
    $userRequest,
    $catalog,
    $doliDb,
    $langs ?? null
);

JsonResponse::success(['prompt' => $prompt, 'connectors' => $catalog, 'userRequest' => $userRequest]);
