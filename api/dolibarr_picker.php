<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require '../../../main.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\JsonResponse;
use Knot\Dolibarr\ObjectFactory;
use Knot\Security\RateLimiter;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

// Picker hits the DB on every keystroke, so cap it per-user to keep
// the backend safe even with a runaway autocomplete.
$limiter = new RateLimiter($db);
$max = function_exists('getDolGlobalInt')
    ? max(0, (int) getDolGlobalInt('MAIN_KNOT_PICKER_RATE_LIMIT_PER_MIN'))
    : 240;
if ($max === 0) $max = 240;
if (!$limiter->consume('picker:user:' . (int) $user->id, $max)) {
    header('Retry-After: ' . $limiter->retryAfterSeconds());
    JsonResponse::error('rate_limited', 'Too many picker requests; slow down.', 429);
    exit;
}

$slug = (string) ($_GET['slug'] ?? '');
$query = trim((string) ($_GET['q'] ?? ''));
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));

if ($slug === '') {
    JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
    exit;
}

$factory = new ObjectFactory();
try {
    $object = $factory->build($slug, $db);
} catch (\Throwable $e) {
    JsonResponse::error('unsupported_slug', $e->getMessage(), 404);
    exit;
}

$element = isset($object->element) && is_string($object->element) ? $object->element : null;
$table = isset($object->table_element) && is_string($object->table_element) ? $object->table_element : $element;
if ($table === null || $table === '') {
    JsonResponse::error('no_table', 'Object has no table_element to pick from.', 400);
    exit;
}

$tablePrefix = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;

// Heuristic: identify the label column. Most Dolibarr objects expose
// a `ref` (canonical id) plus a human label in nom/label/title/lastname.
$candidatesLabel = ['nom', 'label', 'title', 'name', 'lastname', 'fullname'];
$labelCol = null;
$columnsRes = $db->query("SHOW COLUMNS FROM {$tablePrefix}{$table}");
$columns = [];
if ($columnsRes) {
    while ($row = $db->fetch_object($columnsRes)) {
        $columns[strtolower((string) $row->Field)] = true;
    }
}
foreach ($candidatesLabel as $col) {
    if (isset($columns[$col])) { $labelCol = $col; break; }
}
$hasRef = isset($columns['ref']);
$hasEntity = isset($columns['entity']);

$sql = 'SELECT t.rowid';
if ($hasRef) $sql .= ', t.ref';
if ($labelCol !== null) $sql .= ', t.' . $labelCol . ' AS label_value';
$sql .= " FROM {$tablePrefix}{$table} t";
$conds = [];
if ($hasEntity) {
    $conds[] = 't.entity = ' . $entity;
}
if ($query !== '') {
    $needle = '%' . $db->escape($query) . '%';
    $matchers = [];
    if ($hasRef) $matchers[] = "t.ref LIKE '$needle'";
    if ($labelCol !== null) $matchers[] = "t.$labelCol LIKE '$needle'";
    if (ctype_digit($query)) {
        $matchers[] = 't.rowid = ' . (int) $query;
    }
    if ($matchers !== []) {
        $conds[] = '(' . implode(' OR ', $matchers) . ')';
    }
}
if ($conds !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $conds);
}
$sql .= ' ORDER BY t.rowid DESC LIMIT ' . $limit;

$res = $db->query($sql);
if (!$res) {
    JsonResponse::error('query_failed', $db->lasterror(), 500);
    exit;
}

$results = [];
while ($row = $db->fetch_object($res)) {
    $results[] = [
        'id' => (int) $row->rowid,
        'ref' => $hasRef ? (string) $row->ref : (string) $row->rowid,
        'label' => $labelCol !== null
            ? (string) ($row->label_value ?? '')
            : ($hasRef ? (string) $row->ref : (string) $row->rowid),
    ];
}

header('Cache-Control: max-age=10, must-revalidate');
JsonResponse::success(['slug' => $slug, 'count' => count($results), 'results' => $results]);
