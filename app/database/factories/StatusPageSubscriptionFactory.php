<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageSubscription>
 */
class StatusPageSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory()->published(),
            'email' => fake()->unique()->safeEmail(),
            'subscribed_to_all' => true,
            'pending_subscribed_to_all' => true,
            'pending_monitor_ids' => null,
            'confirmation_token_hash' => null,
            'confirmation_requested_at' => null,
            'verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => null,
            'confirmation_token_hash' => hash('sha256', 'pending-token'),
            'confirmation_requested_at' => now(),
        ]);
    }
}
