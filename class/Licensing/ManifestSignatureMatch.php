<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Result of comparing an installed manifest digest to {@see OfficialManifestSignatures}.
 */
enum ManifestSignatureMatch: string
{
    /** Extension id is not in the official pin map. */
    case NOT_OFFICIAL = 'not_official';

    /** Official extension but digest missing or malformed. */
    case MISSING = 'missing';

    /** Matches the current primary pin. */
    case PRIMARY = 'primary';

    /** Matches a deprecated transition pin (still accepted). */
    case DEPRECATED = 'deprecated';

    /** Digest does not match any official pin (fork or unknown build). */
    case REJECTED = 'rejected';
}
