<?php

namespace Database\Factories;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Campus',
            'address' => fake()->address(),
            'phone' => fake()->numerify('+1##########'),
            'email' => fake()->companyEmail(),
            'is_active' => true,
        ];
    }
}
