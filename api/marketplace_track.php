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
use Knot\Repository\AuditLogRepository;
use Knot\Security\RateLimiter;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

/**
 * POST /api/marketplace_track.php
 *
 * Fire-and-forget analytics for Knot Marketplace SPA (block CTAs,
 * banners, editorial surfaces). Persisted only in knot audit (`marketplace.track`).
 */

$activeUser = ApiAuth::requireUser();
ApiAuth::requireRight('knot', 'workflow', 'read');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

ApiAuth::requireCsrf();

$langs->load('knot@knot');
if (!KnotMarketplacePresentation::marketplaceUiEnabled()) {
    JsonResponse::error('marketplace_ui_disabled', $langs->trans('KnotMarketplaceUiDisabledApi'), 403);
    exit;
}

$limiter = new RateLimiter($db);
$bucket = 'marketplace_track:u:' . (int) ($activeUser->id ?? 0);
$maxPerMinute = 60;
if (!$limiter->consume($bucket, $maxPerMinute)) {
    header('Retry-After: ' . $limiter->retryAfterSeconds());
    JsonResponse::error('rate_limited', 'Too many Marketplace tracking requests; please slow down.', 429);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$payload = [];
if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') && $rawBody !== '') {
    /** @var mixed $decoded */
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
}

$event = strtolower(trim((string) ($payload['event'] ?? '')));
/** @var list<string> $allowed */
$allowed = [
    'cta_click',
    'template_instantiated',
    'product_page_visit',
    'news_visit',
    'banner_dismissed',
    'spotlight.click',
    'tab.change',
    'drawer.open',
    'search.query',
    'detail.scroll_depth',
];
if (!in_array($event, $allowed, true)) {
    JsonResponse::error('validation_failed', 'Unknown or missing `event` value.', 400);
    exit;
}

$contextRaw = $payload['context'] ?? null;
/** @var array<string, scalar> $safeContext */
$safeContext = [];
if (is_array($contextRaw)) {
    foreach ($contextRaw as $key => $val) {
        if (!is_string($key) || $key === '' || preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $key) !== 1) {
            continue;
        }
        if ($val === null) {
            $safeContext[$key] = '';

            continue;
        }
        if (is_bool($val) || is_int($val) || is_float($val)) {
            $safeContext[$key] = $val;

            continue;
        }
        if (is_string($val)) {
            $safeContext[$key] = substr($val, 0, 256);
        }
    }
}

$data = [
    'event' => $event,
    'context' => $safeContext,
];
$encodedSample = json_encode($data + ['entity' => (int) $conf->entity], JSON_UNESCAPED_SLASHES);
if ($encodedSample !== false && strlen($encodedSample) > 3800) {
    JsonResponse::error('payload_too_large', 'Tracking payload exceeds maximum size.', 413);
    exit;
}

$audit = new AuditLogRepository($db);
$audit->record(
    'marketplace.track',
    'marketplace',
    null,
    (int) ($activeUser->id ?? 0),
    [
        'event' => $event,
        'context' => $safeContext,
    ],
    (int) $conf->entity,
);

JsonResponse::success(['ok' => true]);
