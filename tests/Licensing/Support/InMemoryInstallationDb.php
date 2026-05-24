<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Support;

use Knot\Tests\Repository\InMemoryConfigDb;

/**
 * KnotConfig-compatible in-memory DB with synthetic {@see DoliDB} host/name props.
 */
final class InMemoryInstallationDb extends InMemoryConfigDb
{
    public string $dbhost;

    public string $dbname;

    public function __construct(string $installDbHost, string $installDbName)
    {
        $this->dbhost = $installDbHost;
        $this->dbname = $installDbName;
    }
}
