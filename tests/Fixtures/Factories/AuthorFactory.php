<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Author;

/**
 * Lives where ConsistentModel::newFactory() looks: Database\Factories\{Model}Factory.
 */
class AuthorFactory extends Factory
{
    protected $model = Author::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Factory Ann'];
    }
}
