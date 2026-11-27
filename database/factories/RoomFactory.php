<?php

namespace Database\Factories;

use App\Models\Campus;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campus_id' => Campus::factory(),
            'name' => fake()->unique()->bothify('Room ###'),
            'capacity' => fake()->numberBetween(10, 60),
            'type' => fake()->randomElement(['classroom', 'lab', 'virtual']),
            'status' => 'active',
        ];
    }
}
