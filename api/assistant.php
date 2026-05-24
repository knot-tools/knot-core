<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Connectors\ConnectorRegistry;

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
$catalog = [];
foreach ($registry->all() as $connector) {
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

$jsonFence = '```';
$jsonExample = json_encode([
    'knotExport' => '1.0',
    'workflow' => [
        'label' => 'Nom du workflow',
        'description' => 'Description',
        'definition' => [
            'schemaVersion' => '1.0',
            'workflow' => ['label' => 'Nom du workflow'],
            'nodes' => [],
            'edges' => [],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$prompt = "Tu es un expert Knot, module Dolibarr d'automatisation visuelle.\n"
    . "Objectif: aider l'utilisateur a construire un workflow JSON Knot valide, importable tel quel.\n\n"
    . "==== DEMANDE UTILISATEUR ====\n"
    . $userRequest . "\n"
    . "==== FIN DEMANDE ====\n\n"
    . "Regles strictes:\n"
    . "- Quand le workflow est pret, reponds par UN SEUL bloc de code Markdown delimite par "
    . $jsonFence . "json en ouverture et " . $jsonFence . " en fermeture, contenant exclusivement le JSON valide.\n"
    . "- Avant ce bloc final, tu peux poser des questions a l'utilisateur si une information manque, en texte libre.\n"
    . "- schemaVersion doit valoir \"1.0\".\n"
    . "- Chaque node a id (string unique), type (id de connecteur), label, subtitle, position {x,y}, config (objet).\n"
    . "- Chaque edge a id, source (id node), target (id node), sourceHandle, targetHandle.\n"
    . "- Utilise uniquement les types de connecteurs listes dans le catalogue ci-dessous.\n"
    . "- Toute reference a une donnee provenant d'un node precedent doit utiliser la syntaxe d'expression "
    . "{{\$json.<chemin>}} (ex: {{\$json.invoice.id}}).\n"
    . "- Pour parcourir une collection avec logic.loop, mets config.realIteration=true et config.itemsPath=\"{{\$json.items}}\". "
    . "Le sous-graphe d'iteration part du sourceHandle \"iteration\" ; la suite du workflow part du sourceHandle \"done\". "
    . "Dans le corps de la boucle, l'item courant est accessible via {{\$json}} et l'index via {{\$json._loopIndex}}.\n"
    . "- Pour gerer les erreurs d'un node, ajoute un edge avec sourceHandle=\"error\" vers un node de fallback (ex: communication.email pour alerter, logic.stop_and_error pour stopper).\n"
    . "- Pour appeler un autre workflow Knot, utilise subworkflow.run avec config.workflowRef et config.payload.\n"
    . "- Pour idempotence (eviter les doublons sur retries), pose config.idempotencyKey sur les nodes critiques (ex: action.stripe).\n"
    . "- Triggers Dolibarr disponibles : trigger.manual (manuel), trigger.cron (cron), trigger.webhook (HTTP), trigger.dolibarr_event (evenements natifs Dolibarr), trigger.stripe_webhook + trigger.shopify_webhook (HMAC verifie cote serveur).\n"
    . "- Connecteurs Dolibarr : dolibarr.object (CRUD generique sur tiers/facture/commande/etc.), dolibarr.read_object (lecture), dolibarr.specialized (operations metier comme valider une facture, generer un PDF, calculer un total).\n"
    . "- Pour gerer un consentement humain : human.approval pose une demande dans la file et bloque tant que personne n'a approuve.\n\n"
    . "Format minimal du JSON a renvoyer (a adapter):\n"
    . $jsonExample
    . "\n\nCatalogue connecteurs disponibles (JSON):\n"
    . json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

JsonResponse::success(['prompt' => $prompt, 'connectors' => $catalog, 'userRequest' => $userRequest]);
