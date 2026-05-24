<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;

JsonResponse::installFatalHandler();
/**
 * V2.5 — galerie publique knot-templates.
 *
 * GET  /api/gallery.php           -> index (proxy https://raw.githubusercontent.com/knot/knot-templates/main/index.json)
 * GET  /api/gallery.php?slug=X    -> télécharge `<slug>.knot.json` depuis GitHub
 *
 * Ce endpoint est intentionnellement read-only et n'écrit JAMAIS dans
 * la base : il sert uniquement à proxifier le dépôt public knot-templates
 * pour contourner CORS et permettre au frontend de présenter la galerie
 * sans dépendre d'un domaine externe côté navigateur.
 *
 * Les workflows téléchargés sont retournés tels quels — l'import dans
 * Knot passe par /api/workflows.php standard avec validation complète.
 */

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$baseUrl = trim((string) (getDolGlobalString('KNOT_TEMPLATES_GALLERY_URL') ?: 'https://raw.githubusercontent.com/knot/knot-templates/main'));
$baseUrl = rtrim($baseUrl, '/');

$slug = (string) GETPOST('slug', 'alphanohtml');

if ($slug === '') {
    $url = $baseUrl . '/index.json';
    $payload = knot_gallery_fetch($url);
    if ($payload === null) {
        JsonResponse::error('gallery_unreachable', 'Cannot reach knot-templates gallery', 502);
        exit;
    }
    JsonResponse::success(['source' => $baseUrl, 'index' => $payload]);
    exit;
}

if (!preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#i', $slug)) {
    JsonResponse::error('invalid_slug', 'Slug must look like "category/name"', 400);
    exit;
}

$url = $baseUrl . '/' . $slug . '.knot.json';
$payload = knot_gallery_fetch($url);
if ($payload === null) {
    JsonResponse::error('template_not_found', 'Template not found in gallery', 404);
    exit;
}
JsonResponse::success(['slug' => $slug, 'workflow' => $payload]);
exit;

/**
 * Fetch a JSON document from GitHub Raw via cURL with a strict timeout.
 */
function knot_gallery_fetch(string $url): mixed
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Knot-Gallery/2.5',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $status < 200 || $status >= 300) {
        return null;
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}
