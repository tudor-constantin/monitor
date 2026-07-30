<?php

namespace Database\Factories;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' website',
            'url' => 'https://'.fake()->unique()->domainName().'/',
            'method' => 'GET',
            'expected_status_code' => 200,
            'interval_seconds' => 300,
            'timeout_seconds' => 10,
            'status' => MonitorStatus::Pending,
            'is_active' => true,
            'consecutive_failures' => 0,
            'last_checked_at' => null,
            'next_check_at' => now(),
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorStatus::Paused,
            'is_active' => false,
            'next_check_at' => null,
        ]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'next_check_at' => now()->subSecond(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'next_check_at' => now()->addMinute(),
        ]);
    }
}
