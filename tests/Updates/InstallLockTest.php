<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Tests\Support\IsolatedDataRoot;
use Knot\Updates\InstallLock;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\InstallLock
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallLockTest extends TestCase
{
    use IsolatedDataRoot;

    private string $lockPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = $this->useIsolatedDataRoot() . '/knot/update.lock';
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedDataRoot();
        parent::tearDown();
    }

    public function testSecondExclusiveLockFails(): void
    {
        $a = new InstallLock();
        $a->acquire();

        $b = new InstallLock();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already in progress');
        try {
            $b->acquire();
        } finally {
            $a->release();
        }
    }

    public function testReleaseRemovesLockFileAndAllowsNextApply(): void
    {
        $path = $this->lockPath;
        $a = new InstallLock();
        $a->acquire();
        self::assertFileExists($path);
        $a->release();
        self::assertFileDoesNotExist($path);

        $b = new InstallLock();
        $b->acquire();
        self::assertFileExists($path);
        $b->release();
    }
}
