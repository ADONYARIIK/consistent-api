<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

class MysqlFilterSortTest extends FilterSortTestCase
{
    protected static function driver(): string
    {
        return 'mysql';
    }
}
