<?php

declare(strict_types=1);

namespace Knot\Tests\Migration;

use Knot\Migration\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Validates the idempotency contract of the Migrator: rerunning the same
 * version should be a no-op once the history row exists.
 */
final class MigratorTest extends TestCase
{
    public function testRunIsIdempotentAcrossMultipleInvocations(): void
    {
        $tmp = sys_get_temp_dir() . '/knot_mig_idem_' . uniqid('', true);
        @mkdir($tmp . '/sql/migrations/v2.0.0', 0o777, true);
        file_put_contents($tmp . '/sql/migrations/v2.0.0/01_noop.sql', "-- noop");

        $applied = ['rows' => []];

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

        $m = new Migrator($db, $tmp);
        $first = $m->run();
        $second = $m->run();
        $third = $m->run();

        $this->assertNotEmpty($first, 'First run should apply the migration');
        $this->assertSame([], $second, 'Second run should skip already-applied migrations');
        $this->assertSame([], $third, 'Third run should also skip');

        @unlink($tmp . '/sql/migrations/v2.0.0/01_noop.sql');
        @rmdir($tmp . '/sql/migrations/v2.0.0');
        @rmdir($tmp . '/sql/migrations');
        @rmdir($tmp . '/sql');
        @rmdir($tmp);
    }

    public function testAlterColumnIsSkippedWhenColumnExists(): void
    {
        $tmp = sys_get_temp_dir() . '/knot_mig_alter_' . uniqid('', true);
        @mkdir($tmp . '/sql/migrations/v2.0.0', 0o777, true);
        file_put_contents(
            $tmp . '/sql/migrations/v2.0.0/02_add_column.sql',
            'ALTER TABLE llx_knot_execution ADD COLUMN demo_flag TINYINT DEFAULT 0;'
        );

        $queries = [];
        $dbWithColumn = new class($queries) extends \DoliDB {
            private bool $showColumnsResult = false;

            public function __construct(private array &$queries)
            {
            }

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW COLUMNS FROM llx_knot_execution LIKE')) {
                    $this->showColumnsResult = true;
                    return new \stdClass();
                }
                if (str_starts_with($sql, 'SELECT rowid FROM llx_knot_migration_history')) {
                    return new \stdClass();
                }

                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                return null;
            }

            public function num_rows($resource): int
            {
                return $this->showColumnsResult ? 1 : 0;
            }

            public function escape(string $string): string
            {
                return addslashes($string);
            }

            public function lasterror(): string
            {
                return '';
            }
        };

        $m = new Migrator($dbWithColumn, $tmp);
        $applied = $m->run();
        self::assertCount(1, $applied);
        self::assertSame('applied', $applied[0]['status']);
        self::assertFalse(
            count(array_filter($queries, static fn (string $q): bool => str_contains($q, 'ADD COLUMN demo_flag'))) > 0
        );

        @unlink($tmp . '/sql/migrations/v2.0.0/02_add_column.sql');
        @rmdir($tmp . '/sql/migrations/v2.0.0');
        @rmdir($tmp . '/sql/migrations');
        @rmdir($tmp . '/sql');
        @rmdir($tmp);
    }

    public function testAlterIndexIsSkippedWhenIndexExists(): void
    {
        $tmp = sys_get_temp_dir() . '/knot_mig_idx_' . uniqid('', true);
        @mkdir($tmp . '/sql/migrations/v2.0.0', 0o777, true);
        file_put_contents(
            $tmp . '/sql/migrations/v2.0.0/03_add_index.sql',
            'ALTER TABLE llx_knot_execution ADD KEY idx_demo (entity);'
        );

        $queries = [];
        $db = new class($queries) extends \DoliDB {
            private bool $showIndexResult = false;

            public function __construct(private array &$queries)
            {
            }

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW INDEX FROM llx_knot_execution WHERE Key_name')) {
                    $this->showIndexResult = true;
                    return new \stdClass();
                }

                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                return null;
            }

            public function num_rows($resource): int
            {
                return $this->showIndexResult ? 1 : 0;
            }

            public function escape(string $string): string
            {
                return addslashes($string);
            }

            public function lasterror(): string
            {
                return '';
            }
        };

        $m = new Migrator($db, $tmp);
        $applied = $m->run();
        self::assertCount(1, $applied);
        self::assertFalse(
            count(array_filter($queries, static fn (string $q): bool => str_contains($q, 'ADD KEY idx_demo'))) > 0
        );

        @unlink($tmp . '/sql/migrations/v2.0.0/03_add_index.sql');
        @rmdir($tmp . '/sql/migrations/v2.0.0');
        @rmdir($tmp . '/sql/migrations');
        @rmdir($tmp . '/sql');
        @rmdir($tmp);
    }

    public function testHarmlessDuplicateColumnErrorIsIgnored(): void
    {
        $tmp = sys_get_temp_dir() . '/knot_mig_dup_' . uniqid('', true);
        @mkdir($tmp . '/sql/migrations/v2.0.0', 0o777, true);
        file_put_contents(
            $tmp . '/sql/migrations/v2.0.0/04_dup.sql',
            'ALTER TABLE llx_knot_execution ADD COLUMN demo_flag TINYINT DEFAULT 0;'
        );

        $queries = [];
        $db = new class($queries) extends \DoliDB {
            public function __construct(private array &$queries)
            {
            }

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (str_contains($sql, 'ADD COLUMN demo_flag')) {
                    return false;
                }

                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                return null;
            }

            public function num_rows($resource): int
            {
                return 0;
            }

            public function escape(string $string): string
            {
                return addslashes($string);
            }

            public function lasterror(): string
            {
                return 'Duplicate column name demo_flag';
            }
        };

        $m = new Migrator($db, $tmp);
        $applied = $m->run();
        self::assertSame('applied', $applied[0]['status']);

        @unlink($tmp . '/sql/migrations/v2.0.0/04_dup.sql');
        @rmdir($tmp . '/sql/migrations/v2.0.0');
        @rmdir($tmp . '/sql/migrations');
        @rmdir($tmp . '/sql');
        @rmdir($tmp);
    }

    public function testAlterUniqueKeyIsSkippedWhenIndexExists(): void
    {
        $tmp = sys_get_temp_dir() . '/knot_mig_uq_' . uniqid('', true);
        @mkdir($tmp . '/sql/migrations/v2.0.0', 0o777, true);
        file_put_contents(
            $tmp . '/sql/migrations/v2.0.0/05_add_unique.sql',
            'ALTER TABLE llx_knot_execution ADD UNIQUE KEY uniq_demo (entity);'
        );

        $queries = [];
        $db = new class($queries) extends \DoliDB {
            private bool $showIndexResult = false;

            public function __construct(private array &$queries)
            {
            }

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW INDEX FROM llx_knot_execution WHERE Key_name')) {
                    $this->showIndexResult = true;
                    return new \stdClass();
                }

                return new \stdClass();
            }

            public function fetch_object($resource): ?object
            {
                return null;
            }

            public function num_rows($resource): int
            {
                return $this->showIndexResult ? 1 : 0;
            }

            public function escape(string $string): string
            {
                return addslashes($string);
            }

            public function lasterror(): string
            {
                return '';
            }
        };

        $m = new Migrator($db, $tmp);
        $applied = $m->run();
        self::assertSame('applied', $applied[0]['status']);
        self::assertFalse(
            count(array_filter($queries, static fn (string $q): bool => str_contains($q, 'ADD UNIQUE KEY uniq_demo'))) > 0
        );

        @unlink($tmp . '/sql/migrations/v2.0.0/05_add_unique.sql');
        @rmdir($tmp . '/sql/migrations/v2.0.0');
        @rmdir($tmp . '/sql/migrations');
        @rmdir($tmp . '/sql');
        @rmdir($tmp);
    }
}
