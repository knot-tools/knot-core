<?php

declare(strict_types=1);

namespace Knot\Tests\Module;

use Knot\Module\ModuleExpectations;
use PHPUnit\Framework\TestCase;

final class ModuleExpectationsTest extends TestCase
{
    public function testMenuEntryCountMatchesModKnotMenuArray(): void
    {
        $modPath = dirname(__DIR__, 2) . '/core/modules/modKnot.class.php';
        $src = (string) file_get_contents($modPath);
        preg_match_all('/\$this->menu\[\$r\+\+\]\s*=\s*\[/', $src, $matches);
        self::assertCount(ModuleExpectations::MENU_ENTRY_COUNT, $matches[0], 'Update ModuleExpectations::MENU_ENTRY_COUNT when editing modKnot menus.');
    }
}
