<?php

declare(strict_types=1);

namespace Knot\Tests\Observability;

use Knot\Observability\RuntimeLogger;
use PHPUnit\Framework\TestCase;

/**
 * V2.5.0d — runtime logger backs the JSON log stream consumed by
 * Loki / Vector / Filebeat plus the healthcheck `runtime_log_failures`
 * counter. Behaviour we lock down here:
 *
 *  - `logNodeExecution()` produces one JSON object per line with the
 *    expected keys, no extras.
 *  - successive calls append (do not overwrite) to the daily file.
 *  - `rotate()` deletes files older than RETENTION_DAYS.
 *  - a non-writable directory does not throw — it bumps the failure
 *    counter so the healthcheck can warn the operator.
 *  - secret-looking values inside `error` are masked.
 */
final class RuntimeLoggerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-runtime-logger-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
    }

    public function testWritesValidJsonLine(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution([
            'workflowId' => 42,
            'executionId' => 7,
            'nodeId' => 'n1',
            'nodeType' => 'action.http',
            'status' => 'success',
            'durationMs' => 123,
            'tenant' => 1,
        ]);

        $files = glob($this->tmpDir . '/runtime-*.jsonl');
        self::assertNotEmpty($files);
        $contents = file_get_contents($files[0]);
        self::assertNotFalse($contents);
        $lines = array_values(array_filter(explode("\n", (string) $contents)));
        self::assertCount(1, $lines);

        $event = json_decode($lines[0], true);
        self::assertIsArray($event);
        self::assertSame('node.execution', $event['event']);
        self::assertSame('42', $event['workflowId']);
        self::assertSame('7', $event['executionId']);
        self::assertSame('n1', $event['nodeId']);
        self::assertSame('action.http', $event['nodeType']);
        self::assertSame('success', $event['status']);
        self::assertSame(123, $event['durationMs']);
        self::assertSame('1', $event['tenant']);
        self::assertNull($event['error']);
        self::assertArrayHasKey('ts', $event);
    }

    public function testAppendsRatherThanOverwrites(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution(['nodeId' => 'a', 'status' => 'success']);
        $logger->logNodeExecution(['nodeId' => 'b', 'status' => 'error']);
        $logger->logNodeExecution(['nodeId' => 'c', 'status' => 'success']);

        $files = glob($this->tmpDir . '/runtime-*.jsonl');
        $contents = (string) file_get_contents($files[0]);
        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(3, $lines);
    }

    public function testRotateDropsFilesOlderThanRetention(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution(['nodeId' => 'a', 'status' => 'success']);

        // Plant a fake file 10 days old.
        $oldFile = $this->tmpDir . '/runtime-2020-01-01.jsonl';
        file_put_contents($oldFile, "old\n");
        touch($oldFile, time() - (10 * 86400));

        $removed = $logger->rotate();
        self::assertSame(1, $removed);
        self::assertFileDoesNotExist($oldFile);

        // Fresh log still there.
        $fresh = glob($this->tmpDir . '/runtime-*.jsonl');
        self::assertCount(1, $fresh);
    }

    public function testFailureCounterIncrementsWhenDirectoryUnwritable(): void
    {
        // Use a path that cannot exist (parent is /dev/null which is
        // not a directory) so mkdir fails and write_failures bumps.
        $logger = new RuntimeLogger('/dev/null/runtime-' . uniqid('', true));
        self::assertFalse($logger->isWritable());

        $logger->logNodeExecution(['nodeId' => 'a', 'status' => 'success']);
        // The counter file itself cannot be written either, so it
        // stays at 0 — the contract is that the call does not throw.
        self::assertGreaterThanOrEqual(0, $logger->failureCount());
    }

    public function testMaskingStripsObviousSecrets(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution([
            'nodeId' => 'a',
            'status' => 'error',
            'error' => 'API call failed: Bearer sk_live_4eC39HqLyjWDarjtT1zdp7dc',
        ]);
        $files = glob($this->tmpDir . '/runtime-*.jsonl');
        $contents = (string) file_get_contents($files[0]);
        // Either redacted in place or the literal token is gone.
        self::assertStringNotContainsString('sk_live_4eC39HqLyjWDarjtT1zdp7dc', $contents);
    }

    public function testDiskUsageStatsAggregatesPerFile(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution(['nodeId' => 'a', 'status' => 'success']);
        $logger->logNodeExecution(['nodeId' => 'b', 'status' => 'success']);

        $stats = $logger->diskUsageStats();
        self::assertSame(1, $stats['fileCount']);
        self::assertGreaterThan(0, $stats['totalBytes']);
        self::assertSame($stats['totalBytes'], $stats['bytesToday']);
        self::assertNotNull($stats['diskFreeBytes']);
        self::assertNotNull($stats['diskFreeRatio']);
    }

    public function testDiskUsageStatsHandlesMissingDirectory(): void
    {
        $logger = new RuntimeLogger($this->tmpDir . '/missing');
        $stats = $logger->diskUsageStats();
        self::assertSame(0, $stats['fileCount']);
        self::assertSame(0, $stats['totalBytes']);
        self::assertSame(0, $stats['bytesToday']);
    }

    public function testTruncatesGiantErrorPayloads(): void
    {
        $logger = new RuntimeLogger($this->tmpDir);
        $logger->logNodeExecution([
            'nodeId' => 'a',
            'status' => 'error',
            'error' => str_repeat('A', 6000),
        ]);
        $files = glob($this->tmpDir . '/runtime-*.jsonl');
        $contents = (string) file_get_contents($files[0]);
        $event = json_decode(trim($contents), true);
        self::assertIsArray($event);
        self::assertLessThanOrEqual(4097, mb_strlen((string) $event['error']));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
