<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * HTTPS-only artefact downloader for apply workflows.
 */
final class ZipDownloader
{
    /**
     * @param list<string> $extraHosts
     */
    public static function allowedHost(string $host, array $extraHosts = []): bool
    {
        $h = strtolower(trim($host));
        if ($h === '') {
            return false;
        }
        foreach ($extraHosts as $extra) {
            $e = strtolower(trim((string) $extra));
            if ($e !== '' && $h === $e) {
                return true;
            }
        }
        $whitelist = [
            'github.com',
            'objects.githubusercontent.com',
            'raw.githubusercontent.com',
            'license.knot.tools',
            'codeload.github.com',
        ];
        foreach ($whitelist as $ok) {
            if ($h === $ok || ($h !== $ok && str_ends_with($h, '.' . $ok))) {
                return true;
            }
        }

        return $h === 'localhost' || $h === '127.0.0.1';
    }

    /** @throws RuntimeException */
    public static function fetchTo(string $absoluteUrl, string $destinationTmpZip): void
    {
        $parsed = parse_url($absoluteUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new RuntimeException('Invalid download URL.');
        }
        $scheme = strtolower((string) $parsed['scheme']);
        $host = strtolower((string) $parsed['host']);
        if ($scheme !== 'https') {
            throw new RuntimeException('Only HTTPS artefact URLs are permitted.');
        }
        if (!self::allowedHost($host)) {
            throw new RuntimeException(sprintf('ZIP host `%s` is blocked by Knot apply policy.', $host));
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required.');
        }

        @unlink($destinationTmpZip);

        $sink = fopen($destinationTmpZip, 'w+b');
        if ($sink === false) {
            throw new RuntimeException('Unable to allocate temporary artefact.');
        }

        $ch = curl_init($absoluteUrl);
        if ($ch === false) {
            fclose($sink);
            throw new RuntimeException('Cannot initialise downloader.');
        }
        $curlOptions = [
            CURLOPT_FILE => $sink,
            CURLOPT_HEADER => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 11,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip, */*',
                'User-Agent: Knot-Core-ZipDownloader/' . \Knot\Version::current(),
            ],
        ];
        if (getenv('KNOT_TEST') === '1') {
            $testCa = getenv('KNOT_TEST_ZIP_CA');
            if (is_string($testCa) && $testCa !== '' && is_readable($testCa)) {
                $curlOptions[CURLOPT_CAINFO] = $testCa;
            }
        }
        curl_setopt_array($ch, $curlOptions);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($sink);

        if ($ok !== true || $errno !== 0 || $status < 200 || $status >= 300) {
            @unlink($destinationTmpZip);
            throw new RuntimeException(
                sprintf('ZIP download transport failed (%d / errno %s: %s).', $status, (string) $errno, $err),
            );
        }
        $size = (int) @filesize($destinationTmpZip);
        if (!is_readable($destinationTmpZip) || $size <= 512) {
            @unlink($destinationTmpZip);
            throw new RuntimeException('Downloaded artefact appears corrupt.');
        }
    }
}
