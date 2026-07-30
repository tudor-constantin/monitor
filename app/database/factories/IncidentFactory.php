<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'started_at' => now(),
            'resolved_at' => null,
            'initial_check_id' => null,
            'recovery_check_id' => null,
            'cause' => fake()->sentence(),
            'duration_seconds' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(5),
            'resolved_at' => now(),
            'duration_seconds' => 300,
        ]);
    }
}
