<?php

declare(strict_types=1);

namespace Knot\Repository;

/**
 * Base class for Knot repositories.
 */
abstract class AbstractRepository
{
    public function __construct(protected \DoliDB $db)
    {
    }

    /**
     * Return SQL table name with Dolibarr prefix.
     */
    protected function table(string $name): string
    {
        return MAIN_DB_PREFIX . 'knot_' . $name;
    }
}
