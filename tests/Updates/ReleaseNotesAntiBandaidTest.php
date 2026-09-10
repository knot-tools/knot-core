<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use PHPUnit\Framework\TestCase;

/**
 * Anti-bandaid: notes come from signed releases.json, not ZIP parse or a
 * GitHub Release link standing in as the Updates UX.
 */
final class ReleaseNotesAntiBandaidTest extends TestCase
{
    public function testNotifyAndUiPathsDoNotParseZipChangelogOrUseGithubReleaseLinkAsNotes(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/frontend/src/views/UpdatesView.vue');
        $api = (string) file_get_contents($root . '/api/updates.php');
        $checker = (string) file_get_contents($root . '/class/Updates/UpdateChecker.php');
        $github = (string) file_get_contents($root . '/class/Updates/GithubReleasesClient.php');
        $inject = (string) file_get_contents($root . '/scripts/release/inject_release_notes.php');

        self::assertStringNotContainsString('ZipArchive', $view);
        self::assertStringNotContainsString('CHANGELOG.md', $view);
        self::assertStringNotContainsString('github.com/knot-tools/knot-core/releases', $view);

        self::assertStringNotContainsString('ZipArchive', $api);
        self::assertStringNotContainsString('CHANGELOG.md', $api);

        self::assertStringNotContainsString('ZipArchive', $checker);
        self::assertStringNotContainsString('file_get_contents', $checker);

        self::assertStringContainsString("latest['notes']", $github);
        self::assertStringNotContainsString('ZipArchive', $github);

        self::assertStringContainsString('CHANGELOG.md', $inject);
        self::assertStringNotContainsString('ZipArchive', $inject);
        self::assertStringNotContainsString('zip://', $inject);
    }
}
