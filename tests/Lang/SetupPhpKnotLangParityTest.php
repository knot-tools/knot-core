<?php

declare(strict_types=1);

namespace Knot\Tests\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Ensures every Translate key referenced from admin/setup.php exists in en_US and fr_FR knot.lang files.
 */
final class SetupPhpKnotLangParityTest extends TestCase
{
    public function testEverySetupPhpTransKeyExistsInEnglishAndFrench(): void
    {
        $root = dirname(__DIR__, 2);
        $setupPath = $root . '/admin/setup.php';
        self::assertFileExists($setupPath);
        $php = (string) file_get_contents($setupPath);

        $keys = [];

        if (preg_match_all('/\$langs->trans\(\s*\'([A-Za-z0-9_]+)\'/', $php, $m)) {
            foreach ($m[1] as $k) {
                $keys[$k] = true;
            }
        }
        if (
            preg_match_all(
                '/\$langs->trans\(\s*\$[a-zA-Z_]+\s*\?\s*\'([A-Za-z0-9_]+)\'\s*:\s*\'([A-Za-z0-9_]+)\'/',
                $php,
                $m2
            )
        ) {
            foreach (array_merge($m2[1], $m2[2]) as $k) {
                $keys[$k] = true;
            }
        }
        if (str_contains($php, '$healthBadgeKey')) {
            $keys['KnotSetupHealthBadgeHealthy'] = true;
            $keys['KnotSetupHealthBadgeIssues'] = true;
        }

        self::assertGreaterThanOrEqual(
            5,
            count($keys),
            'Expected several $langs->trans() literals in admin/setup.php'
        );

        $en = self::parseKnotLangKeys($root . '/langs/en_US/knot.lang');
        $fr = self::parseKnotLangKeys($root . '/langs/fr_FR/knot.lang');

        foreach (array_keys($keys) as $key) {
            self::assertArrayHasKey(
                $key,
                $en,
                'Missing langs/en_US/knot.lang key ' . $key . ' referenced from admin/setup.php'
            );
            self::assertArrayHasKey(
                $key,
                $fr,
                'Missing langs/fr_FR/knot.lang key ' . $key . ' referenced from admin/setup.php'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseKnotLangKeys(string $path): array
    {
        self::assertFileExists($path);
        $keys = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k] = explode('=', $line, 2);
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $keys[$k] = '1';
        }

        return $keys;
    }
}
