<?php

declare(strict_types=1);

namespace Knot\Api;

/**
 * CSRF guard implementing defence-in-depth on top of Dolibarr's own
 * CSRF protection (which only kicks in when the global setting
 * `MAIN_SECURITY_CSRF_WITH_TOKEN` is enabled).
 *
 * The Vue frontend sends the token via the `X-Csrf-Token` header to
 * keep JSON request bodies clean, so we hoist that into `$_POST['token']`
 * before delegating to the standard Dolibarr lookup pattern:
 *
 *   1. Read the supplied token via `GETPOST('token', 'alpha')` (or fall
 *      back to `$_POST` / `$_GET` when Dolibarr helpers are unavailable
 *      e.g. in CLI tests).
 *   2. Compare it (with `hash_equals` to avoid timing oracles) against
 *      `$_SESSION['token']`, which `main.inc.php` rotates per request.
 *
 * Earlier versions delegated to `checkToken()`, but Dolibarr 21 inlined
 * that check directly into `main.inc.php` and removed the helper, which
 * made `function_exists('checkToken')` return `false` and the guard
 * silently no-op (every supplied token, including empty, was accepted).
 * This regression is the reason the strict pattern is now hand-rolled.
 */
final class CsrfGuard
{
    /**
     * Verify the request carries a valid CSRF token.
     *
     * Returns `false` when the token is missing, the session has no
     * stored token, or the supplied token does not match.
     */
    public static function verify(): bool
    {
        $headerToken = self::headerToken();
        if ($headerToken !== '' && empty($_POST['token']) && empty($_GET['token'])) {
            $_POST['token'] = $headerToken;
        }

        $supplied = self::suppliedToken();
        if ($supplied === '' || $supplied === 'notrequired') {
            return false;
        }

        $sessionToken = isset($_SESSION['token']) ? (string) $_SESSION['token'] : '';
        if ($sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $supplied);
    }

    private static function suppliedToken(): string
    {
        if (function_exists('GETPOST')) {
            return (string) GETPOST('token', 'alpha');
        }
        return (string) ($_POST['token'] ?? $_GET['token'] ?? '');
    }

    private static function headerToken(): string
    {
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'X-Csrf-Token') === 0) {
                    return (string) $value;
                }
            }
        }
        return '';
    }
}
