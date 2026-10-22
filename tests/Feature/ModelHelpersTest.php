<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Factories\AuthorFactory;
use Database\Factories\GadgetFactory;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesLibrary;
use Tests\Fixtures\Author;
use Tests\Fixtures\Book;
use Tests\Fixtures\Gadget;
use Tests\Fixtures\Novelist;
use Tests\Fixtures\Profile;
use Tests\Fixtures\Writer;
use Tests\TestCase;

/**
 * ConsistentModel helpers and the Credibility trait.
 */
class ModelHelpersTest extends TestCase
{
    use CreatesLibrary;

    public function test_factory_is_looked_up_by_the_model_class_name(): void
    {
        $factory = Author::factory();

        $this->assertInstanceOf(AuthorFactory::class, $factory);
        $this->assertSame('Factory Ann', $factory->make()->name);

        $newFactory = new ReflectionMethod(Profile::class, 'newFactory');
        $this->assertNull($newFactory->invoke(null));
    }

    public function test_factory_named_by_the_model_takes_precedence(): void
    {
        $this->assertInstanceOf(AuthorFactory::class, Writer::factory());
        $this->assertInstanceOf(AuthorFactory::class, Novelist::factory());

        $newFactory = new ReflectionMethod(Writer::class, 'newFactory');
        $this->assertInstanceOf(AuthorFactory::class, $newFactory->invoke(null));
    }

    public function test_factory_without_a_model_creates_the_model_it_was_found_for(): void
    {
        $factory = Gadget::factory();

        $this->assertInstanceOf(GadgetFactory::class, $factory);
        $this->assertSame(Gadget::class, $factory->modelName());
        $this->assertInstanceOf(Gadget::class, $factory->make());
    }

    public function test_lists_the_table_columns(): void
    {
        $this->createLibraryTables();

        $this->assertSame(['id', 'name', 'created_at', 'updated_at'], (new Author)->getAllColumns());
        $this->assertSame(Schema::getColumnListing('books'), (new Book)->getAllColumns());
    }

    public function test_lists_the_columns_of_the_model_connection(): void
    {
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('other')->create('authors', function ($table) {
            $table->id();
            $table->string('pen_name');
        });

        $this->assertSame(['id', 'pen_name'], (new Author)->setConnection('other')->getAllColumns());
    }

    public function test_credible_when_the_foreign_key_matches_the_id(): void
    {
        $author = (new Author)->forceFill(['id' => 7]);

        $this->assertTrue((new Book)->forceFill(['author_id' => 7])->isCredible($author, 'author_id'));
        $this->assertTrue((new Book)->forceFill(['author_id' => '7'])->isCredible($author, 'author_id'));
        $this->assertFalse((new Book)->forceFill(['author_id' => 8])->isCredible($author, 'author_id'));
        $this->assertFalse((new Book)->forceFill(['author_id' => null])->isCredible($author, 'author_id'));
        $this->assertFalse((new Book)->forceFill(['author_id' => ''])->isCredible($author, 'author_id'));
        $this->assertFalse((new Book)->forceFill(['author_id' => 7])->isCredible(new Author, 'author_id'));
    }

    public function test_credible_compares_with_the_primary_key_or_the_given_owner_key(): void
    {
        $gadget = (new Gadget)->forceFill(['code' => 'g-1', 'id' => 5]);

        // The primary key of Gadget is code, not id
        $this->assertTrue((new Book)->forceFill(['author_id' => 'g-1'])->isCredible($gadget, 'author_id'));
        $this->assertFalse((new Book)->forceFill(['author_id' => 5])->isCredible($gadget, 'author_id'));
        $this->assertTrue((new Book)->forceFill(['author_id' => 5])->isCredible($gadget, 'author_id', 'id'));

        $this->expectException(HttpException::class);

        (new Book)->forceFill(['author_id' => 'g-1'])->checkModelCredibility($gadget, 'author_id', ownerKey: 'id');
    }

    public function test_check_aborts_with_the_given_status_and_message(): void
    {
        $author = (new Author)->forceFill(['id' => 7]);

        (new Book)->forceFill(['author_id' => 7])->checkModelCredibility($author, 'author_id');

        try {
            (new Book)->forceFill(['author_id' => 8])->checkModelCredibility($author, 'author_id');
            $this->fail('A foreign book passed the check');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('The model is not credible.', $e->getMessage());
        }

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageToContain('Not your book');

        (new Book)->forceFill(['author_id' => 8])->checkModelCredibility($author, 'author_id', 403, 'Not your book');
    }
}
