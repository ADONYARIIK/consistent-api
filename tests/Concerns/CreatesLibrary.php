<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Author;
use Tests\Fixtures\Book;
use Tests\Fixtures\Profile;
use Tests\Fixtures\Tag;

/**
 * Tables and rows of the author / book / tag fixtures.
 */
trait CreatesLibrary
{
    protected function createLibraryTables(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id');
            $table->string('nickname');
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->nullable();
            $table->integer('price')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->foreignId('editor_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('book_tag', function (Blueprint $table) {
            $table->foreignId('book_id');
            $table->foreignId('tag_id');
        });
    }

    /**
     * Ann wrote The Ring and Dune, Bob wrote Ringworld and Emma, Cid wrote nothing and has no profile.
     * Cid edited The Ring and Emma, Ann edited Ringworld, Bob edited Dune.
     */
    protected function seedLibrary(): void
    {
        $ann = Author::create(['name' => 'Ann']);
        $bob = Author::create(['name' => 'Bob']);
        $cid = Author::create(['name' => 'Cid']);

        Profile::create(['author_id' => $ann->id, 'nickname' => 'zeta']);
        Profile::create(['author_id' => $bob->id, 'nickname' => 'alpha']);

        $sf = Tag::create(['name' => 'sf']);
        $classic = Tag::create(['name' => 'classic']);
        $novel = Tag::create(['name' => 'novel']);

        Book::create(['title' => 'The Ring', 'status' => 'draft', 'price' => 10, 'published_at' => null, 'author_id' => $ann->id, 'editor_id' => $cid->id])
            ->tags()->attach([$classic->id, $novel->id]);
        Book::create(['title' => 'Ringworld', 'status' => 'published', 'price' => 20, 'published_at' => '2021-06-15 10:00:00', 'author_id' => $bob->id, 'editor_id' => $ann->id])
            ->tags()->attach([$sf->id]);
        Book::create(['title' => 'Dune', 'status' => 'archived', 'price' => 30, 'published_at' => '2022-01-01 00:00:00', 'author_id' => $ann->id, 'editor_id' => $bob->id])
            ->tags()->attach([$sf->id, $classic->id]);
        Book::create(['title' => 'Emma', 'status' => null, 'price' => 40, 'published_at' => '2020-03-01 12:00:00', 'author_id' => $bob->id, 'editor_id' => $cid->id])
            ->tags()->attach([$classic->id]);
    }
}
