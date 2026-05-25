<?php

declare(strict_types=1);

/**
 * Minimal Dolibarr module base for Pro Pack PHPUnit (pro-pack bootstrap sets
 * DOL_DOCUMENT_ROOT to this stubs tree).
 */
class DolibarrModules
{
    public $db;

    public bool $stubInitWasCalled = false;

    public string $error = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param array<int, mixed> $array_sql
     */
    protected function _init($array_sql, $options = '')
    {
        $this->stubInitWasCalled = true;

        return 1;
    }
}
