<?php

declare(strict_types=1);

namespace Knot\Module;

/**
 * Installation expectations shared by admin/setup.php, api/health.php, and modKnot.
 *
 * Keep MENU_ENTRY_COUNT in sync with the number of entries pushed into
 * modKnot::$this->menu in core/modules/modKnot.class.php.
 */
final class ModuleExpectations
{
    public const MENU_ENTRY_COUNT = 7;
}
