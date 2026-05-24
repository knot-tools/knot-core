<?php

declare(strict_types=1);

class Societe
{
    public int $id = 0;
    public string $ref = '';
    public string $name = '';
    public string $label = '';

    public function __construct(mixed $db = null)
    {
    }

    public function fetch(int $id): int
    {
        $this->id = $id;
        return 1;
    }
}
