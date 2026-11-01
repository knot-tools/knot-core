<?php

declare(strict_types=1);

namespace Knot;

/**
 * Single source of truth for the Knot module version string.
 *
 * Reads `$this->version` from the module descriptor of the tree this class is
 * loaded from (`<module>/core/modules/modKnot.class.php`), so admin/setup.php,
 * api/health.php, the Vue app and the trigger handler report the code that is
 * actually running. FALLBACK is only used when the descriptor is unreadable;
 * VersionConsistencyTest keeps it equal to the descriptor at every bump.
 */
final class Version
{
    public const FALLBACK = '2.13.27';

    public static function current(): string
    {
        return self::fromDescriptor(dirname(__DIR__) . '/core/modules/modKnot.class.php') ?? self::FALLBACK;
    }

    /**
     * Extract `$this->version = '…'` from a modKnot descriptor file.
     */
    public static function fromDescriptor(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false || preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
