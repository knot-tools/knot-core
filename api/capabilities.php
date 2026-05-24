<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Capabilities\CapabilitiesBuilder;
use Knot\Extension\ExtensionRegistry;
use Knot\Licensing\Bootstrap;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;

if (!empty(GETPOST('refresh', 'alpha'))) {
    (new CapabilitiesBuilder($db, $conf, $entity))->invalidateCache();
}

$builder = new CapabilitiesBuilder($db, $conf, $entity);
$cached = $builder->loadCache();
if ($cached !== null) {
    JsonResponse::success(['capabilities' => $cached, 'cached' => true]);
    exit;
}

try {
    try {
        $extensions = Bootstrap::buildExtensionRegistry($db);
    } catch (\Throwable $e) {
        error_log('[knot capabilities] ExtensionRegistry bootstrap failed: ' . $e->getMessage());
        $extensions = new ExtensionRegistry();
    }
    $payload = $builder->build($extensions);
} catch (\Throwable $e) {
    error_log('[knot capabilities] build failed: ' . $e->getMessage());
    JsonResponse::error(
        'capabilities_build_failed',
        'Unable to build capability manifest.',
        500,
        ['detail' => $e->getMessage()]
    );
    exit;
}

try {
    $builder->saveCache($payload);
} catch (\Throwable $e) {
    error_log('[knot capabilities] saveCache failed: ' . $e->getMessage());
}

JsonResponse::success(['capabilities' => $payload, 'cached' => false]);
