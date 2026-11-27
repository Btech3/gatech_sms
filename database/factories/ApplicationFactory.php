<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Intake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        $intake = Intake::factory()->create();

        return [
            'application_number' => 'APP-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'applicant_id' => Applicant::factory(),
            'intake_id' => $intake->id,
            'campus_id' => $intake->campus_id,
            'course_id' => $intake->course_id,
            'status' => Application::STATUS_DRAFT,
        ];
    }
}
