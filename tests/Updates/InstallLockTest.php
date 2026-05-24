<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\InstallLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\InstallLock
 */
final class InstallLockTest extends TestCase
{
    protected function tearDown(): void
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/')
            . DIRECTORY_SEPARATOR . 'knot'
            . DIRECTORY_SEPARATOR . 'update.lock';
        if (is_readable($path)) {
            $h = fopen($path, 'rb');
            if (is_resource($h)) {
                @flock($h, LOCK_UN);
                fclose($h);
            }
            @unlink($path);
        }
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
}
