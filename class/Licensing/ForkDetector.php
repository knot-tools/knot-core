<?php

declare(strict_types=1);

namespace Knot\Licensing;

/**
 * Detects extensions that try to impersonate an official Knot pack.
 *
 * If an installed extension declares a manifest `id` that matches an
 * official Knot pack (e.g. `knot-pro-pack`, `knot-enterprise`) but
 * the manifest signature does not match the one published in the
 * official extension index, the verdict becomes `tampered`, the
 * extension is disabled, and an audit-log entry is written.
 *
 * The official index is shipped embedded in Knot Core (so a network
 * outage never gives the attacker a free pass) and refreshed via the
 * Dolistore licence backend at the same cadence as licences.
 */
final class ForkDetector
{
    /** @var array<string, list<non-empty-string>> */
    private array $officialManifestSignatures;

    /**
     * @param array<string, string|list<string>> $officialManifestSignatures
     */
    public function __construct(array $officialManifestSignatures)
    {
        $this->officialManifestSignatures = self::normalizeMap($officialManifestSignatures);
    }

    /**
     * Detect whether an installed extension is a fork of an official pack.
     *
     * @param string $extensionId       The `id` declared in the installed manifest.
     * @param string $manifestSignature The Ed25519 signature included in the installed manifest.
     */
    public function isFork(string $extensionId, string $manifestSignature): bool
    {
        return $this->classify($extensionId, $manifestSignature) === ManifestSignatureMatch::REJECTED;
    }

    /** Whether {@see $extensionId} has an official pinned manifest digest in Core. */
    public function expectsPinnedManifestSignature(string $extensionId): bool
    {
        return isset($this->officialManifestSignatures[$extensionId])
            && $this->officialManifestSignatures[$extensionId] !== [];
    }

    /**
     * Classify an installed digest against the official pin list.
     */
    public function classify(string $extensionId, string $manifestSignature): ManifestSignatureMatch
    {
        if (!isset($this->officialManifestSignatures[$extensionId])) {
            return ManifestSignatureMatch::NOT_OFFICIAL;
        }

        $installed = strtolower(trim($manifestSignature));
        if ($installed === '' || !preg_match('/^[a-f0-9]{128}$/', $installed)) {
            return ManifestSignatureMatch::MISSING;
        }

        $accepted = $this->officialManifestSignatures[$extensionId];
        if (hash_equals($accepted[0], $installed)) {
            return ManifestSignatureMatch::PRIMARY;
        }

        foreach ($accepted as $index => $digest) {
            if ($index === 0) {
                continue;
            }
            if (hash_equals($digest, $installed)) {
                return ManifestSignatureMatch::DEPRECATED;
            }
        }

        return ManifestSignatureMatch::REJECTED;
    }

    /**
     * Return the list of `extensionId`s that Knot considers official.
     *
     * @return array<int, string>
     */
    public function officialIds(): array
    {
        return array_keys($this->officialManifestSignatures);
    }

    /**
     * @param array<string, string|list<string>> $map
     *
     * @return array<string, list<non-empty-string>>
     */
    private static function normalizeMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $extensionId => $digests) {
            if (is_string($digests)) {
                $trimmed = strtolower(trim($digests));
                $normalized[$extensionId] = $trimmed !== '' ? [$trimmed] : [];
                continue;
            }

            $list = [];
            foreach ($digests as $digest) {
                $trimmed = strtolower(trim((string) $digest));
                if ($trimmed !== '' && !in_array($trimmed, $list, true)) {
                    $list[] = $trimmed;
                }
            }
            $normalized[$extensionId] = $list;
        }

        return $normalized;
    }
}
