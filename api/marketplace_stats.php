<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\JsonResponse;
use Knot\Marketplace\KnotMarketplacePresentation;
use Knot\Marketplace\MarketplaceStatsReader;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

ApiAuth::requireRight('knot', 'admin', 'configure');

$langs->load('knot@knot');
if (!KnotMarketplacePresentation::marketplaceUiEnabled()) {
    JsonResponse::error('marketplace_ui_disabled', $langs->trans('KnotMarketplaceUiDisabledApi'), 403);
    exit;
}

$entity = (int) $conf->entity;
$daysRaw = GETPOST('days', 'alphanohtml');
$days = ($daysRaw !== '' && $daysRaw !== null && is_numeric($daysRaw))
    ? max(1, min(366, (int) $daysRaw))
    : 30;
$limitRaw = GETPOST('limit', 'alphanohtml');
$limit = ($limitRaw !== '' && $limitRaw !== null && is_numeric($limitRaw))
    ? max(1, min(50, (int) $limitRaw))
    : 8;

$reader = new MarketplaceStatsReader($db);
JsonResponse::success([
    'windowDays' => $days,
    'topCtaClicks' => $reader->topCtaClickKeys($entity, $days, $limit),
]);
