<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\ScheduledSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledSession>
 */
class ScheduledSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->startOfHour();

        return [
            'room_id' => Room::factory(),
            'title' => fake()->sentence(4),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => 'scheduled',
        ];
    }
}
