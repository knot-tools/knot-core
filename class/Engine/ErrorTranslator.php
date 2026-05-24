<?php

/* Copyright (C) 2026 Sébastien Audel (EXIATIS) — Knot Tools™ Core, GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Engine;

use Throwable;

final class ErrorTranslator
{
    /**
     * @return array<string, string>
     */
    public function translate(Throwable|string $error): array
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $lower = strtolower($message);

        if (str_contains($lower, 'permission') || str_contains($lower, 'forbidden')) {
            return $this->entry('permission_denied', $message);
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return $this->entry('timeout', $message);
        }
        if (str_contains($lower, 'not found') || str_contains($lower, 'inactive')) {
            return $this->entry('not_found', $message);
        }
        if (
            str_contains($lower, 'credential')
            || str_contains($lower, 'api key')
            || str_contains($lower, 'unauthorized')
        ) {
            return $this->entry('auth_failed', $message);
        }
        if (str_contains($lower, 'json') || str_contains($lower, 'expression')) {
            return $this->entry('expression_resolution_failed', $message);
        }

        return $this->entry('unknown_error', $message);
    }

    /**
     * @return array<string, string>
     */
    private function entry(string $code, string $technical): array
    {
        return [
            'code' => $code,
            'technicalMessage' => $technical,
            'messageKey' => 'errors.engine.' . $code . '.message',
            'suggestedFixKey' => 'errors.engine.' . $code . '.suggestedFix',
            'docLink' => 'docs/troubleshooting.md#' . $code,
        ];
    }
}
