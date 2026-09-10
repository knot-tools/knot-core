<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

/**
 * Extracts a CHANGELOG.md section into client-facing release notes.
 *
 * Used at Core sign/publish time to fill {@code latest.notes} on
 * {@code releases.json}. Notes ride on {@code latest} next to the signed
 * artefact metadata so Updates can render them without a second fetch.
 * They are intentionally **not** part of the Ed25519
 * {@code signature_payload} ({@code channel}, {@code version},
 * {@code zip_sha256}, {@code zip_url}) so existing signatures stay stable.
 */
final class ReleaseNotesExtractor
{
    public const MAX_NOTES_CHARS = 16_000;

    /**
     * Extract {@code ## [X.Y.Z]} through the next {@code ## [} (or EOF).
     */
    public static function extract(string $changelogMarkdown, string $version): string
    {
        $version = self::normalizeVersion($version);
        if ($version === '') {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $changelogMarkdown);
        $lines = explode("\n", $text);
        $heading = '/^## \[' . preg_quote($version, '/') . '\](?:\s|$)/';

        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match($heading, $line) === 1) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return '';
        }

        $chunk = [];
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            if ($i > $start && preg_match('/^## \[/', $lines[$i]) === 1) {
                break;
            }
            $chunk[] = $lines[$i];
        }

        return self::sanitize(implode("\n", $chunk));
    }

    public static function extractFromFile(string $changelogPath, string $version): string
    {
        if (!is_readable($changelogPath)) {
            return '';
        }
        $raw = file_get_contents($changelogPath);
        if (!is_string($raw)) {
            return '';
        }

        return self::extract($raw, $version);
    }

    /**
     * Persist notes on {@code latest} without mutating {@code signature_payload}.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function injectIntoLatest(array $manifest, string $notes): array
    {
        $latestRaw = $manifest['latest'] ?? null;
        $latest = is_array($latestRaw) ? $latestRaw : [];
        $latest['notes'] = self::sanitize($notes);
        $manifest['latest'] = $latest;

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function injectFromChangelog(array $manifest, string $changelogMarkdown, string $version = ''): array
    {
        $latestRaw = $manifest['latest'] ?? null;
        $latest = is_array($latestRaw) ? $latestRaw : [];
        $effectiveVersion = $version !== '' ? $version : trim((string) ($latest['version'] ?? ''));

        return self::injectIntoLatest($manifest, self::extract($changelogMarkdown, $effectiveVersion));
    }

    public static function sanitize(string $notes): string
    {
        $notes = str_replace(["\r\n", "\r"], "\n", $notes);
        $notes = str_replace("\0", '', $notes);
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }
        if (mb_strlen($notes, 'UTF-8') > self::MAX_NOTES_CHARS) {
            $notes = rtrim(mb_substr($notes, 0, self::MAX_NOTES_CHARS, 'UTF-8')) . "\n…";
        }

        return $notes;
    }

    public static function normalizeNotes(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        return self::sanitize($raw);
    }

    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = trim(substr($version, 1));
        }

        return $version;
    }
}
