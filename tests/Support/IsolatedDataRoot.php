<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

/**
 * Points DOL_DATA_ROOT at a directory owned by the current test process.
 *
 * Use only from tests running in a separate process: the constant cannot be
 * redefined, and the shared system temp directory must stay untouched.
 */
trait IsolatedDataRoot
{
    private string $isolatedDataRoot = '';

    private function useIsolatedDataRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knot-test-data-' . bin2hex(random_bytes(8));
        mkdir($root, 0755, true);
        define('DOL_DATA_ROOT', $root);
        $this->isolatedDataRoot = $root;

        return $root;
    }

    private function removeIsolatedDataRoot(): void
    {
        if ($this->isolatedDataRoot === '' || !is_dir($this->isolatedDataRoot)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->isolatedDataRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->isolatedDataRoot);
        $this->isolatedDataRoot = '';
    }
}
