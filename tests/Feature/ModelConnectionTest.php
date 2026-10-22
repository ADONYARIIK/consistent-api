<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\Author;
use Tests\Fixtures\Book;
use Tests\TestCase;

/**
 * CanFilter and CanSort build SQL for the model's connection, not for the default one.
 *
 * The default connection is SQLite; toSql() compiles the queries without connecting to the other servers.
 */
class ModelConnectionTest extends TestCase
{
    public function test_substring_filters_use_the_operator_of_the_model_connection(): void
    {
        $query = (new Book)->setConnection('pgsql')->newQuery()->filter(['title' => 'ring', 'author' => 'ann', 'status' => ['like' => 'pub']], []);

        // Column, relation and like filters: LIKE would be case-sensitive on PostgreSQL
        $this->assertSame(3, substr_count($query->toSql(), " ILIKE ? ESCAPE '!'"));
        $this->assertStringEndsWith("\"books\".\"title\" LIKE ? ESCAPE '!'", Book::filter(['title' => 'ring'], [])->toSql());
    }

    public function test_relation_sorts_are_compiled_for_the_model_connection(): void
    {
        $pgsql = (new Author)->setConnection('pgsql')->newQuery()->sort(['books' => 'asc'], [])->toSql();
        $mysql = (new Book)->setConnection('mysql')->newQuery()->sort(['tags' => 'desc'], [])->toSql();

        $this->assertStringContainsString('order by (select "books"."title" from "books" where "authors"."id" = "books"."author_id"', $pgsql);
        $this->assertStringContainsString('order by `tags`.`name` desc limit 1) desc', $mysql);

        foreach ([$pgsql, $mysql] as $sql) {
            $this->assertStringNotContainsString('STRING_AGG', $sql);
            $this->assertStringNotContainsString('GROUP_CONCAT', $sql);
        }
    }
}
