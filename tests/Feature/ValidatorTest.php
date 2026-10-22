<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Exceptions\InvalidFilterableOperatorException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use Adonyarik\ConsistentApi\Support\FilterableSortableValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\InvalidModels\DuplicateFilterColumn\Report as DuplicateFilterColumn;
use Tests\InvalidModels\Mixed\Nested\Deep;
use Tests\InvalidModels\Mixed\Several;
use Tests\InvalidModels\Mixed\Valid;
use Tests\InvalidModels\UnknownRelationFilterOperator\Report as UnknownRelationFilterOperator;
use Tests\TestCase;

/**
 * FilterableSortableValidator: operators, relation filters and model discovery.
 * AccessRestrictionsTest covers access groups, duplicate columns and relation sorts.
 */
class ValidatorTest extends TestCase
{
    private function models(string $directory): string
    {
        return dirname(__DIR__).'/InvalidModels/'.$directory;
    }

    /**
     * @return array<string, array{string, class-string, string}>
     */
    public static function brokenDeclarations(): array
    {
        return [
            'disabled operators' => ['DisabledOperator', InvalidFilterableOperatorException::class, 'operator(s) [between, near] for column "status"'],
            'disabled operator with rules' => ['DisabledRuleOperator', InvalidFilterableOperatorException::class, 'operator(s) [min] for column "price"'],
            'missing filter relation' => ['MissingFilterRelation', InvalidRelationFilterException::class, 'pointing to relation "author", but no such method exists'],
            'filter by a non-relation' => ['FilterByNonRelation', InvalidRelationFilterException::class, 'does not return an Eloquent relation'],
            'filter by a method that throws' => ['FilterByThrowingMethod', InvalidRelationFilterException::class, 'does not return an Eloquent relation'],
            'unknown relation filter operator' => ['UnknownRelationFilterOperator', InvalidRelationFilterException::class, 'column "author" as a RelationFilter with operator "equal"'],
        ];
    }

    public function test_unknown_relation_filter_operator_fails_where_validation_does_not_run(): void
    {
        $this->expectException(InvalidRelationFilterException::class);
        $this->expectExceptionMessageToContain('Use "eq" for equality or "like" for a substring match.');

        UnknownRelationFilterOperator::filter(['author' => 'Ann'], []);
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('brokenDeclarations')]
    public function test_rejects_broken_filter_declarations(string $directory, string $exception, string $message): void
    {
        config(['consistentapi.filters.model_paths' => [$this->models($directory)]]);

        $this->expectException($exception);
        $this->expectExceptionMessageToContain($message);

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_finds_models_whose_docblock_mentions_class(): void
    {
        config(['consistentapi.filters.model_paths' => [$this->models('DocumentedModel')]]);

        $this->expectException(InvalidAccessException::class);
        $this->expectExceptionMessageToContain('access "unknown"');

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_repeated_filter_column_is_rejected_and_keeps_its_first_declaration(): void
    {
        config(['consistentapi.accesses' => ['admin' => []], 'consistentapi.filters.model_paths' => [$this->models('DuplicateFilterColumn')]]);

        $report = new DuplicateFilterColumn;
        // Where validation does not run, the first declaration wins
        $this->assertSame(['title', 'email'], $report->getAllowedFilters(['admin']));
        $this->assertNull($report->getAllowedFilterKeys('title', ['admin']));
        $this->assertSame(['title'], $report->getAllowedSorts(['admin']));

        $this->expectException(InvalidAccessException::class);
        $this->expectExceptionMessageToContain('column "title" in more than one #[Filterable]');

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_discovers_only_concrete_eloquent_models_in_php_files(): void
    {
        // Mixed also holds an interface, a plain class, functions, a .dist file and an abstract model
        config(['consistentapi.filters.model_paths' => [$this->models('Mixed')]]);
        $discover = new ReflectionMethod(FilterableSortableValidator::class, 'discoverModelClasses');

        $classes = $discover->invoke(new FilterableSortableValidator);
        sort($classes);

        $this->assertSame([Deep::class, Several::class, Valid::class], $classes);

        (new FilterableSortableValidator)->validateAll();
    }

    public function test_resolves_wildcards_and_skips_missing_paths(): void
    {
        config(['consistentapi.filters.model_paths' => [
            $this->models('Mix*'),
            $this->models('Mixed'),
            $this->models('Missing'),
            $this->models('Nothing*'),
        ]]);
        $resolve = new ReflectionMethod(FilterableSortableValidator::class, 'resolveModelPaths');

        $this->assertSame([$this->models('Mixed')], array_values($resolve->invoke(new FilterableSortableValidator)));
    }

    public function test_without_model_paths_there_is_nothing_to_validate(): void
    {
        config(['consistentapi.filters.model_paths' => [$this->models('Missing')]]);
        $discover = new ReflectionMethod(FilterableSortableValidator::class, 'discoverModelClasses');

        $this->assertSame([], $discover->invoke(new FilterableSortableValidator));
    }

    public function test_reads_the_class_names_of_a_file(): void
    {
        $read = new ReflectionMethod(FilterableSortableValidator::class, 'classesFromFile');
        $validator = new FilterableSortableValidator;

        $this->assertSame([Valid::class], $read->invoke($validator, $this->models('Mixed').'/Valid.php'));
        $this->assertSame([Several::class], $read->invoke($validator, $this->models('Mixed').'/Several.php'));
        $this->assertSame([], $read->invoke($validator, $this->models('Mixed').'/Contract.php'));
        $this->assertSame([], $read->invoke($validator, $this->models('Mixed').'/functions.php'));
    }
}
