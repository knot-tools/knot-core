<?php

declare(strict_types=1);

namespace Knot\Tests\Module;

use PHPUnit\Framework\TestCase;

/**
 * Guard: async Run / retention / health must be enabled at install.
 * Parses modKnot.class.php (stub class exists in PHPUnit bootstrap).
 */
final class ModKnotCronJobsTest extends TestCase
{
    public function testCronJobsDefaultStatusIsEnabledInDescriptor(): void
    {
        $path = dirname(__DIR__, 2) . '/core/modules/modKnot.class.php';
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);

        self::assertSame(
            1,
            preg_match('/\$this->cronjobs\s*=\s*\[(.*?)\];/s', $contents, $block),
            'cronjobs array must be present in modKnot',
        );

        $cronBlock = $block[1];
        foreach (['KnotCronWorker', 'KnotRetentionWorker', 'KnotHealthWorker'] as $label) {
            self::assertStringContainsString("'" . $label . "'", $cronBlock);
        }

        self::assertSame(
            3,
            preg_match_all("/'status'\\s*=>\\s*1/", $cronBlock, $statusMatches),
            'Each of the 3 Knot cron jobs must declare status => 1',
        );
        self::assertSame(
            0,
            preg_match_all("/'status'\\s*=>\\s*0/", $cronBlock),
            'No Knot cron job may declare status => 0',
        );
    }

    public function testInitForcesEnableExistingCronJobs(): void
    {
        $path = dirname(__DIR__, 2) . '/core/modules/modKnot.class.php';
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString(
            "cronjob SET status = 1",
            $contents,
            'init() must UPDATE existing Knot cron rows to status=1 on upgrade',
        );
        self::assertStringContainsString('KnotCronWorker', $contents);
    }
}
