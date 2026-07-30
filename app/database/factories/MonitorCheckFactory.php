<?php

namespace Database\Factories;

use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorCheck>
 */
class MonitorCheckFactory extends Factory
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
            'status' => MonitorCheckStatus::Successful,
            'status_code' => 200,
            'response_time_ms' => fake()->numberBetween(40, 500),
            'response_size_bytes' => fake()->numberBetween(500, 50000),
            'resolved_ip' => '93.184.216.34',
            'error_type' => null,
            'error_message' => null,
            'checked_at' => now(),
        ];
    }
}
