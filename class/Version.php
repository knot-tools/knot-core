<?php

declare(strict_types=1);

namespace Knot;

/**
 * Single source of truth for the Knot module version string.
 *
 * Always read from \modKnot::$version when available so admin/setup.php,
 * api/health.php, the Vue app and the trigger handler stay aligned.
 */
final class Version
{
    public const FALLBACK = '2.13.4';

    public static function current(): string
    {
        if (class_exists('modKnot')) {
            try {
                $reflection = new \ReflectionClass('modKnot');
                $instance = $reflection->newInstanceWithoutConstructor();
                $value = (string) $instance->version;
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        if (defined('DOL_DOCUMENT_ROOT')) {
            $modulePath = DOL_DOCUMENT_ROOT . '/custom/knot/core/modules/modKnot.class.php';
            if (is_readable($modulePath)) {
                $contents = (string) file_get_contents($modulePath);
                if (preg_match('/\$this->version\s*=\s*\'([^\']+)\'/m', $contents, $matches)) {
                    return $matches[1];
                }
            }
        }

        return self::FALLBACK;
    }
}
