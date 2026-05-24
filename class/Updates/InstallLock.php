<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Updates;

use RuntimeException;

/**
 * Cross-process flock guard for Knot apply operations.
 *
 * Persisted adjacent to DOL_DATA_ROOT so multiple PHP workers collide safely.
 */
final class InstallLock
{
    /** @var resource|null */
    private $handle;

    /**
     * @throws RuntimeException when another apply is holding the lock.
     */
    public function acquire(): void
    {
        $root = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : sys_get_temp_dir();
        $dir = rtrim((string) $root, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'knot';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create Knot data directory for lock: ' . $dir);
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'update.lock';

        $fh = fopen($path, 'c+b');
        if ($fh === false) {
            throw new RuntimeException('Cannot open Knot update lock file: ' . $path);
        }
        stream_set_blocking($fh, true);
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException('Another Knot update apply is already in progress.');
        }

        fwrite($fh, 'pid:' . getmypid() . '|ts:' . gmdate('c'));

        $this->handle = $fh;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
