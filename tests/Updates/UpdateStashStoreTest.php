<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\UpdateStashStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\UpdateStashStore
 */
final class UpdateStashStoreTest extends TestCase
{
    private string $tmpRoot = '';

    /** @var mixed */
    private $previousConf;

    protected function setUp(): void
    {
        $this->previousConf = $GLOBALS['conf'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousConf === null) {
            unset($GLOBALS['conf']);
        } else {
            $GLOBALS['conf'] = $this->previousConf;
        }
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

    public function testResolveRootPrefersConfDirOutput(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-conf-' . bin2hex(random_bytes(4));
        $dirOutput = $this->tmpRoot . '/documents/knot';
        $conf = new \stdClass();
        $conf->knot = new \stdClass();
        $conf->knot->dir_output = $dirOutput;
        $GLOBALS['conf'] = $conf;

        $root = UpdateStashStore::resolveRoot();
        self::assertSame(
            $dirOutput . DIRECTORY_SEPARATOR . UpdateStashStore::SUBDIR,
            $root,
        );
        self::assertStringNotContainsString('/custom/', $root);
    }

    public function testResolveRootFallsBackWhenConfMissing(): void
    {
        unset($GLOBALS['conf']);
        $root = str_replace('\\', '/', UpdateStashStore::resolveRoot());
        self::assertStringEndsWith('/knot/' . UpdateStashStore::SUBDIR, $root);
        if (defined('DOL_DATA_ROOT')) {
            self::assertStringStartsWith(
                rtrim(str_replace('\\', '/', (string) DOL_DATA_ROOT), '/'),
                $root,
            );
        } else {
            self::assertStringStartsWith(
                rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/'),
                $root,
            );
        }
    }

    public function testOverrideRootWinsOverConf(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-ov-' . bin2hex(random_bytes(4));
        $conf = new \stdClass();
        $conf->knot = new \stdClass();
        $conf->knot->dir_output = $this->tmpRoot . '/from-conf';
        $GLOBALS['conf'] = $conf;

        $override = $this->tmpRoot . '/explicit-stashes';
        self::assertSame($override, UpdateStashStore::resolveRoot($override));
        self::assertSame($override, (new UpdateStashStore($override))->root());
    }

    public function testAllocateCreatesRootNotLeafAndMatchesSlugPattern(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-alloc-' . bin2hex(random_bytes(4));
        $store = new UpdateStashStore($this->tmpRoot . '/update-stashes');
        $path = $store->allocate('knot');

        self::assertDirectoryExists($store->root());
        self::assertDirectoryDoesNotExist($path);
        self::assertTrue(UpdateStashStore::isStashDirectoryName(basename($path), 'knot'));
        self::assertSame($store->root(), dirname($path));
    }

    public function testListForSlugDoesNotMatchLongerPrefix(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-prefix-' . bin2hex(random_bytes(4));
        $root = $this->tmpRoot . '/update-stashes';
        @mkdir($root, 0777, true);
        @mkdir($root . '/knot.20260101120000_aaaaaa', 0777, true);
        @mkdir($root . '/knot-pro-pack.20260101120000_bbbbbb', 0777, true);

        $store = new UpdateStashStore($root);
        $core = $store->listForSlug('knot');
        self::assertCount(1, $core);
        self::assertSame('knot.20260101120000_aaaaaa', basename($core[0]));
    }

    public function testPruneKeepsNewestAndDeletesOlder(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-prune-' . bin2hex(random_bytes(4));
        $root = $this->tmpRoot . '/update-stashes';
        @mkdir($root, 0777, true);
        foreach (['20260101000000_111111', '20260102000000_222222', '20260103000000_333333'] as $suffix) {
            $dir = $root . '/knot.' . $suffix;
            @mkdir($dir, 0777, true);
            file_put_contents($dir . '/marker.txt', $suffix);
        }

        $store = new UpdateStashStore($root);
        self::assertSame(1, $store->prune('knot', UpdateStashStore::KEEP_DEFAULT));
        $left = $store->listForSlug('knot');
        self::assertCount(2, $left);
        self::assertSame('knot.20260103000000_333333', basename($left[0]));
        self::assertSame('knot.20260102000000_222222', basename($left[1]));
        self::assertDirectoryDoesNotExist($root . '/knot.20260101000000_111111');
    }

    public function testAssertSafeForLiveRejectsCustomParentAndCustomSegment(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-unsafe-' . bin2hex(random_bytes(4));
        $custom = $this->tmpRoot . '/htdocs/custom';
        $live = $custom . '/knot';
        @mkdir($live, 0777, true);

        $siblingStore = new UpdateStashStore($custom);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('live module parent');
        $siblingStore->assertSafeForLive($live);
    }

    public function testAssertSafeForLiveRejectsPathUnderCustomSegment(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-seg-' . bin2hex(random_bytes(4));
        $live = $this->tmpRoot . '/elsewhere/knot';
        @mkdir($live, 0777, true);
        $store = new UpdateStashStore($this->tmpRoot . '/htdocs/custom/update-stashes');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('custom/');
        $store->assertSafeForLive($live);
    }

    public function testAssertSafeForLiveAcceptsDocumentsPath(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-ok-' . bin2hex(random_bytes(4));
        $live = $this->tmpRoot . '/htdocs/custom/knot';
        $stashes = $this->tmpRoot . '/documents/knot/update-stashes';
        @mkdir($live, 0777, true);
        $store = new UpdateStashStore($stashes);
        $store->assertSafeForLive($live);
        self::assertSame($stashes, $store->root());
    }

    public function testAllocateRejectsUnsafeSlug(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/stash-slug-' . bin2hex(random_bytes(4));
        $store = new UpdateStashStore($this->tmpRoot);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid module folder name');
        $store->allocate('../escape');
    }
}
