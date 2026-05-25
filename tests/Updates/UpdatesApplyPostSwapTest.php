<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Repository\AuditLogRepository;
use Knot\Tests\Licensing\Audit\CapturingDb;
use Knot\Updates\Installer;
use Knot\Updates\UpdatesApplyPostSwap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Updates\UpdatesApplyPostSwap
 */
final class UpdatesApplyPostSwapTest extends TestCase
{
    private string $tmpRoot = '';

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            is_dir($child) ? $this->rrmdir($child) : @unlink($child);
        }
        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function auditActions(CapturingDb $db): array
    {
        $actions = [];
        foreach ($db->queries as $sql) {
            if (preg_match("/'updates\\.apply\\.[^']+'/", $sql, $m)) {
                $actions[] = trim($m[0], "'");
            }
        }

        return $actions;
    }

    /**
     * @return array{namespace: string, suffix: string}
     */
    private function writeThrowingExtensionTree(string $root): array
    {
        $suffix = bin2hex(random_bytes(3));
        $namespace = 'DemoPostApplyFail' . $suffix . '\\';
        @mkdir($root . '/class/Demo' . $suffix, 0777, true);
        file_put_contents($root . '/knot-extension.json', json_encode([
            'id' => 'knot-demo',
            'label' => 'Demo',
            'version' => '1.0.0',
            'author' => 'Knot',
            'namespace' => $namespace,
            'connectors' => [],
            'postApply' => [
                'contractVersion' => 1,
                'autoload' => 'autoload.php',
                'migrationRunner' => $namespace . 'Migrator',
            ],
        ], JSON_THROW_ON_ERROR));
        $ns = rtrim($namespace, '\\');
        file_put_contents(
            $root . '/autoload.php',
            "<?php\nrequire_once __DIR__ . '/class/Demo{$suffix}/Migrator.php';\n",
        );
        file_put_contents(
            $root . '/class/Demo' . $suffix . '/Migrator.php',
            <<<PHP
<?php
declare(strict_types=1);
namespace {$ns};
final class Migrator
{
    public function __construct(private readonly object \$db, private readonly string \$root)
    {
    }

    public function run(): array
    {
        throw new \\RuntimeException('boom migration');
    }
}
PHP,
        );

        return ['namespace' => $namespace, 'suffix' => $suffix];
    }

    private function installerWithSuccessfulRollback(string $preparedRoot, string $liveRoot): Installer
    {
        @mkdir($liveRoot, 0777, true);
        file_put_contents($liveRoot . '/manifest.json', '{"v":1}');

        $installer = new Installer();
        $installer->swap($preparedRoot, $liveRoot);

        return $installer;
    }

    public function testMigrateExtensionFailureAuditsFailedAndRolledBackWhenRollbackSucceeds(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/knot-postswap-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot, 0777, true);
        // Keep prepared under stage-parent/ so Installer::swap() purge does not delete live/.
        $prepared = $this->tmpRoot . '/stage-parent/incoming';
        $live = $this->tmpRoot . '/live';
        $this->writeThrowingExtensionTree($prepared);

        $installer = $this->installerWithSuccessfulRollback($prepared, $live);

        self::assertFileExists($live . '/knot-extension.json');

        $db = new CapturingDb();
        $audit = new AuditLogRepository($db);

        $result = UpdatesApplyPostSwap::migrateExtension(
            new \stdClass(),
            $installer,
            $live,
            $audit,
            1,
            42,
            'knot-demo',
        );

        self::assertSame('restored', $result['rollback']);
        self::assertSame(422, UpdatesApplyPostSwap::migrationFailureHttpStatus($result['rollback']));
        self::assertStringContainsString('boom migration', $result['message']);
        self::assertContains('updates.apply.failed', $this->auditActions($db));
        self::assertContains('updates.apply.rolled_back', $this->auditActions($db));
    }

    public function testMigrateExtensionFailureAuditsFailedOnlyWhenRollbackFails(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/knot-postswap-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot, 0777, true);
        $live = $this->tmpRoot . '/live-only';
        $this->writeThrowingExtensionTree($live);

        $db = new CapturingDb();
        $audit = new AuditLogRepository($db);

        $result = UpdatesApplyPostSwap::migrateExtension(
            new \stdClass(),
            $installer = new Installer(),
            $live,
            $audit,
            1,
            42,
            'knot-demo',
        );

        self::assertSame('failed', $result['rollback']);
        self::assertSame(500, UpdatesApplyPostSwap::migrationFailureHttpStatus($result['rollback']));
        $actions = $this->auditActions($db);
        self::assertContains('updates.apply.failed', $actions);
        self::assertNotContains('updates.apply.rolled_back', $actions);
    }
}
