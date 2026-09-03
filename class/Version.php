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
    public const FALLBACK = '2.13.20';

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
        // Filesystem path via __DIR__ (Dolistore: no DOL_DOCUMENT_ROOT + custom module tree).
        $modulePath = dirname(__DIR__) . '/core/modules/modKnot.class.php';
        if (is_readable($modulePath)) {
            $contents = (string) file_get_contents($modulePath);
            if (preg_match('/\$this->version\s*=\s*\'([^\']+)\'/m', $contents, $matches)) {
                return $matches[1];
            }
        }

        return self::FALLBACK;
    }
}
