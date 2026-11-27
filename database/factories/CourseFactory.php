<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'code' => fake()->unique()->bothify('CRS-####'),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'duration_months' => fake()->numberBetween(3, 36),
            'fee_amount' => fake()->randomFloat(2, 0, 10000),
            'currency' => 'USD',
            'prerequisites' => [],
            'is_active' => true,
        ];
    }
}
