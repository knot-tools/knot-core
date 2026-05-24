<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Knot.
 *
 * The test suite is intentionally engine-focused: it does NOT boot a real
 * Dolibarr instance. Instead it stubs the small subset of Dolibarr
 * symbols our connectors and repositories touch. Tests that need richer
 * Dolibarr behavior (e.g. CMailFile, real DB) must opt-in via integration
 * harnesses that are out of scope for the V1 unit suite.
 */

require_once __DIR__ . '/../class/autoload.php';
require_once __DIR__ . '/stubs/dolibarr.php';
