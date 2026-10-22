<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Lets guests see and change books, but not delete them; viewAny is left out on purpose.
 */
class BookPolicy
{
    public function view(?object $user, Book $book): bool
    {
        return true;
    }

    public function create(?object $user): bool
    {
        return true;
    }

    public function update(?object $user, Book $book): bool
    {
        return $book->title !== 'Locked';
    }

    public function delete(?object $user, Book $book): bool
    {
        return false;
    }
}
