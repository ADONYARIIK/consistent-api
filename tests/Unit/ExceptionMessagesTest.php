<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adonyarik\ConsistentApi\Exceptions\InvalidFilterableOperatorException;
use Adonyarik\ConsistentApi\Exceptions\InvalidModuleOptionsException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use PHPUnit\Framework\TestCase;

class ExceptionMessagesTest extends TestCase
{
    public function test_declaration_messages_name_the_model_and_column(): void
    {
        $this->assertSame(
            'Model [App\Post] declares #[Filterable] operator(s) [min, max] for column "price" that are not enabled in config("consistentapi.filters.operators"). '
                .'Either add them to the config or remove them from the attribute.',
            InvalidFilterableOperatorException::forColumn('App\Post', 'price', ['min', 'max'])->getMessage(),
        );
        $this->assertStringStartsWith(
            'Model [App\Post] declares #[Filterable] column "author" as a RelationFilter pointing to relation "user"',
            InvalidRelationFilterException::relationNotFound('App\Post', 'author', 'user')->getMessage(),
        );
        $this->assertStringContainsString(
            'pointing to "user", but that method does not return an Eloquent relation.',
            InvalidRelationFilterException::notARelation('App\Post', 'author', 'user')->getMessage(),
        );
        $this->assertSame(
            'Model [App\Post] declares #[Filterable] column "author" as a RelationFilter with operator "=". '
                .'Use "eq" for equality or "like" for a substring match.',
            InvalidRelationFilterException::unknownOperator('App\Post', 'author', '=')->getMessage(),
        );
    }

    public function test_module_options_exception_keeps_every_error(): void
    {
        $exception = new InvalidModuleOptionsException(['first', 'second']);

        $this->assertSame(['first', 'second'], $exception->errors);
        $this->assertSame('first'.PHP_EOL.'second', $exception->getMessage());
    }
}
