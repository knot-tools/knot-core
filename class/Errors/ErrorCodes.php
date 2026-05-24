<?php

declare(strict_types=1);

namespace Knot\Errors;

/**
 * Canonical Knot error codes documented in docs/errors/catalog.md.
 *
 * @internal Used by tests to guard catalogue drift.
 */
final class ErrorCodes
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'KNOT_CRITICAL_SYSTEM',
            'KNOT_DATABASE_CONNECTION',
            'KNOT_DEPENDENCY_FAILED',
            'KNOT_DISK_FULL',
            'KNOT_DOLIBARR_INTEGRITY',
            'KNOT_DOLIBARR_RECORD_NOT_FOUND',
            'KNOT_DOLIBARR_UNEXPECTED',
            'KNOT_EXECUTION_FAILED',
            'KNOT_EXECUTION_LOOP',
            'KNOT_EXECUTION_TIMEOUT',
            'KNOT_INVALID_CONFIG',
            'KNOT_INVALID_TYPE',
            'KNOT_MODULE_NOT_ACTIVATED',
            'KNOT_PERM_DOLIBARR_DENIED',
            'KNOT_PERM_KNOT_DENIED',
            'KNOT_PRECONDITION_FAILED',
            'KNOT_RATE_LIMITED',
            'KNOT_SCHEMA_WORKFLOW_INVALID',
            'KNOT_STATE_ALREADY_EXISTS',
            'KNOT_STATE_INVALID_TRANSITION',
            'KNOT_SYSTEM_UNEXPECTED',
            'KNOT_VALIDATION_INVALID_VALUE',
            'KNOT_VALIDATION_MISSING_FIELD',
            'KNOT_WEBHOOK_INVALID_PAYLOAD',
            'KNOT_CREDENTIAL_MISSING',
            'KNOT_CREDENTIAL_INVALID',
            'KNOT_QUEUE_OVERFLOW',
            'KNOT_QUEUE_CONFLICT',
            'KNOT_OBJECT_UNSUPPORTED',
            'KNOT_OBJECT_ID_REQUIRED',
            'KNOT_USER_REQUIRED',
            'KNOT_JSON_INVALID',
            'KNOT_IMPORT_PARTIAL',
            'KNOT_TEMPLATE_UNAVAILABLE',
            'KNOT_LICENSE_REQUIRED',
            'KNOT_HTTP_CLIENT_ERROR',
            'KNOT_HTTP_SERVER_ERROR',
            'KNOT_SANDBOX_VIOLATION',
            'KNOT_APPROVAL_REQUIRED',
            'KNOT_APPROVAL_REJECTED',
            'KNOT_SCHEDULE_INVALID_CRON',
            'KNOT_VARIABLE_UNDEFINED',
            'KNOT_EXPRESSION_EVAL_FAILED',
            'KNOT_CONNECTOR_DISABLED',
            'KNOT_IDEMPOTENCY_CONFLICT',
            'KNOT_WORKFLOW_INACTIVE',
            'KNOT_WORKFLOW_NOT_FOUND',
            'KNOT_SINGLE_INSTANCE_BLOCKED',
            'KNOT_CAPABILITIES_UNAVAILABLE',
            'KNOT_GROUND_TRUTH_MISMATCH',
            'KNOT_MIGRATION_BLOCKED',
        ];
    }
}
