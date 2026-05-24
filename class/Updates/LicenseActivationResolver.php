<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\LicenseCache;

/**
 * Resolves the cleartext activation code Knot already stores encrypted per extension.
 */
final class LicenseActivationResolver
{
    public static function cleartextActivationForExtension(
        \DoliDB $db,
        string $extensionId,
        ?LicenseCache $cache = null,
    ): ?string {
        $id = trim(strtolower($extensionId));
        if ($id === '') {
            return null;
        }

        try {
            $cache ??= new LicenseCache();
            $cached = $cache->read($id);
            if (
                $cached === null
                || !isset($cached['activationCodeEnc'])
                || $cached['activationCodeEnc'] === ''
            ) {
                return null;
            }

            return ActivationCodeProtector::decrypt(
                $cached['activationCodeEnc'],
                Bootstrap::localSalt($db),
                $id,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
