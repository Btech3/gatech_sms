<?php

namespace Database\Factories;

use App\Models\AcademyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademyProfile>
 */
class AcademyProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'singleton_id' => 1,
            'name' => fake()->company(),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
        ];
    }
}
