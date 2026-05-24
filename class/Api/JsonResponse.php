<?php

declare(strict_types=1);

namespace Knot\Api;

use Knot\Errors\KnotError;

/**
 * Small JSON response helper for internal APIs.
 */
final class JsonResponse
{
    /**
     * Send a success response.
     *
     * @param array<string, mixed> $data Response data
     */
    public static function success(array $data = [], int $status = 200): void
    {
        self::send(self::successEnvelope($data), $status);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function successEnvelope(array $data = []): array
    {
        return ['success' => true, 'data' => $data, 'error' => null, 'meta' => []];
    }

    /**
     * Send an error response.
     *
     * `$code` is the stable machine id (Vue: `errors.api.<code>`).
     * `error_code` duplicates `code` in the JSON envelope for clients that expect one field name.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, int $status = 400, array $details = []): void
    {
        self::send(self::errorEnvelope($code, $message, $details), $status);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public static function errorEnvelope(string $code, string $message, array $details = []): array
    {
        return [
            'success' => false,
            'data' => null,
            'error' => self::errorBody($code, $message, $details),
            'meta' => [],
        ];
    }

    /**
     * @param array<string, mixed> $details
     * @return array{code: string, error_code: string, message: string, details: array<string, mixed>}
     */
    public static function errorBody(string $code, string $message, array $details = []): array
    {
        return [
            'code' => $code,
            'error_code' => $code,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Emit a structured Knot domain error (see ADR-007).
     * Keeps the legacy `{ success, error.code, error.message }` envelope;
     * full Knot payload is under error.details.knot for forward-compatible clients.
     */
    public static function knotError(KnotError $err): void
    {
        self::send(self::knotErrorEnvelope($err), self::httpStatusForKnotError($err));
    }

    /**
     * @return array<string, mixed>
     */
    public static function knotErrorEnvelope(KnotError $err): array
    {
        return [
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $err->knotCode,
                'error_code' => $err->knotCode,
                'message' => $err->userMessage,
                'details' => [
                    'knot' => $err->toArray(),
                ],
            ],
            'meta' => [],
        ];
    }

    private static function httpStatusForKnotError(KnotError $err): int
    {
        if ($err instanceof \Knot\Errors\PermissionError || str_starts_with($err->knotCode, 'KNOT_PERM_')) {
            return 403;
        }
        if ($err instanceof \Knot\Errors\DolibarrRecordNotFoundError || str_starts_with($err->knotCode, 'KNOT_NOT_FOUND')) {
            return 404;
        }
        if (
            $err instanceof \Knot\Errors\ValidationError
            || str_starts_with($err->knotCode, 'KNOT_VALIDATION_')
            || str_starts_with($err->knotCode, 'KNOT_SCHEMA_')
        ) {
            return 422;
        }
        if ($err instanceof \Knot\Errors\RateLimitError || str_starts_with($err->knotCode, 'KNOT_RATE_')) {
            return 429;
        }
        if ($err instanceof \Knot\Errors\SystemError || str_starts_with($err->knotCode, 'KNOT_SYSTEM_')) {
            return 500;
        }

        return 400;
    }

    /**
     * Install a shutdown + exception safety net so a fatal error or an
     * uncaught throwable can never leak HTML in front of a JSON response.
     *
     * Call this once at the top of every `api/*.php` entry point, right
     * after `main.inc.php` is loaded. The handler:
     *   - clears pending output buffers,
     *   - sets a 500 status code,
     *   - emits a structured JSON error envelope with a stable error code,
     *   - never leaks the raw exception message to the client (logged
     *     server-side via PHP's standard error_log instead).
     *
     * Idempotent: subsequent calls are no-ops.
     */
    public static function installFatalHandler(): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }
        $installed = true;

        $emit = static function (string $code, string $message): void {
            if (headers_sent()) {
                return;
            }
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
            echo self::encodePayload([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => $code,
                    'error_code' => $code,
                    'message' => $message,
                    'details' => [],
                ],
                'meta' => [],
            ]);
        };

        set_exception_handler(static function (\Throwable $e) use ($emit): void {
            error_log(sprintf(
                '[knot api] uncaught %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $emit('unhandled_exception', 'An unexpected error occurred. See server logs.');
        });

        register_shutdown_function(static function () use ($emit): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR
                | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
            if (((int) $err['type'] & $fatalMask) === 0) {
                return;
            }
            error_log(sprintf(
                '[knot api] fatal error: %s in %s:%d',
                $err['message'],
                $err['file'],
                $err['line']
            ));
            $emit('fatal_error', 'A fatal server error occurred. See server logs.');
        });
    }

    /**
     * Send raw JSON payload.
     *
     * Purges any output buffer before sending headers so a stray PHP
     * notice or HTML emitted by Dolibarr's `main.inc.php` (e.g. when
     * a deprecated feature is used in a hook) cannot leak in front
     * of the JSON envelope. Without this, the SPA receives
     * `<br />\n<b>Notice...</b>{...}` and JSON.parse blows up at the
     * very first byte.
     *
     * @param array<string, mixed> $payload JSON payload
     */
    private static function send(array $payload, int $status): void
    {
        self::prepareResponse($status);
        echo self::encodePayload($payload);
    }

    /**
     * Set JSON response headers and status when output has not started yet.
     */
    public static function prepareResponse(int $status): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
}
