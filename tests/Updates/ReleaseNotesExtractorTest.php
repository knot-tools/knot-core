<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\ReleaseNotesExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Updates\ReleaseNotesExtractor
 */
final class ReleaseNotesExtractorTest extends TestCase
{
    private const SAMPLE = <<<'MD'
# Changelog

## [Unreleased]

- pending

## [2.13.22] - 2026-09-07

### Added

- **Public Utility API** for extensions.

### Fixed

- ZIP hygiene.

## [2.13.21] - 2026-09-05

- older
MD;

    public function testExtractsExactSemverSectionUntilNextHeading(): void
    {
        $notes = ReleaseNotesExtractor::extract(self::SAMPLE, '2.13.22');

        self::assertStringContainsString('## [2.13.22] - 2026-09-07', $notes);
        self::assertStringContainsString('**Public Utility API**', $notes);
        self::assertStringContainsString('ZIP hygiene.', $notes);
        self::assertStringNotContainsString('## [2.13.21]', $notes);
        self::assertStringNotContainsString('Unreleased', $notes);
        self::assertStringNotContainsString('- older', $notes);
    }

    public function testExtractsUnreleasedAndStripsVPrefix(): void
    {
        $notes = ReleaseNotesExtractor::extract(self::SAMPLE, 'vUnreleased');

        self::assertStringContainsString('## [Unreleased]', $notes);
        self::assertStringContainsString('- pending', $notes);
        self::assertStringNotContainsString('2.13.22', $notes);
    }

    public function testDoesNotMatchPrefixVersion(): void
    {
        $notes = ReleaseNotesExtractor::extract(self::SAMPLE, '2.13.2');

        self::assertSame('', $notes);
    }

    public function testMissingVersionReturnsEmpty(): void
    {
        self::assertSame('', ReleaseNotesExtractor::extract(self::SAMPLE, '9.9.9'));
        self::assertSame('', ReleaseNotesExtractor::extract(self::SAMPLE, ''));
        self::assertSame('', ReleaseNotesExtractor::extract('', '2.13.22'));
    }

    public function testAcceptsWindowsNewlines(): void
    {
        $windows = str_replace("\n", "\r\n", self::SAMPLE);
        $notes = ReleaseNotesExtractor::extract($windows, '2.13.21');

        self::assertStringContainsString('## [2.13.21] - 2026-09-05', $notes);
        self::assertStringContainsString('- older', $notes);
        self::assertStringNotContainsString("\r", $notes);
    }

    public function testSanitizeTruncatesAndStripsNullBytes(): void
    {
        $long = str_repeat('a', ReleaseNotesExtractor::MAX_NOTES_CHARS + 50);
        $out = ReleaseNotesExtractor::sanitize("  \0" . $long . "  ");

        self::assertSame(ReleaseNotesExtractor::MAX_NOTES_CHARS + 2, mb_strlen($out, 'UTF-8'));
        self::assertStringEndsWith("\n…", $out);
        self::assertStringNotContainsString("\0", $out);
    }

    public function testNormalizeNotesRejectsNonStrings(): void
    {
        self::assertSame('', ReleaseNotesExtractor::normalizeNotes(null));
        self::assertSame('', ReleaseNotesExtractor::normalizeNotes(['x']));
        self::assertSame('hello', ReleaseNotesExtractor::normalizeNotes("  hello  "));
    }

    public function testInjectIntoLatestDoesNotTouchSignaturePayload(): void
    {
        $payload = [
            'channel' => 'beta',
            'version' => '2.13.22',
            'zip_sha256' => str_repeat('a', 64),
            'zip_url' => 'https://knot.tools/downloads/knot-core/latest',
        ];
        $manifest = [
            'schema_version' => 'knot-core-releases@v1',
            'latest' => [
                'version' => '2.13.22',
                'signature_payload' => $payload,
                'signature_hex' => 'deadbeef',
            ],
        ];

        $updated = ReleaseNotesExtractor::injectFromChangelog($manifest, self::SAMPLE, '2.13.22');

        self::assertSame($payload, $updated['latest']['signature_payload']);
        self::assertStringContainsString('## [2.13.22]', $updated['latest']['notes']);
        self::assertArrayNotHasKey('notes', $updated['latest']['signature_payload']);
    }

    public function testExtractFromShippedChangelogSection(): void
    {
        $path = dirname(__DIR__, 2) . '/CHANGELOG.md';
        self::assertFileExists($path);

        $notes = ReleaseNotesExtractor::extractFromFile($path, '2.13.22');

        self::assertNotSame('', $notes);
        self::assertStringContainsString('## [2.13.22]', $notes);
        self::assertStringContainsString('ObjectFactory', $notes);
        self::assertStringNotContainsString('## [2.13.21]', $notes);
        self::assertStringNotContainsString('## [Unreleased]', $notes);
    }

    public function testFixtureManifestHasClientFacingNotes(): void
    {
        $path = dirname(__DIR__) . '/fixtures/updates/releases-with-notes.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $notes = (string) ($decoded['latest']['notes'] ?? '');
        self::assertStringContainsString('## [2.13.99]', $notes);
        self::assertArrayNotHasKey('notes', $decoded['latest']['signature_payload']);
    }
}
