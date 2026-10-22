<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

class SqliteFilterSortTest extends FilterSortTestCase
{
    protected static function driver(): string
    {
        return 'sqlite';
    }
}
