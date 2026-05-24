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
 * Bundled offline workflow templates shipped under `data/templates/`.
 *
 * GET /api/bundled_templates.php           — manifest (from index.json)
 * GET /api/bundled_templates.php?slug=…    — one template (definition JSON + metadata)
 */

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

/**
 * @return non-empty-string
 */
function knot_bundled_templates_root(): string
{
    return dirname(__DIR__) . '/data/templates';
}

/** @return array{version?: string, templates: array<int, array<string, mixed>>}|null */
function knot_bundled_load_manifest(string $root): ?array
{
    $path = $root . '/index.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['templates']) || !is_array($data['templates'])) {
        return null;
    }

    return $data;
}

$root = knot_bundled_templates_root();
$manifest = knot_bundled_load_manifest($root);

if ($manifest === null) {
    JsonResponse::success([
        'templates' => [],
        'meta' => ['source' => 'bundled', 'empty' => true],
    ]);
    exit;
}

$slug = trim((string) GETPOST('slug', 'alphanohtml'));

if ($slug === '') {
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $manifest['templates'];
    $public = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $s = (string) ($row['slug'] ?? '');
        if ($s === '') {
            continue;
        }
        $public[] = [
            'slug' => $s,
            'title' => (string) ($row['title'] ?? $s),
            'description' => (string) ($row['description'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
            'difficulty' => (string) ($row['difficulty'] ?? 'beginner'),
            'tier' => (string) ($row['tier'] ?? 'free'),
            'modulesRequired' => is_array($row['modulesRequired'] ?? null) ? $row['modulesRequired'] : [],
            'demoInvalid' => !empty($row['demoInvalid']),
        ];
    }
    JsonResponse::success([
        'templates' => $public,
        'meta' => [
            'source' => 'bundled',
            'version' => (string) ($manifest['version'] ?? '1'),
        ],
    ]);
    exit;
}

$fileRel = null;
$meta = null;
foreach ($manifest['templates'] as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['slug'] ?? '') !== $slug) {
        continue;
    }
    $fileRel = (string) ($row['file'] ?? '');
    $meta = $row;
    break;
}

if ($meta === null || $fileRel === '' || !preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_.-]*\\.json$#', $fileRel)) {
    JsonResponse::error('not_found', 'Bundled template not found', 404);
    exit;
}

$fullPath = realpath($root . '/' . $fileRel);
$rootReal = realpath($root);
if ($fullPath === false || $rootReal === false || !str_starts_with($fullPath, $rootReal . DIRECTORY_SEPARATOR)) {
    JsonResponse::error('invalid_template_path', 'Bundled template path is invalid', 500);
    exit;
}

$rawDef = file_get_contents($fullPath);
if ($rawDef === false) {
    JsonResponse::error('template_unreadable', 'Unable to read bundled template', 500);
    exit;
}

$definition = json_decode($rawDef, true);
if (!is_array($definition)) {
    JsonResponse::error('invalid_template_json', 'Bundled template JSON is invalid', 500);
    exit;
}

JsonResponse::success([
    'slug' => $slug,
    'meta' => [
        'title' => (string) ($meta['title'] ?? $slug),
        'description' => (string) ($meta['description'] ?? ''),
        'category' => (string) ($meta['category'] ?? 'general'),
        'difficulty' => (string) ($meta['difficulty'] ?? 'beginner'),
        'tier' => (string) ($meta['tier'] ?? 'free'),
        'demoInvalid' => !empty($meta['demoInvalid']),
    ],
    'knotExport' => '1.0',
    'exportedAt' => gmdate('c'),
    'workflow' => [
        'label' => (string) ($meta['title'] ?? $slug),
        'description' => (string) ($meta['description'] ?? ''),
        'definition' => $definition,
    ],
]);
exit;
