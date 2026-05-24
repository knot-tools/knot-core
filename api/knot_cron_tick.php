<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Engine\CronWorker;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'execute')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$payload = [];
if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
}

$maxRounds = (int) ($payload['rounds'] ?? $payload['maxRounds'] ?? 25);
$maxRounds = max(1, min(120, $maxRounds));

$worker = new CronWorker();
$totalProcessed = 0;
$roundsRun = 0;
for ($i = 0; $i < $maxRounds; $i++) {
    $n = $worker->run();
    $roundsRun++;
    if ($n <= 0) {
        break;
    }
    $totalProcessed += $n;
}

JsonResponse::success([
    'totalProcessed' => $totalProcessed,
    'roundsRun' => $roundsRun,
    'capRounds' => $maxRounds,
]);
