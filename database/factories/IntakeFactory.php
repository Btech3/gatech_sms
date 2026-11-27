<?php

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\Intake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intake>
 */
class IntakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_session_id' => AcademicSession::factory(),
            'campus_id' => Campus::factory(),
            'name' => fake()->unique()->numerify('Intake ####'),
            'starts_on' => now()->addMonths(3)->startOfMonth(),
            'applications_open_at' => now()->subDay(),
            'applications_close_at' => now()->addMonth(),
            'capacity' => 30,
            'is_active' => true,
        ];
    }
}
