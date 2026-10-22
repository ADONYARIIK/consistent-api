<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Names no model, as make:factory generates it: ConsistentModel::newFactory() has to tell it.
 */
class GadgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Gadget'];
    }
}
