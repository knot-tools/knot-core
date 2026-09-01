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

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Dolibarr\ObjectFactory;
use Knot\StateMachine\StateMachineEngine;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$slug = trim((string) ($_GET['slug'] ?? $_POST['slug'] ?? ''));
$engine = new StateMachineEngine();

try {
    switch ($action) {
        case 'states':
            if ($slug === '') {
                JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
                exit;
            }
            JsonResponse::success([
                'slug' => $slug,
                'states' => $engine->getStates($slug, $db),
            ]);
            exit;

        case 'transitions':
            if ($slug === '') {
                JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
                exit;
            }
            JsonResponse::success([
                'slug' => $slug,
                'transitions' => $engine->getTransitions($slug, $db),
            ]);
            exit;

        case 'probable_transitions':
            if ($slug === '') {
                JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
                exit;
            }
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                JsonResponse::error('missing_id', 'Query parameter "id" must be a positive integer.', 400);
                exit;
            }
            $factory = new ObjectFactory();
            $object = $factory->build($slug, $db);
            if (method_exists($object, 'fetch')) {
                $object->fetch($id);
            }
            JsonResponse::success([
                'slug' => $slug,
                'id' => (int) ($object->id ?? $id),
                'currentLogicalState' => $engine->getCurrentState($object, $engine->getStates($slug, $db)),
                'probableTransitions' => $engine->getProbableTransitions($object, $slug, $db),
            ]);
            exit;

        case 'current':
            if ($slug === '') {
                JsonResponse::error('missing_slug', 'Query parameter "slug" is required.', 400);
                exit;
            }
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                JsonResponse::error('missing_id', 'Query parameter "id" must be a positive integer.', 400);
                exit;
            }
            $factory = new ObjectFactory();
            $object = $factory->build($slug, $db);
            if (method_exists($object, 'fetch')) {
                $object->fetch($id);
            }
            $states = $engine->getStates($slug, $db);
            JsonResponse::success([
                'slug' => $slug,
                'id' => (int) ($object->id ?? $id),
                'states' => $states,
                'currentLogicalState' => $engine->getCurrentState($object, $states),
                'statusValue' => (new \Knot\StateMachine\StateExtractor())->readStatusValue($object),
            ]);
            exit;

        case 'transition':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                JsonResponse::error('method_not_allowed', 'Use POST for transition.', 405);
                exit;
            }
            if (!$user->hasRight('knot', 'workflow', 'execute')) {
                JsonResponse::error('permission_denied', 'workflow execute permission required', 403);
                exit;
            }
            if (!CsrfGuard::verify()) {
                JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
                exit;
            }
            if ($slug === '') {
                JsonResponse::error('missing_slug', 'POST field "slug" is required.', 400);
                exit;
            }
            $id = (int) ($_POST['id'] ?? 0);
            $method = trim((string) ($_POST['method'] ?? ''));
            if ($id <= 0 || $method === '') {
                JsonResponse::error('invalid_payload', 'POST fields "id" and "method" are required.', 400);
                exit;
            }
            $factory = new ObjectFactory();
            $object = $factory->build($slug, $db);
            if (method_exists($object, 'fetch')) {
                $object->fetch($id);
            }
            JsonResponse::success([
                'slug' => $slug,
                'id' => (int) ($object->id ?? $id),
                'result' => $engine->transition($object, $method, $user),
            ]);
            exit;

        default:
            JsonResponse::error(
                'unknown_action',
                'Unsupported action. Try states, transitions, probable_transitions, current, transition.',
                400
            );
            exit;
    }
} catch (\Throwable $e) {
    JsonResponse::error('state_machine_failed', $e->getMessage(), 500);
    exit;
}
