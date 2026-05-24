<?php

/**
 * Lightweight PSR-4 autoloader for Knot classes.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Knot\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
