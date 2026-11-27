<?php

namespace Database\Factories;

use App\Models\AcademicSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicSession>
 */
class AcademicSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = now()->addYear()->startOfYear();

        return [
            'name' => fake()->unique()->numerify('Academic Session ####'),
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->copy()->endOfYear(),
            'is_current' => false,
            'is_closed' => false,
        ];
    }
}
