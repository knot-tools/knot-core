<?php

declare(strict_types=1);

namespace Knot\Errors;

use Throwable;

/**
 * Maps Dolibarr / PHP throwables to KnotError instances using message heuristics.
 * Prefer explicit KnotError throws in new code; this class improves UX for legacy paths.
 */
final class DolibarrErrorTranslator
{
    private function docLink(string $knotCode): string
    {
        return 'https://knot.tools/docs/errors/catalog#' . strtolower(str_replace('_', '-', $knotCode));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function translate(Throwable $throwable, array $context = []): KnotError
    {
        if ($throwable instanceof KnotError) {
            return $throwable;
        }

        $msg = $throwable->getMessage();
        $lower = strtolower($msg);

        $baseContext = array_merge(['original_class' => $throwable::class], $context);

        if (str_contains($lower, 'permission') || str_contains($lower, 'access denied')) {
            return new DolibarrPermissionError(
                'KNOT_PERM_DOLIBARR_DENIED',
                'Your Dolibarr profile does not allow this operation.',
                $msg,
                $this->docLink('KNOT_PERM_DOLIBARR_DENIED'),
                $baseContext,
                'Ask an administrator to grant the relevant module permissions.',
                'warning',
                $throwable
            );
        }

        if (str_contains($lower, 'not found') || str_contains($lower, 'introuvable')) {
            return new DolibarrRecordNotFoundError(
                'KNOT_DOLIBARR_RECORD_NOT_FOUND',
                'The requested Dolibarr record was not found.',
                $msg,
                $this->docLink('KNOT_DOLIBARR_RECORD_NOT_FOUND'),
                $baseContext,
                'Verify the object id and that the record still exists.',
                'warning',
                $throwable
            );
        }

        if (
            str_contains($lower, 'foreign key')
            || str_contains($lower, 'duplicate entry')
            || str_contains($lower, 'integrity constraint')
        ) {
            return new DolibarrIntegrityError(
                'KNOT_DOLIBARR_INTEGRITY',
                'Dolibarr rejected the change because related data is inconsistent.',
                $msg,
                $this->docLink('KNOT_DOLIBARR_INTEGRITY'),
                $baseContext,
                'Check linked objects (third party, lines, stock) before retrying.',
                'error',
                $throwable
            );
        }

        if (str_contains($lower, 'already exists') || str_contains($lower, 'déjà')) {
            return new ConflictingStateError(
                'KNOT_STATE_ALREADY_EXISTS',
                'This record already exists or is duplicated.',
                $msg,
                $this->docLink('KNOT_STATE_ALREADY_EXISTS'),
                $baseContext,
                'Use fetch/update instead of create, or choose a unique reference.',
                'warning',
                $throwable
            );
        }

        if (str_contains($lower, 'invalid status') || str_contains($lower, 'bad status')) {
            return new InvalidTransitionError(
                'KNOT_STATE_INVALID_TRANSITION',
                'This operation is not allowed for the current document status.',
                $msg,
                $this->docLink('KNOT_STATE_INVALID_TRANSITION'),
                $baseContext,
                'Adjust the workflow so the previous step reaches a compatible status.',
                'warning',
                $throwable
            );
        }

        if (str_contains($lower, 'module') && str_contains($lower, 'not activated')) {
            return new ModuleNotActivatedError(
                'KNOT_MODULE_NOT_ACTIVATED',
                'The required Dolibarr module is not enabled on this instance.',
                $msg,
                $this->docLink('KNOT_MODULE_NOT_ACTIVATED'),
                $baseContext,
                'Enable the module in Home ▸ Setup ▸ Modules.',
                'warning',
                $throwable
            );
        }

        if (
            str_contains($lower, 'required field')
            || (str_contains($lower, 'field') && str_contains($lower, 'required'))
        ) {
            return new MissingFieldError(
                'KNOT_VALIDATION_MISSING_FIELD',
                'A mandatory field is missing for this operation.',
                $msg,
                $this->docLink('KNOT_VALIDATION_MISSING_FIELD'),
                $baseContext,
                'Review the node payload against the object schema.',
                'warning',
                $throwable
            );
        }

        if (str_contains($lower, 'is not a valid') || str_contains($lower, 'invalid value')) {
            return new InvalidValueError(
                'KNOT_VALIDATION_INVALID_VALUE',
                'One or more values are not accepted by Dolibarr.',
                $msg,
                $this->docLink('KNOT_VALIDATION_INVALID_VALUE'),
                $baseContext,
                'Check enums, dates, and numeric formats in your workflow.',
                'warning',
                $throwable
            );
        }

        return new DolibarrInternalError(
            'KNOT_DOLIBARR_UNEXPECTED',
            'Dolibarr returned an error while processing this step.',
            $msg,
            $this->docLink('KNOT_DOLIBARR_UNEXPECTED'),
            $baseContext,
            'Inspect execution logs and Dolibarr logs for more detail.',
            'error',
            $throwable
        );
    }
}
