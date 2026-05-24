<?php

declare(strict_types=1);

namespace Knot\Tests\Migration;

use Knot\Migration\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorV2120Test extends TestCase
{
    public function testV2120MigrationSqlAddsActivationWarningDismissedColumn(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/sql/migrations/v2.12.0/01_workflow_activation_warning_dismissed.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('activation_warning_dismissed', $sql);
        self::assertStringContainsString('llx_knot_workflow', $sql);
    }

    public function testMigratorAppliesV2120SqlOnce(): void
    {
        $root = sys_get_temp_dir() . '/knot_mig_v212_' . uniqid('', true);
        $dir = $root . '/sql/migrations/v2.12.0';
        @mkdir($dir, 0o777, true);
        $src = dirname(__DIR__, 2) . '/sql/migrations/v2.12.0/01_workflow_activation_warning_dismissed.sql';
        copy($src, $dir . '/01_workflow_activation_warning_dismissed.sql');

        $applied = ['rows' => [], 'executed' => []];

        $db = new class($applied) extends \DoliDB {
            /** @var array<string, mixed> */
            private array $applied;
            private bool $lastSelectFound = false;

            public function __construct(array &$applied)
            {
                $this->applied = &$applied;
            }

            public function query(string $sql)
            {
                if (str_starts_with($sql, 'SELECT rowid FROM llx_knot_migration_history')) {
                    $this->lastSelectFound = count($this->applied['rows']) > 0;
                    return new \stdClass();
                }
                if (str_starts_with($sql, 'INSERT INTO llx_knot_migration_history')) {
                    $this->applied['rows'][] = $sql;
                } else {
                    $this->applied['executed'][] = $sql;
                }
                $this->lastSelectFound = false;
                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                if ($this->lastSelectFound) {
                    $this->lastSelectFound = false;
                    return (object) ['rowid' => 1];
                }
                return null;
            }
        };

        $m = new Migrator($db, $root);
        $first = $m->run();
        $second = $m->run();

        self::assertNotEmpty($first);
        self::assertSame([], $second);
        $joined = implode("\n", $applied['executed']);
        self::assertStringContainsString('activation_warning_dismissed', $joined);

        @unlink($dir . '/01_workflow_activation_warning_dismissed.sql');
        @rmdir($dir);
        @rmdir($root . '/sql/migrations');
        @rmdir($root . '/sql');
        @rmdir($root);
    }
}
