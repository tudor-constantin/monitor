<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
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
            'name' => fake()->unique()->company().' status',
            'slug' => fake()->unique()->slug(3).'-'.Str::lower(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'is_public' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'is_public' => true,
        ]);
    }
}
