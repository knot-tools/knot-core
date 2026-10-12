<?php

declare(strict_types=1);

namespace Knot\Tests\Schema;

use Knot\Tests\Support\InstallSqlSchema;
use PHPUnit\Framework\TestCase;

/**
 * Guards install SQL vs migrations vs application SQL column references.
 *
 * After 2.13.24, migration-added columns/tables that application code
 * depends on must also exist in `sql/llx_knot_*.sql` so `_load_tables`
 * on a fresh enable matches the post-Migrator schema.
 */
final class InstallSqlColumnReferenceAuditTest extends TestCase
{
    public function testSharedColumnNamesMustExistOnReferencedInstallTables(): void
    {
        $sqlDir = dirname(__DIR__, 2) . '/sql';
        $install = InstallSqlSchema::loadInstallTables($sqlDir);
        $effective = InstallSqlSchema::loadEffectiveTables($sqlDir);

        $columnOwners = [];
        foreach ($effective as $table => $cols) {
            foreach (array_keys($cols) as $col) {
                $columnOwners[$col][$table] = true;
            }
        }

        $shared = [];
        foreach ($columnOwners as $col => $owners) {
            if (count($owners) >= 2) {
                $shared[$col] = true;
            }
        }
        foreach (['date_creation', 'tms', 'created_at', 'updated_at', 'fk_user_creat', 'fk_user_modif'] as $col) {
            $shared[$col] = true;
        }

        $hardFailures = [];
        $classRoot = dirname(__DIR__, 2) . '/class';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }
            $path = $fileInfo->getPathname();
            $relative = 'class/' . substr($path, strlen($classRoot) + 1);
            $content = (string) file_get_contents($path);
            $tables = $this->detectKnotTables($content, array_keys($effective));
            if ($tables === []) {
                continue;
            }

            foreach ($this->extractSqlIdentifiers($content) as $ident) {
                if (!isset($shared[$ident])) {
                    continue;
                }
                $okOnInstall = false;
                foreach ($tables as $table) {
                    if (isset($install[$table][$ident])) {
                        $okOnInstall = true;
                        break;
                    }
                }
                if ($okOnInstall) {
                    continue;
                }
                $hardFailures[] = sprintf(
                    '%s: shared column `%s` used in SQL strings but absent from install schema for tables [%s]',
                    $relative,
                    $ident,
                    implode(',', $tables)
                );
            }
        }

        self::assertSame([], $hardFailures, implode("\n", $hardFailures));
    }

    /**
     * Every column/table introduced by migrations must also ship in install SQL
     * (fresh `_load_tables` path). No allowlist — fold into sql/llx_knot_*.sql.
     */
    public function testMigrationSchemaIsCoveredByInstallSql(): void
    {
        $sqlDir = dirname(__DIR__, 2) . '/sql';
        $install = InstallSqlSchema::loadInstallTables($sqlDir);
        $effective = InstallSqlSchema::loadEffectiveTables($sqlDir);

        $missing = [];
        foreach ($effective as $table => $cols) {
            if (!isset($install[$table])) {
                $missing[] = sprintf(
                    'table `%s` exists via migrations/Migrator but has no sql/llx_knot_%s.sql',
                    $table,
                    $table
                );
                continue;
            }
            foreach (array_keys($cols) as $col) {
                if (!isset($install[$table][$col])) {
                    $missing[] = sprintf(
                        '`%s.%s` added by migration but missing from install SQL',
                        $table,
                        $col
                    );
                }
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * Previously drifted columns — assert present on fresh-install schema.
     *
     * @dataProvider previouslyDriftedColumnsProvider
     */
    public function testPreviouslyDriftedColumnsPresentInInstallSql(string $table, string $column): void
    {
        $sqlDir = dirname(__DIR__, 2) . '/sql';
        $install = InstallSqlSchema::loadInstallTables($sqlDir);
        self::assertArrayHasKey($table, $install, 'missing install table ' . $table);
        self::assertArrayHasKey(
            $column,
            $install[$table],
            sprintf('%s.%s must exist in install SQL (was migration-only drift)', $table, $column)
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function previouslyDriftedColumnsProvider(): array
    {
        return [
            ['workflow', 'fk_folder'],
            ['webhook', 'rl_window_start'],
            ['webhook', 'rl_count'],
            ['rate_limit', 'bucket'],
            ['rate_limit', 'window_start'],
            ['rate_limit', 'hits'],
            ['config', 'k'],
            ['config', 'v'],
            ['config', 'created_at'],
            ['config', 'updated_at'],
            ['template', 'slug'],
            ['template', 'tier'],
            ['template', 'status'],
            ['template', 'cached_at'],
            ['template', 'source'],
            ['migration_history', 'version'],
            ['migration_history', 'migration_file'],
            ['migration_history', 'applied_at'],
            ['migration_history', 'status'],
        ];
    }

    /**
     * @param list<string> $knownShortNames
     * @return list<string>
     */
    private function detectKnotTables(string $content, array $knownShortNames): array
    {
        $found = [];
        foreach ($knownShortNames as $short) {
            if (preg_match('/tablePrefix\s*\.\s*[\'"]' . preg_quote($short, '/') . '[\'"]/', $content) === 1) {
                $found[$short] = true;
                continue;
            }
            if (
                str_contains($content, "table('" . $short . "')")
                || str_contains($content, 'table("' . $short . '")')
                || str_contains($content, 'llx_knot_' . $short)
            ) {
                $found[$short] = true;
                continue;
            }
            if (preg_match('/MAIN_DB_PREFIX\s*\.\s*[\'"]knot_' . preg_quote($short, '/') . '[\'"]/', $content) === 1) {
                $found[$short] = true;
                continue;
            }
            if (preg_match('/[\'"]knot_' . preg_quote($short, '/') . '[\'"]/', $content) === 1) {
                $found[$short] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    private function extractSqlIdentifiers(string $content): array
    {
        $idents = [];
        if (!preg_match_all('/([\'"])(.*?)\1/s', $content, $matches)) {
            return [];
        }
        foreach ($matches[2] as $literal) {
            $lit = (string) $literal;
            $isSqlish = preg_match(
                '/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|GROUP\s+BY|ORDER\s+BY|AND|SET)\b/i',
                $lit
            ) === 1;
            $isColumnList = preg_match(
                '/^[a-z_][a-z0-9_]*(?:\s*,\s*[a-z_][a-z0-9_]*)+\s*$/i',
                trim($lit)
            ) === 1;
            $isSingleColumnFrag = preg_match(
                '/^(?:AND\s+|,\s*)?[a-z_][a-z0-9_]*(?:\s*(?:=|>=|<=|<>|!=))?/i',
                trim($lit)
            ) === 1 && preg_match('/date_creation|started_at|ended_at|scheduled_at|executed_at|created_at|tms/i', $lit) === 1;

            if (!$isSqlish && !$isColumnList && !$isSingleColumnFrag) {
                continue;
            }
            if (preg_match_all('/\b([a-z_][a-z0-9_]*)\b/i', $lit, $words)) {
                foreach ($words[1] as $w) {
                    $idents[strtolower((string) $w)] = true;
                }
            }
        }

        return array_keys($idents);
    }
}
