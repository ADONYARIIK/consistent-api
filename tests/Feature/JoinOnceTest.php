<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Article;
use Tests\Fixtures\ArticleComment;
use Tests\TestCase;

class JoinOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('articles', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('article_comments', function ($table) {
            $table->id();
            $table->foreignId('article_id');
            $table->string('body');
            $table->timestamps();
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function joinTypes(): array
    {
        return [
            'joinOnce' => ['inner'],
            'leftJoinOnce' => ['left'],
            'rightJoinOnce' => ['right'],
        ];
    }

    #[DataProvider('joinTypes')]
    public function test_adds_one_join_of_its_type(string $type): void
    {
        $query = ArticleComment::query()->joinArticles($type);

        $this->assertCount(1, $query->getQuery()->joins);
        $this->assertSame($type, $query->getQuery()->joins[0]->type);
        $this->assertSame(
            'select * from "article_comments" '.$type.' join "articles" on "articles"."id" = "article_comments"."article_id"',
            $query->toSql(),
        );
    }

    #[DataProvider('joinTypes')]
    public function test_repeated_call_does_not_join_again(string $type): void
    {
        $query = ArticleComment::query()->joinArticles($type)->joinArticles($type);

        $this->assertCount(1, $query->getQuery()->joins);
    }

    public function test_first_join_of_a_table_wins_whatever_the_type(): void
    {
        $query = ArticleComment::query()->joinArticles('left')->joinArticles('inner')->joinArticles('right');

        $this->assertCount(1, $query->getQuery()->joins);
        $this->assertSame('left', $query->getQuery()->joins[0]->type);
    }

    public function test_relation_sort_works_next_to_a_join_made_by_a_scope(): void
    {
        $this->seedComments();

        $sorted = ArticleComment::query()->sort(['article' => 'asc']);
        $this->assertSame(['on alpha', 'on beta', 'on gamma'], $sorted->get()->pluck('body')->all());

        // The sort orders by a subquery and adds no join of its own
        $joined = ArticleComment::query()->joinArticles('inner')->sort(['article' => 'desc']);
        $this->assertCount(1, $joined->getQuery()->joins);

        // Keyed by id: only the comment columns are selected, so the article id does not replace the comment id
        $this->assertSame([2 => 'on gamma', 1 => 'on beta', 3 => 'on alpha'], $joined->get()->pluck('body', 'id')->all());
    }

    private function seedComments(): void
    {
        $gamma = Article::create(['title' => 'Gamma']);
        $alpha = Article::create(['title' => 'Alpha']);
        $beta = Article::create(['title' => 'Beta']);

        ArticleComment::create(['article_id' => $beta->id, 'body' => 'on beta']);
        ArticleComment::create(['article_id' => $gamma->id, 'body' => 'on gamma']);
        ArticleComment::create(['article_id' => $alpha->id, 'body' => 'on alpha']);
    }
}
