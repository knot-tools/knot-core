<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Api;

/**
 * Centralised auth helper for Knot internal HTTP endpoints.
 *
 * Every new V2.5+ endpoint MUST call {@see requireUser()} first
 * (replaces the ad-hoc `if (!is_object($user)) { ... exit }` boilerplate
 * scattered across `api/*.php`). Endpoints that mutate state additionally
 * call {@see requireRight()} and {@see requireCsrf()}.
 *
 * The helpers send a JSON envelope error response and call `exit;` when
 * the check fails — never returning to the caller — so they can be used
 * as one-line gates at the top of an endpoint script.
 */
final class ApiAuth
{
    /**
     * Ensure the request carries an authenticated Dolibarr session.
     *
     * Returns the resolved Dolibarr `User` instance (read from
     * `$GLOBALS['user']`, which `main.inc.php` populates after session
     * bootstrap). The return type is intentionally `object` (not the
     * global `\User` class) so static analyzers running outside
     * Dolibarr (no autoload of `User`) keep working — runtime
     * behaviour is unchanged.
     *
     * @return object Dolibarr `\User` instance
     */
    public static function requireUser(): object
    {
        $user = $GLOBALS['user'] ?? null;
        if (!is_object($user) || empty($user->id)) {
            JsonResponse::error('not_authenticated', 'Authentication required', 401);
            exit;
        }
        return $user;
    }

    /**
     * Install a global crash guard that turns any uncaught Throwable or
     * fatal error into a JSON envelope so the frontend never tries to
     * parse Dolibarr's HTML error page (`Knot API 200: invalid JSON
     * response`). Call this from API endpoints that consume optional
     * subsystems (Licensing bootstrap, ExtensionRegistry discovery,
     * etc.) where a missing dependency would otherwise crash the
     * request mid-flight.
     */
    public static function installCrashHandler(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            if (!headers_sent()) {
                @http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unhandled_exception',
                    'message' => $e->getMessage(),
                    'details' => [
                        'class' => get_class($e),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ],
                ],
                'meta' => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                return;
            }
            if (!headers_sent()) {
                @http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'fatal_error',
                    'message' => (string) $err['message'],
                    'details' => [
                        'file' => basename((string) $err['file']),
                        'line' => (int) $err['line'],
                    ],
                ],
                'meta' => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * Ensure the active user holds a Dolibarr right.
     *
     * Dolibarr's `User::hasRight()` is invoked with the configured
     * variant: `hasRight($module, $feature, $verb)` for granular rights
     * such as `knot.workflow.write`. `$verb` is optional to keep the
     * shape compatible with simpler `$module/$feature` permissions.
     */
    public static function requireRight(string $module, string $feature, string $verb = ''): void
    {
        $user = self::requireUser();
        $granted = $verb !== ''
            ? (bool) $user->hasRight($module, $feature, $verb)
            : (bool) $user->hasRight($module, $feature);
        if (!$granted) {
            JsonResponse::error('permission_denied', 'Permission denied', 403);
            exit;
        }
    }

    /**
     * Ensure the request carries a valid CSRF token. Use on any endpoint
     * that mutates state (POST/PUT/PATCH/DELETE).
     */
    public static function requireCsrf(): void
    {
        if (!CsrfGuard::verify()) {
            JsonResponse::error('csrf_invalid', 'CSRF token missing or invalid', 403);
            exit;
        }
    }
}
